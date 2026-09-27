<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreatePrintifyOrderJob;
use App\Models\Order;
use App\Models\PrintifyShop;
use App\Services\Printify\PrintifyOrderCreateService;
use App\Services\Printify\PrintifyOrderPreviewService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PrintifyOrderController extends Controller
{
    use ApiResponse;

    public function preview(Request $request, Order $order, PrintifyOrderPreviewService $preview)
    {
        $order = Order::query()->visibleTo($request->user())->findOrFail($order->id);

        $resolved = $this->validatedShopAndMappings($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$shop, $mappings] = $resolved;

        try {
            $result = $preview->preview($order, $shop, $mappings);
        } catch (RuntimeException $exception) {
            return $this->mapPreflightError($exception);
        }

        return $this->success($result, $result['ready']
            ? 'Printify order payload ready (dry-run)'
            : 'Printify order payload is not ready');
    }

    public function create(Request $request, Order $order, PrintifyOrderCreateService $create)
    {
        $order = Order::query()->visibleTo($request->user())->findOrFail($order->id);

        $resolved = $this->validatedShopAndMappings($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$shop, $mappings] = $resolved;

        try {
            $result = $create->create($order, $shop, $mappings);
        } catch (RuntimeException $exception) {
            return $this->mapPreflightError($exception);
        }

        if ($result['state'] === 'not_ready') {
            return $this->error(
                'Printify order payload is not ready: '.implode(' ', $result['preview']['errors'] ?? []),
                422,
                ['code' => 'printify_shop_not_ready']
            );
        }

        return $this->success([
            'created' => $result['created'],
            'state' => $result['state'],
            'printify_order' => $result['printify_order'],
            'remote' => $result['remote'],
            'preview' => $result['preview'],
        ], $this->stateMessage($result['state']));
    }

    /**
     * Batch: preview + enqueue each order synchronously (DB-only, fast), then
     * dispatch one job per queued/requeued order. Never fails the whole batch
     * for one bad order — per-order `state` carries the outcome.
     */
    public function createBatch(Request $request, PrintifyOrderCreateService $create)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');

        $rules = [
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['integer', 'distinct'],
        ];
        $rules['shop_id'] = $isAdmin ? ['required', 'integer', 'exists:printify_shops,id'] : ['sometimes', 'nullable', 'integer', 'exists:printify_shops,id'];
        $validated = $request->validate($rules);

        // Reuse the single-order shop resolution (admin requires shop_id;
        // seller uses assigned/default shop) — line_mappings never apply to
        // a bulk push, so pass none.
        $resolved = $this->validatedShopAndMappings($request->merge(['line_mappings' => []]));
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$shop, ] = $resolved;

        $orders = Order::query()->visibleTo($user)->whereIn('id', $validated['order_ids'])->get()->keyBy('id');

        $results = collect($validated['order_ids'])->map(function (int $orderId) use ($orders, $shop, $create) {
            $order = $orders->get($orderId);
            if ($order === null) {
                return ['order_id' => $orderId, 'state' => 'not_found', 'printify_order_id' => null, 'errors' => []];
            }

            $enqueued = $create->enqueue($order, $shop, []);
            if (in_array($enqueued['state'], ['queued', 'requeued'], true)) {
                CreatePrintifyOrderJob::dispatch($enqueued['printify_order']->id);
            }

            return [
                'order_id' => $orderId,
                'state' => $enqueued['state'],
                'printify_order_id' => $enqueued['printify_order']?->id,
                'errors' => $enqueued['preview']['errors'] ?? [],
            ];
        });

        return $this->success(['results' => $results->values()], 'Đã tiếp nhận yêu cầu tạo đơn Printify');
    }

    private function stateMessage(string $state): string
    {
        return match ($state) {
            'created' => 'Printify order created',
            'queued', 'requeued', 'already_queued', 'processing' => 'Đơn đang chờ tạo trên Printify',
            'conflict' => 'Đơn xung đột với shop khác, cần xử lý thủ công',
            'not_ready' => 'Printify order payload is not ready',
            default => 'Printify order already exists for this eBay number',
        };
    }

    /**
     * @return array{0: PrintifyShop, 1: array<int, array{line_item_id: int, variant_id: int}>}|JsonResponse
     */
    private function validatedShopAndMappings(Request $request): array|JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('admin');

        $rules = [
            'line_mappings' => ['sometimes', 'array'],
            'line_mappings.*.line_item_id' => ['required', 'integer', 'exists:order_line_items,id'],
            'line_mappings.*.variant_id' => ['required', 'integer'],
        ];

        if ($isAdmin) {
            $rules['shop_id'] = ['required', 'integer', 'exists:printify_shops,id'];
        } else {
            $rules['shop_id'] = ['sometimes', 'nullable', 'integer', 'exists:printify_shops,id'];
        }

        $validated = $request->validate($rules);

        if ($isAdmin) {
            $shop = PrintifyShop::with('account')->findOrFail($validated['shop_id']);
        } else {
            $user->loadMissing('printifyShops.account');
            $assignedShops = $user->printifyShops;

            if ($assignedShops->isEmpty()) {
                return $this->error(
                    'Vui lòng gán một shop Printify cho người dùng này.',
                    422,
                    ['code' => 'printify_shop_assignment_required']
                );
            }

            $requestedShopId = $validated['shop_id'] ?? null;

            if ($requestedShopId !== null) {
                $shop = $assignedShops->firstWhere('id', (int) $requestedShopId);

                if ($shop === null) {
                    return $this->error(
                        'Shop Printify đã chọn chưa được gán cho bạn.',
                        422,
                        ['code' => 'printify_shop_not_assigned']
                    );
                }
            } else {
                $shop = $assignedShops->firstWhere('pivot.is_default', true) ?? $assignedShops->first();
            }
        }

        if ($shop->account === null || ! $shop->account->is_active) {
            return $this->error(
                'Printify account của shop này hiện không hoạt động.',
                422,
                ['code' => 'printify_account_inactive']
            );
        }

        return [$shop, $validated['line_mappings'] ?? []];
    }

    private function mapPreflightError(RuntimeException $exception): JsonResponse
    {
        $message = $exception->getMessage();
        $code = null;

        if (str_contains($message, 'inactive')) {
            $code = 'printify_account_inactive';
        } elseif (str_contains($message, 'not ready') || str_contains($message, 'closed') || str_contains($message, 'default_sku')) {
            $code = 'printify_shop_not_ready';
        }

        return $this->error($message, 422, $code ? ['code' => $code] : null);
    }
}
