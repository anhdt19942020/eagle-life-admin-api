<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
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
            'seller:id,name,employee_code,printify_shop_id',
            'seller.printifyShop:id,title,printify_shop_id,is_open',
            'lineItems:id,order_id,title',
        ];

        if ($request->query('trashed') === 'only') {
            if (! $user->hasRole('admin')) {
                return $this->error('Chỉ admin được xem đơn đã xoá.', 403);
            }
            $query->onlyTrashed();
            $with[] = 'deletedBy:id,name,employee_code';
        }

        $this->applyOrderFilters($query, $request);

        $sellerStats = $this->sellerStatsFor($query);

        if ($request->filled('seller_id')) {
            $query->where('seller_id', $request->seller_id);
        }

        $orders = $query->with($with)->latest('ebay_created_at')->paginate($request->per_page ?? 25);
        $payload = $orders->toArray();
        $payload['seller_stats'] = $sellerStats;

        return $this->success($payload, 'Lấy danh sách đơn hàng thành công');
    }

    public function show(Request $request, $id)
    {
        $order = Order::query()
            ->visibleTo($request->user())
            ->with([
                'buyer:id,name,employee_code',
                'seller:id,name,employee_code,printify_shop_id',
                'seller.printifyShop:id,title,printify_shop_id,is_open',
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
            $order->load([
                'buyer:id,name,employee_code',
                'seller:id,name,employee_code,printify_shop_id',
                'seller.printifyShop:id,title,printify_shop_id,is_open',
            ]),
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
            $order->fresh()->load([
                'buyer:id,name,employee_code',
                'seller:id,name,employee_code,printify_shop_id',
                'seller.printifyShop:id,title,printify_shop_id,is_open',
            ]),
            'Khôi phục đơn hàng thành công'
        );
    }

    /**
     * Apply list filters except seller_id (seller filter is applied after stats).
     *
     * @param  Builder<Order>  $query
     */
    private function applyOrderFilters(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ebay_order_id', 'like', "%{$search}%")
                  ->orWhere('printify_order_id', 'like', "%{$search}%");
            });
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

        if ($request->has('no_printify') && $request->no_printify) {
            $query->whereNull('printify_order_id');
        }
    }

    /**
     * Order counts per seller within the current visibility + filters (excluding seller_id).
     *
     * @param  Builder<Order>  $query
     * @return list<array{seller_id: int|null, orders_count: int, seller: array{id: int, name: string, employee_code: string|null}|null}>
     */
    private function sellerStatsFor(Builder $query): array
    {
        $counts = (clone $query)
            ->reorder()
            ->selectRaw('seller_id, COUNT(*) as orders_count')
            ->groupBy('seller_id')
            ->orderByDesc('orders_count')
            ->get();

        $sellers = User::query()
            ->whereIn('id', $counts->pluck('seller_id')->filter())
            ->get(['id', 'name', 'employee_code'])
            ->keyBy('id');

        return $counts->map(function ($row) use ($sellers) {
            $seller = $row->seller_id !== null ? $sellers->get($row->seller_id) : null;

            return [
                'seller_id' => $row->seller_id !== null ? (int) $row->seller_id : null,
                'orders_count' => (int) $row->orders_count,
                'seller' => $seller ? [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'employee_code' => $seller->employee_code,
                ] : null,
            ];
        })->values()->all();
    }
}
