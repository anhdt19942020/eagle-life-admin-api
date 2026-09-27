<?php

namespace App\Services\Printify;

use App\Jobs\CreatePrintifyOrderJob;
use App\Models\Order;
use App\Models\PrintifyOrder;
use App\Models\PrintifyShop;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Two-step create: enqueue() is DB-only and fast (safe inside an HTTP
 * request); submit() does the Printify POST and is meant to run inside
 * CreatePrintifyOrderJob, off the request path. create() composes both for
 * the single-order endpoint, which keeps its existing synchronous contract.
 */
class PrintifyOrderCreateService
{
    /** A row this stale is treated as orphaned (lost job, cleared queue, dead worker) and re-dispatched. */
    private const ORPHAN_AFTER_SECONDS = 150;

    public function __construct(
        private readonly PrintifyOrderPreviewService $preview,
        private readonly PrintifyClientFactory $factory,
        private readonly PrintifySyncService $sync,
    ) {}

    /**
     * @param  array<int, array{line_item_id:int, variant_id:int}>  $manualMappings
     * @return array{created: bool, printify_order: PrintifyOrder, remote: array, preview: array, state: string}
     */
    public function create(Order $order, PrintifyShop $shop, array $manualMappings = []): array
    {
        $enqueued = $this->enqueue($order, $shop, $manualMappings);

        if (! in_array($enqueued['state'], ['queued', 'requeued'], true)) {
            return $enqueued + ['created' => false, 'remote' => []];
        }

        $claimed = PrintifyOrder::whereKey($enqueued['printify_order']->id)
            ->where('intent_state', 'queued')
            ->update(['intent_state' => 'processing']);
        if ($claimed !== 1) {
            $current = $enqueued['printify_order']->fresh();

            return array_replace($enqueued, ['created' => false, 'printify_order' => $current, 'remote' => [], 'state' => $current?->intent_state ?? 'already_queued']);
        }

        $printifyOrder = $this->submit($enqueued['printify_order']->fresh(), $manualMappings);
        if ($printifyOrder->intent_state === 'queued') {
            CreatePrintifyOrderJob::dispatch($printifyOrder->id, $manualMappings);
        }

        return [
            'created' => $printifyOrder->intent_state === 'created',
            'printify_order' => $printifyOrder,
            'remote' => $printifyOrder->intent_state === 'created' ? ['id' => $printifyOrder->printify_order_id, 'status' => $printifyOrder->status] : [],
            'preview' => $enqueued['preview'],
            'state' => $printifyOrder->intent_state,
        ];
    }

    /**
     * DB-only: preview + write/attach a queued intent row. Never calls Printify.
     *
     * @param  array<int, array{line_item_id:int, variant_id:int}>  $manualMappings
     * @return array{state: string, printify_order: ?PrintifyOrder, preview: array}
     */
    public function enqueue(Order $order, PrintifyShop $shop, array $manualMappings = []): array
    {
        $conflict = PrintifyOrder::where('order_id', $order->id)->where('intent_state', 'conflict')->first();
        if ($conflict !== null) {
            return ['state' => 'conflict', 'printify_order' => $conflict, 'preview' => []];
        }

        $preview = $this->preview->preview($order, $shop, $manualMappings);
        if (! $preview['ready'] || $preview['payload'] === null) {
            return ['state' => 'not_ready', 'printify_order' => null, 'preview' => $preview];
        }

        $externalId = (string) ($order->ebay_order_number ?: $order->ebay_order_id);

        try {
            $row = PrintifyOrder::create([
                'order_id' => $order->id,
                'printify_shop_id' => $shop->id,
                'printify_order_id' => null,
                'ebay_order_number' => $externalId,
                'intent_state' => 'queued',
                'attempt_key' => 'create:'.$shop->id.':'.$externalId.':'.Str::uuid(),
            ]);

            return ['state' => 'queued', 'printify_order' => $row, 'preview' => $preview];
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->resolveExistingRow($order, $shop, $externalId, $preview);
        }
    }

