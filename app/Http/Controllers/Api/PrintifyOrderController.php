<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PrintifyShop;
use App\Services\Printify\PrintifyOrderPreviewService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PrintifyOrderController extends Controller
{
    use ApiResponse;

    public function preview(Request $request, Order $order, PrintifyOrderPreviewService $preview)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:printify_shops,id'],
            'line_mappings' => ['sometimes', 'array'],
            'line_mappings.*.line_item_id' => ['required', 'integer', 'exists:order_line_items,id'],
            'line_mappings.*.variant_id' => ['required', 'integer'],
        ]);

        $shop = PrintifyShop::findOrFail($validated['shop_id']);

        try {
            $result = $preview->preview($order, $shop, $validated['line_mappings'] ?? []);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($result, $result['ready']
            ? 'Printify order payload ready (dry-run)'
            : 'Printify order payload is not ready');
    }
}
