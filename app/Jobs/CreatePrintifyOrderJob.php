<?php

namespace App\Jobs;

use App\Models\PrintifyOrder;
use App\Services\Printify\PrintifyOrderCreateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Performs the Printify POST for one queued/processing printify_orders row,
 * off the HTTP request path. Dedup is row-based (CAS below) plus the DB
 * unique on (shop, ebay_order_number) — deliberately NOT ShouldBeUnique,
 * since a stale unique lock could silently swallow the orphan-row
 * re-dispatch that PrintifyOrderCreateService::enqueue() relies on.
 */
class CreatePrintifyOrderJob implements ShouldQueue
{
    use Queueable;

    /** Re-delivery after a worker kill is tolerated: a `processing` row older than this is up for grabs again. */
    private const STALE_PROCESSING_SECONDS = 120;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public int $timeout = 60;

    /** @param array<int, array{line_item_id:int, variant_id:int}> $manualMappings */
    public function __construct(public readonly int $printifyOrderId, public readonly array $manualMappings = [])
    {
        $this->onQueue('printify-orders');
    }

    public function handle(PrintifyOrderCreateService $service): void
    {
        if (! $this->claim()) {
            return;
        }

        $row = PrintifyOrder::find($this->printifyOrderId);
        if ($row === null) {
            return;
        }

        $result = $service->submit($row, $this->manualMappings);
        if ($result->intent_state !== 'queued') {
            return;
        }

        if ($this->attempts() >= $this->tries) {
            $this->fail('Printify order create retry limit reached');
            return;
        }

        $this->release($this->backoff[$this->attempts() - 1]);
    }

    /**
     * CAS queued/stale-processing → processing. Returns false when another
     * worker already owns the row, it already reached a terminal state
     * (created/failed/synced/conflict), or the row was deleted.
     */
    private function claim(): bool
    {
        $affected = DB::table('printify_orders')
            ->where('id', $this->printifyOrderId)
            ->where(function ($query) {
                $query->where('intent_state', 'queued')
                    ->orWhere(function ($stale) {
                        $stale->where('intent_state', 'processing')
                            ->where('updated_at', '<', now()->subSeconds(self::STALE_PROCESSING_SECONDS));
                    });
            })
            ->update(['intent_state' => 'processing', 'updated_at' => now()]);

        return $affected === 1;
    }

    public function failed(?Throwable $exception): void
    {
        PrintifyOrder::whereKey($this->printifyOrderId)
            ->whereIn('intent_state', ['queued', 'processing'])
            ->update([
                'intent_state' => 'failed',
                'last_error' => mb_strimwidth('Printify order create job failed: '.($exception?->getMessage() ?? 'unknown'), 0, 500, '…'),
            ]);

        Log::error('printify_order_create.job_failed', [
            'printify_order_id' => $this->printifyOrderId,
            'exception_class' => $exception !== null ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
