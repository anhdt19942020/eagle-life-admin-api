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
        $query = Order::with(['sale:id,name,employee_code']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_code', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->sale_id);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $orders = $query->latest()->paginate($request->per_page ?? 15);

        return $this->success($orders, 'Lấy danh sách đơn hàng thành công');
    }

    public function show($id)
    {
        $order = Order::with(['sale:id,name,employee_code'])->findOrFail($id);
        return $this->success($order, 'Lấy chi tiết đơn hàng thành công');
    }

    public function update(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $request->validate([
            'status' => 'sometimes|in:pending,processing,completed,canceled',
            'sale_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        $order->update($request->only(['status', 'sale_id', 'notes']));

        return $this->success($order->load('sale:id,name,employee_code'), 'Cập nhật đơn hàng thành công');
    }

    public function destroy($id)
    {
        $order = Order::findOrFail($id);
        $order->delete();
        return $this->success(null, 'Xoá đơn hàng thành công');
    }
}
