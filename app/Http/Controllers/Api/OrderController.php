<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Order::with([
            'buyer:id,name,employee_code',
            'seller:id,name,employee_code',
        ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ebay_order_id', 'like', "%{$search}%")
                  ->orWhere('printify_order_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('seller_id')) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->filled('buyer_id')) {
            $query->where('buyer_id', $request->buyer_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('ebay_created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('ebay_created_at', '<=', $request->to_date);
        }

        // Filter: chưa có printify_order_id
        if ($request->has('no_printify') && $request->no_printify) {
            $query->whereNull('printify_order_id');
        }

        $orders = $query->latest('ebay_created_at')->paginate($request->per_page ?? 15);

        return $this->success($orders, 'Lấy danh sách đơn hàng thành công');
    }

    public function show($id)
    {
        $order = Order::with([
            'buyer:id,name,employee_code',
            'seller:id,name,employee_code',
        ])->findOrFail($id);

        return $this->success($order, 'Lấy chi tiết đơn hàng thành công');
    }

    public function update(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $request->validate([
            'printify_order_id'   => 'nullable|string',
            'printify_created_at' => 'nullable|date',
            'seller_id'           => 'nullable|exists:users,id',
            'buyer_id'            => 'nullable|exists:users,id',
        ]);

        $order->update($request->only([
            'printify_order_id',
            'printify_created_at',
            'seller_id',
            'buyer_id',
        ]));

        return $this->success(
            $order->load(['buyer:id,name,employee_code', 'seller:id,name,employee_code']),
            'Cập nhật đơn hàng thành công'
        );
    }

    public function destroy($id)
    {
        Order::findOrFail($id)->delete();
        return $this->success(null, 'Xoá đơn hàng thành công');
    }
}
