<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return $this->success([
            'created' => $result['created'],
            'printify_order' => $result['printify_order'],
            'remote' => $result['remote'],
            'preview' => $result['preview'],
        ], $result['created']
            ? 'Printify order created'
            : 'Printify order already exists for this eBay number');
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
            $user->loadMissing('printifyShop.account');
            $shop = $user->printifyShop;

            if ($shop === null) {
                return $this->error(
                    'Vui lòng gán một shop Printify cho người dùng này.',
                    422,
                    ['code' => 'printify_shop_assignment_required']
                );
            }

            if (array_key_exists('shop_id', $validated)
                && $validated['shop_id'] !== null
                && (int) $validated['shop_id'] !== (int) $shop->id) {
                return $this->error(
                    'Không được chọn shop Printify khác với shop đã gán.',
                    422,
                    ['code' => 'printify_shop_spoof_rejected']
                );
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
