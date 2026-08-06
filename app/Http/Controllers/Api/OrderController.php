<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Order::query()->visibleTo($user);
        $with = [
            'buyer:id,name,employee_code',
            'seller:id,name,employee_code',
            'lineItems:id,order_id,title',
        ];

        if ($request->query('trashed') === 'only') {
            if (! $user->hasRole('admin')) {
                return $this->error('Chỉ admin được xem đơn đã xoá.', 403);
            }
            $query->onlyTrashed();
            $with[] = 'deletedBy:id,name,employee_code';
        }

        $query->with($with);

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

    public function show(Request $request, $id)
    {
        $order = Order::query()
            ->visibleTo($request->user())
            ->with([
                'buyer:id,name,employee_code',
                'seller:id,name,employee_code',
                'fulfillmentAddress',
                'lineItems',
            ])
            ->findOrFail($id);

        return $this->success($order, 'Lấy chi tiết đơn hàng thành công');
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $order = Order::query()->visibleTo($user)->findOrFail($id);

        $request->validate([
            'seller_id' => 'nullable|exists:users,id',
            'buyer_id' => 'nullable|exists:users,id',
        ]);

        if (! $user->hasRole('admin')
            && $request->exists('seller_id')
            && (int) $request->input('seller_id') !== (int) $order->seller_id) {
            throw ValidationException::withMessages([
                'seller_id' => ['Bạn không được thay đổi seller của đơn hàng.'],
            ]);
        }

        $order->update($request->only([
            'seller_id',
            'buyer_id',
        ]));

        return $this->success(
            $order->load(['buyer:id,name,employee_code', 'seller:id,name,employee_code']),
            'Cập nhật đơn hàng thành công'
        );
    }

    public function destroy(Request $request, $id)
    {
        $order = Order::query()->visibleTo($request->user())->findOrFail($id);
        $order->forceFill(['deleted_by' => $request->user()->id])->save();
        $order->delete();

        return $this->success(null, 'Xoá đơn hàng thành công');
    }

    public function restore(Request $request, $id)
    {
        if (! $request->user()->hasRole('admin')) {
            return $this->error('Chỉ admin được khôi phục đơn đã xoá.', 403);
        }

        $order = Order::onlyTrashed()->findOrFail($id);
        $order->restore();
        $order->forceFill(['deleted_by' => null])->save();

        return $this->success(
            $order->fresh()->load(['buyer:id,name,employee_code', 'seller:id,name,employee_code']),
            'Khôi phục đơn hàng thành công'
        );
    }
}