    /**
     * @return array{state: string, printify_order: ?PrintifyOrder, preview: array}
     */
    private function resolveExistingRow(Order $order, PrintifyShop $shop, string $externalId, array $preview): array
    {
        // order_id is unique too: the same order enqueued to a different shop
        // hits that constraint, not (shop, ebay_order_number) — check it first.
        $existing = PrintifyOrder::where('order_id', $order->id)->first()
            ?? PrintifyOrder::where('printify_shop_id', $shop->id)->where('ebay_order_number', $externalId)->first();

        if ($existing === null) {
            // Constraint fired but the row is already gone (rare race) — nothing to attach to.
            return ['state' => 'already_exists', 'printify_order' => null, 'preview' => $preview];
        }

        return match ($existing->intent_state) {
            'created', 'synced' => ['state' => 'already_exists', 'printify_order' => $existing, 'preview' => $preview],
            'conflict' => ['state' => 'conflict', 'printify_order' => $existing, 'preview' => $preview],
            'queued', 'processing' => $this->isOrphan($existing)
                ? $this->requeueOrphan($existing, $shop, $externalId, $preview)
                : ['state' => 'already_queued', 'printify_order' => $existing, 'preview' => $preview],
            'failed' => $this->requeueFailed($existing, $shop, $externalId, $preview),
            default => ['state' => 'already_queued', 'printify_order' => $existing, 'preview' => $preview],
        };
    }

    private function isOrphan(PrintifyOrder $row): bool
    {
        return $row->updated_at !== null && $row->updated_at->lt(now()->subSeconds(self::ORPHAN_AFTER_SECONDS));
    }

    /** @return array{state: string, printify_order: PrintifyOrder, preview: array} */
    private function requeueOrphan(PrintifyOrder $row, PrintifyShop $shop, string $externalId, array $preview): array
    {
        $affected = PrintifyOrder::whereKey($row->id)
            ->whereIn('intent_state', ['queued', 'processing'])
            ->where('updated_at', '<', now()->subSeconds(self::ORPHAN_AFTER_SECONDS))
            ->update(['intent_state' => 'queued', 'updated_at' => now()]);

        if ($affected !== 1) {
            // Someone else already touched it (its own job just started, or another
            // request beat us to the requeue) — report the current state, don't dispatch twice.
            return ['state' => 'already_queued', 'printify_order' => $row->fresh(), 'preview' => $preview];
        }

        $row->refresh();

        return ['state' => 'requeued', 'printify_order' => $row, 'preview' => $preview];
    }

