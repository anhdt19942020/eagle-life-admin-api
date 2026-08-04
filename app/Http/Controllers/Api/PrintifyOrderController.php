<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PrintifyShop;
use App\Services\Printify\PrintifyOrderCreateService;
use App\Services\Printify\PrintifyOrderPreviewService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PrintifyOrderController extends Controller
{
    use ApiResponse;

    public function preview(Request $request, Order $order, PrintifyOrderPreviewService $preview)
    {
        [$shop, $mappings] = $this->validatedShopAndMappings($request);

        try {
            $result = $preview->preview($order, $shop, $mappings);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($result, $result['ready']
            ? 'Printify order payload ready (dry-run)'
            : 'Printify order payload is not ready');
    }

    public function create(Request $request, Order $order, PrintifyOrderCreateService $create)
    {
        [$shop, $mappings] = $this->validatedShopAndMappings($request);

        try {
            $result = $create->create($order, $shop, $mappings);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
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
     * @return array{0: PrintifyShop, 1: array<int, array{line_item_id: int, variant_id: int}>}
     */
    private function validatedShopAndMappings(Request $request): array
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:printify_shops,id'],
            'line_mappings' => ['sometimes', 'array'],
            'line_mappings.*.line_item_id' => ['required', 'integer', 'exists:order_line_items,id'],
            'line_mappings.*.variant_id' => ['required', 'integer'],
        ]);

        return [
            PrintifyShop::findOrFail($validated['shop_id']),
            $validated['line_mappings'] ?? [],
        ];
    }
}
