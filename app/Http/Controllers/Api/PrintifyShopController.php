<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintifyShopResource;
use App\Models\PrintifyShop;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PrintifyShopController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $request->validate([
            'active_only' => ['sometimes', 'boolean'],
            'open_only' => ['sometimes', 'boolean'],
        ]);

        $query = PrintifyShop::query()->orderBy('title');

        // Default management list: active shops (includes closed).
        $activeOnly = $request->has('active_only')
            ? $request->boolean('active_only')
            : true;

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($request->boolean('open_only')) {
            $query->where('is_open', true);
        }

        return $this->success(PrintifyShopResource::collection($query->paginate()));
    }

    public function confirmManualApproval(Request $request, PrintifyShop $shop)
    {
        $shop->update([
            'manual_approval_confirmed_by' => $request->user()->id,
            'manual_approval_confirmed_at' => now(),
        ]);

        return $this->success(new PrintifyShopResource($shop), 'Đã xác nhận Manual approval');
    }

    public function open(Request $request, PrintifyShop $shop)
    {
        $shop->setOpenState(true, $request->user()->id);

        return $this->success(
            new PrintifyShopResource($shop->fresh()),
            'Đã mở shop trên hệ thống (cho chọn khi tạo đơn). Không đổi Printify.'
        );
    }

    public function close(Request $request, PrintifyShop $shop)
    {
        $shop->setOpenState(false, $request->user()->id);

        return $this->success(
            new PrintifyShopResource($shop->fresh()),
            'Đã đóng shop trên hệ thống (không cho chọn khi tạo đơn). Không đổi Printify.'
        );
    }
}
