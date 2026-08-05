<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintifyShopResource;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use App\Services\Printify\PrintifySyncService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use RuntimeException;

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

        $perPage = min(max($request->integer('per_page', 15), 1), 1000);

        return $this->success(PrintifyShopResource::collection($query->paginate($perPage)));
    }

    public function sync(PrintifySyncService $sync)
    {
        try {
            $count = $sync->syncShops();
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }

        return $this->success(
            ['synced' => $count],
            "Đã sync {$count} shop từ Printify (upsert theo printify_shop_id, không tạo trùng)."
        );
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

    public function updateDefaultSku(Request $request, PrintifyShop $shop)
    {
        $validated = $request->validate([
            'default_sku' => ['nullable', 'string', 'max:255'],
        ]);

        $sku = trim((string) ($validated['default_sku'] ?? ''));
        if ($sku === '') {
            $shop->forceFill(['default_sku' => null])->save();

            return $this->success(
                new PrintifyShopResource($shop->fresh()),
                'Đã xóa default SKU của shop.'
            );
        }

        $matches = PrintifyProductVariant::query()
            ->where('sku', $sku)
            ->where('is_enabled', true)
            ->whereHas('product', fn ($query) => $query->where('printify_shop_id', $shop->id))
            ->count();

        if ($matches === 0) {
            return $this->error(
                "Không có variant enabled với SKU [{$sku}] trên shop này. Sync products trước rồi thử lại.",
                422
            );
        }

        if ($matches > 1) {
            return $this->error(
                "SKU [{$sku}] khớp {$matches} variants trên shop — chọn SKU unique hơn.",
                422
            );
        }

        $shop->forceFill(['default_sku' => $sku])->save();

        return $this->success(
            new PrintifyShopResource($shop->fresh()),
            'Đã cập nhật default SKU của shop.'
        );
    }
}
