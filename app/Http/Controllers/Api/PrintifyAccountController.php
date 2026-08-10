<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintifyAccountResource;
use App\Jobs\SyncPrintifyShopsJob;
use App\Models\PrintifyAccount;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class PrintifyAccountController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = PrintifyAccount::query()->withCount('shops');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $accounts = $query->latest()->paginate($request->integer('per_page', 15));
        $this->attachAssignedUserCounts(collect($accounts->items()));

        return $this->success(PrintifyAccountResource::collection($accounts), 'Lấy danh sách Printify account thành công');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:255|unique:printify_accounts,email',
            'api_key' => 'required|string|min:1',
        ]);

        try {
            $account = PrintifyAccount::create([
                'email' => $data['email'],
                'api_key' => $data['api_key'],
                'is_active' => true,
            ]);
        } catch (Throwable $e) {
            // Never log $e->getMessage(): QueryException interpolates bound values
            // (including api_key) directly into its message text.
            Log::error('printify_account.store_failed', [
                'exception_class' => $e::class,
                'email' => $data['email'],
            ]);

            return $this->error('Không thể tạo Printify account. Vui lòng thử lại.', 500);
        }

        SyncPrintifyShopsJob::dispatch($account->id)->afterResponse();

        $account->loadCount('shops');
        $this->attachAssignedUserCounts(collect([$account]));

        return $this->success(
            new PrintifyAccountResource($account),
            'Tạo Printify account thành công. Hệ thống đang đồng bộ shop — có thể mất vài phút, vui lòng làm mới danh sách shop sau.',
            201
        );
    }

    public function show(PrintifyAccount $account)
    {
        $account->loadCount('shops')->load('shops');
        $this->attachAssignedUserCounts(collect([$account]));

        return $this->success(new PrintifyAccountResource($account), 'Lấy chi tiết Printify account thành công');
    }

    public function update(Request $request, PrintifyAccount $account)
    {
        $data = $request->validate([
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('printify_accounts', 'email')->ignore($account->id)],
            'api_key' => ['nullable', 'string', 'min:1'],
        ]);

        $changes = [];
        if (array_key_exists('email', $data)) {
            $changes['email'] = $data['email'];
        }
        if (filled($data['api_key'] ?? null)) {
            $changes['api_key'] = $data['api_key'];
            $changes['key_rotated_by'] = $request->user()->id;
            $changes['key_rotated_at'] = now();
        }

        try {
            $account->update($changes);
        } catch (Throwable $e) {
            // Never log $e->getMessage(): QueryException interpolates bound values
            // (including api_key) directly into its message text.
            Log::error('printify_account.update_failed', [
                'exception_class' => $e::class,
                'account_id' => $account->id,
            ]);

            return $this->error('Không thể cập nhật Printify account. Vui lòng thử lại.', 500);
        }

        $account->loadCount('shops');
        $this->attachAssignedUserCounts(collect([$account]));

        return $this->success(new PrintifyAccountResource($account), 'Cập nhật Printify account thành công');
    }

    public function deactivate(PrintifyAccount $account)
    {
        $account->update(['is_active' => false]);
        $account->loadCount('shops');
        $this->attachAssignedUserCounts(collect([$account]));

        return $this->success(new PrintifyAccountResource($account), 'Đã vô hiệu hoá Printify account');
    }

    public function activate(PrintifyAccount $account)
    {
        $account->update(['is_active' => true]);
        $account->loadCount('shops');
        $this->attachAssignedUserCounts(collect([$account]));

        return $this->success(new PrintifyAccountResource($account), 'Đã kích hoạt Printify account');
    }

    /**
     * Distinct-seller count per account, computed via the seller<->shop pivot since a seller
     * may now be assigned to multiple shops (no longer a plain hasManyThrough). Sets
     * `assigned_users_count` on each model so PrintifyAccountResource::whenCounted('assignedUsers')
     * finds it exactly as it would after a native Eloquent withCount().
     *
     * @param  Collection<int, PrintifyAccount>  $accounts
     */
    private function attachAssignedUserCounts(Collection $accounts): void
    {
        $accountIds = $accounts->pluck('id');

        $counts = DB::table('printify_shop_user')
            ->join('printify_shops', 'printify_shops.id', '=', 'printify_shop_user.printify_shop_id')
            ->whereIn('printify_shops.printify_account_id', $accountIds)
            ->selectRaw('printify_shops.printify_account_id as account_id, COUNT(DISTINCT printify_shop_user.user_id) as user_count')
            ->groupBy('printify_shops.printify_account_id')
            ->pluck('user_count', 'account_id');

        $accounts->each(fn (PrintifyAccount $account) => $account->setAttribute(
            'assigned_users_count',
            (int) ($counts->get($account->id) ?? 0)
        ));
    }
}
