<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderImportService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class OrderImportController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderImportService $importService) {}

    public function import(Request $request)
    {
        $request->validate([
            'orders'                        => 'required|array|min:1',
            'orders.*.ebay_order_id'        => 'required|string',
            'orders.*.ebay_created_at'      => 'required|date',
            'orders.*.buyer_code'           => 'nullable|string',
            'orders.*.seller_code'          => 'nullable|string',
            'orders.*.printify_order_id'    => 'nullable|string',
            'orders.*.printify_created_at'  => 'nullable|date',
        ]);

        $result = $this->importService->importFromArray($request->orders);

        $message = "Import hoàn tất: {$result['success']}/{$result['total']} thành công";

        return $this->success($result, $message);
    }
}
