<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintifyShopResource;
use App\Jobs\SyncPrintifyShopsJob;
use App\Models\PrintifyAccount;
use App\Models\PrintifyProductVariant;
use App\Models\PrintifyShop;
use App\Services\Printify\PrintifyDefaultSkuEnsurer;
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
            'account_id' => ['sometimes', 'integer', 'exists:printify_accounts,id'],
        ]);

        $query = PrintifyShop::query()
            ->with(['account', 'assignedUser'])
            ->withExists([
                'orders as has_order_conflicts' => fn ($query) => $query->where('has_conflict', true),
                'assignedUser as has_assigned_user',
            ])
            // Assigned holders first so ops can find reserved shops quickly; title within each group.
            ->orderByDesc('has_assigned_user')
            ->orderBy('title');

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

        if ($request->filled('account_id')) {
            $query->where('printify_account_id', $request->integer('account_id'));
        }

        $user = $request->user();
        if (! $user->hasRole('admin')) {
            if ($user->printify_shop_id === null) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('id', $user->printify_shop_id);
            }
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 1000);

        return $this->success(PrintifyShopResource::collection($query->paginate($perPage)));
    }

    public function sync(Request $request)
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'exists:printify_accounts,id'],
        ]);

        $account = PrintifyAccount::query()
            ->whereKey($validated['account_id'])
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            return $this->error(
                'Printify account is inactive or missing.',
                422,
                ['code' => 'printify_account_inactive']
            );
        }

        SyncPrintifyShopsJob::dispatch($account->id)->afterResponse();

        return $this->success(
            [
                'queued' => true,
                'account_id' => $account->id,
            ],
            'Hệ thống đang đồng bộ shop từ Printify — có thể mất vài phút. Vui lòng làm mới danh sách sau khi hoàn tất.'
        );
    }

    public function confirmManualApproval(Request $request, PrintifyShop $shop)
    {
        $this->authorize('manage', $shop);

        $shop->update([
            'manual_approval_confirmed_by' => $request->user()->id,
            'manual_approval_confirmed_at' => now(),
        ]);

        return $this->success(new PrintifyShopResource($shop), 'Đã xác nhận Manual approval');
    }

    public function open(Request $request, PrintifyShop $shop)
    {
        $this->authorize('manage', $shop);

        $shop->setOpenState(true, $request->user()->id);

        return $this->success(
            new PrintifyShopResource($shop->fresh()),
            'Đã mở shop trên hệ thống (cho chọn khi tạo đơn). Không đổi Printify.'
        );
    }

    public function close(Request $request, PrintifyShop $shop)
    {
        $this->authorize('manage', $shop);

        $shop->setOpenState(false, $request->user()->id);

        return $this->success(
            new PrintifyShopResource($shop->fresh()),
            'Đã đóng shop trên hệ thống (không cho chọn khi tạo đơn). Không đổi Printify.'
        );
    }

    public function ensureDefaultSku(
        Request $request,
        PrintifyShop $shop,
        PrintifyDefaultSkuEnsurer $ensurer,
    ) {
        $this->authorize('manage', $shop);

        if (! $shop->is_active) {
            return $this->error(
                'Shop Printify hiện không hoạt động.',
                422,
                ['code' => 'printify_shop_not_ready']
            );
        }

        $shop->loadMissing('account');
        if ($shop->account === null || ! $shop->account->is_active) {
            return $this->error(
                'Printify account của shop hiện không hoạt động.',
                422,
                ['code' => 'printify_account_inactive']
            );
        }

        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);
        $force = (bool) ($validated['force'] ?? false);

        $result = $ensurer->ensureForShop($shop, seedProduct: true, force: $force);

        if ($result['status'] === 'failed') {
            return $this->error(
                'Không thể sync sản phẩm mặc định từ Printify.',
                502,
                ['code' => 'default_sku_sync_failed', 'reason' => $result['reason']]
            );
        }

        if ($result['reason'] === 'no_unique_enabled_sku') {
            return $this->error(
                'Không tìm thấy SKU enabled duy nhất để đặt làm mặc định.',
                422,
                ['code' => 'default_sku_not_resolved']
            );
        }

        $code = match ($result['reason']) {
            'already_set' => 'default_sku_already_set',
            'forced' => 'default_sku_refreshed',
            default => 'default_sku_set',
        };

        $freshShop = PrintifyShop::query()
            ->with(['account', 'assignedUser'])
            ->withExists([
                'orders as has_order_conflicts' => fn ($query) => $query->where('has_conflict', true),
            ])
            ->findOrFail($shop->id);

        $message = match ($code) {
            'default_sku_refreshed' => 'Đã sync lại sản phẩm và cập nhật default SKU cho shop.',
            'default_sku_set' => 'Đã sync một sản phẩm và đặt default SKU cho shop.',
            default => 'Shop đã có default SKU.',
        };

        return $this->success([
            'result' => [
                'code' => $code,
                'status' => $result['status'],
                'sku' => $result['sku'],
                'reason' => $result['reason'],
            ],
            'shop' => new PrintifyShopResource($freshShop),
        ], $message);
    }

    public function updateDefaultSku(Request $request, PrintifyShop $shop)
    {
        $this->authorize('manage', $shop);

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