    /** @return array{state: string, printify_order: PrintifyOrder, preview: array} */
    private function requeueFailed(PrintifyOrder $row, PrintifyShop $shop, string $externalId, array $preview): array
    {
        $affected = PrintifyOrder::whereKey($row->id)
            ->where('intent_state', 'failed')
            ->update([
                'intent_state' => 'queued',
                'printify_shop_id' => $shop->id,
                'ebay_order_number' => $externalId,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            return ['state' => 'already_queued', 'printify_order' => $row->fresh(), 'preview' => $preview];
        }

        $row->refresh();

        return ['state' => 'requeued', 'printify_order' => $row, 'preview' => $preview];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // MySQL 1062 / SQLite "UNIQUE constraint failed" — matches how the rest
        // of this codebase has no existing helper for this; keep it local.
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Runs the Printify POST for a queued/processing row. Meant to be called
     * only from CreatePrintifyOrderJob (or inline from create() for the
     * single-order endpoint) — never call this directly from a controller.
     *
     * @param  array<int, array{line_item_id:int, variant_id:int}>  $manualMappings
     */
    public function submit(PrintifyOrder $row, array $manualMappings = []): PrintifyOrder
    {
        $order = $row->order ?? Order::find($row->order_id);
        $shop = $row->shop ?? PrintifyShop::with('account')->find($row->printify_shop_id);

        if ($order === null || $shop === null || $shop->account === null) {
            return $this->markFailed($row, 'Printify order/shop/account missing at submit time.');
        }

        // Re-run preview: the catalog may have changed since enqueue.
        $preview = $this->preview->preview($order, $shop, $manualMappings);
        if (! $preview['ready'] || $preview['payload'] === null) {
            return $this->markFailed($row, 'Printify order payload is not ready: '.implode(' ', $preview['errors']));
        }

        try {
            $remote = $this->factory->for($shop->account)->post(
                "/shops/{$shop->printify_shop_id}/orders.json",
                $preview['payload'],
                retryTimes: 0,
            );
        } catch (ConnectionException $exception) {
            // Ambiguous: the request may have reached Printify before the
            // timeout. Leave queued; the next attempt's 409 (if any) reconciles.
            return $this->releaseForRetry($row);
        } catch (RuntimeException $exception) {
            return $this->handlePrintifyError($row, $shop, $order, $exception);
        }

        $remoteId = (string) ($remote['id'] ?? '');
        if ($remoteId === '') {
            return $this->markFailed($row, 'Printify create order response missing id.');
        }

        return DB::transaction(function () use ($row, $order, $remote, $remoteId) {
            $updated = $this->updateClaimedRow($row, [
                'printify_order_id' => $remoteId,
                'status' => $remote['status'] ?? 'pending',
                'intent_state' => 'created',
                'last_error' => null,
                'synced_at' => now(),
            ]);
            if (! $updated) return $row->fresh() ?? $row;

            $order->forceFill([
                'printify_order_id' => $remoteId,
                'printify_created_at' => $order->printify_created_at ?? now(),
            ])->save();

            return $row->fresh();
        });
    }

    private function handlePrintifyError(PrintifyOrder $row, PrintifyShop $shop, Order $order, RuntimeException $exception): PrintifyOrder
    {
        if ($this->isLockBusy($exception)) {
            return $this->releaseForRetry($row);
        }

        if ($this->isAlreadyExistsConflict($exception)) {
            return $this->reconcileExistingRemote($row, $shop, $order, $exception);
        }

        $status = $this->responseStatus($exception);
        if ($status === 429 || ($status !== null && $status >= 500)) {
            return $this->releaseForRetry($row);
        }

        return $this->markFailed($row, $exception->getMessage());
    }

    private function releaseForRetry(PrintifyOrder $row): PrintifyOrder
    {
        $this->updateClaimedRow($row, ['intent_state' => 'queued']);

        return $row->fresh() ?? $row;
    }

    private function markFailed(PrintifyOrder $row, string $reason): PrintifyOrder
    {
        $this->updateClaimedRow($row, [
            'intent_state' => 'failed',
            'last_error' => mb_strimwidth($reason, 0, 500, '…'),
        ]);

        return $row->fresh() ?? $row;
    }

    private function updateClaimedRow(PrintifyOrder $row, array $attributes): bool
    {
        return PrintifyOrder::whereKey($row->id)
            ->where('intent_state', 'processing')
            ->where('printify_shop_id', $row->printify_shop_id)
            ->update($attributes) === 1;
    }

    /**
     * Recover the local PrintifyOrder record for an order Printify already
     * holds (a 409 duplicate-external_id). Bounded to 1 page (Printify returns
     * newest-first, verified 2026-09-26) and a short timeout so this stays well
     * under the job's timeout — an unbounded sync here can outlive the job and
     * leave the account/shop cache lock held for its full TTL.
     */
    private function reconcileExistingRemote(PrintifyOrder $row, PrintifyShop $shop, Order $order, RuntimeException $exception): PrintifyOrder
    {
        try {
            $this->sync->syncOrders($shop->account, (int) $shop->printify_shop_id, limitPages: 1, timeout: 10);
        } catch (RuntimeException $syncException) {
            // Account/shop sync lock busy — treat like any other transient
            // contention and let the job retry later.
            return $this->releaseForRetry($row);
        }

        $reconciled = $row->fresh();
        if ($reconciled !== null && $reconciled->intent_state === 'synced') {
            return $reconciled;
        }

        // Reconcile didn't find it on page 1 (unexpected) — leave queued;
        // the 15-min pull sync (unbounded) will heal it.
        return $this->releaseForRetry($row);
    }

    private function isAlreadyExistsConflict(RuntimeException $exception): bool
    {
        return $this->responseStatus($exception) === 409
            && str_contains($exception->getPrevious()?->response->body() ?? '', 'already exists');
    }

    private function isLockBusy(RuntimeException $exception): bool
    {
        return $exception->getPrevious() === null
            && (str_contains($exception->getMessage(), 'sync is already running for this shop')
                || str_contains($exception->getMessage(), 'Printify account sync is already running'));
    }

    private function responseStatus(RuntimeException $exception): ?int
    {
        $previous = $exception->getPrevious();

        return $previous instanceof RequestException ? $previous->response->status() : null;
    }
}
