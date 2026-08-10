<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintifyShop;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = User::with(['roles', 'salesGroup', 'printifyShops.account']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('role')) {
            $role = $request->role;
            $query->whereHas('roles', function ($q) use ($role) {
                $q->where('name', $role);
            });
        }

        if ($request->filled('sales_group_id')) {
            $query->where('sales_group_id', $request->sales_group_id);
        }

        if ($request->filled('printify_assignment')) {
            $assignment = $request->input('printify_assignment');
            if ($assignment === 'assigned') {
                $query->has('printifyShops');
            } elseif ($assignment === 'unassigned') {
                $query->doesntHave('printifyShops');
            }
        }

        $users = $query->latest()->paginate($request->per_page ?? 15);

        return $this->success($users, 'Lấy danh sách người dùng thành công');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'username' => 'required|string|max:255|unique:users',
            'phone' => 'nullable|string|max:20|unique:users',
            'avatar' => 'nullable|string',
            'role' => 'nullable|string|exists:roles,name',
            'sales_group_id' => [
                Rule::requiredIf(fn () => in_array($request->input('role'), User::GROUP_REQUIRED_ROLES, true)),
                'nullable',
                'exists:sales_groups,id',
            ],
            'printify_account_id' => ['nullable', 'integer', 'exists:printify_accounts,id'],
            'printify_shop_ids' => ['sometimes', 'array'],
            'printify_shop_ids.*' => ['integer', 'exists:printify_shops,id'],
            'default_printify_shop_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $validator->validate();

        $role = $request->filled('role') ? $request->role : null;
        $shopIds = $role === 'admin' ? [] : $this->requestedPrintifyShopIds($request);
        $defaultShopId = $this->requestedDefaultPrintifyShopId($request, $shopIds);

        $printifyAccountId = $request->filled('printify_account_id') ? (int) $request->input('printify_account_id') : null;

        if ($assignmentError = $this->resolvePrintifyAssignmentError($role, $shopIds, $defaultShopId, $printifyAccountId)) {
            [$code, $message] = $assignmentError;

            return $this->error($message, 422, ['code' => $code]);
        }

        $salesGroupId = $role === 'admin' ? null : $request->input('sales_group_id');

        $latestUser = User::orderBy('id', 'desc')->first();
        $nextId = $latestUser ? $latestUser->id + 1 : 1;
        $employeeCode = 'NV'.str_pad($nextId, 4, '0', STR_PAD_LEFT);

        try {
            $user = User::create([
                'employee_code' => $employeeCode,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'username' => $request->username,
                'phone' => $request->phone,
                'avatar' => $request->avatar,
                'status' => $request->status ?? 1,
                'sales_group_id' => $salesGroupId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->respondToUniqueViolation($request, null);
        }

        if ($role) {
            $user->assignRole($role);
        }

        $this->syncPrintifyShops($user, $shopIds, $defaultShopId, $request->user()->id);

        return $this->success($user->load(['roles', 'salesGroup', 'printifyShops.account']), 'Tạo người dùng thành công', 201);
    }

    public function show($id)
    {
        $user = User::with(['roles', 'salesGroup', 'printifyShops.account'])->findOrFail($id);

        return $this->success($user, 'Lấy chi tiết người dùng thành công');
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $effectiveRole = $this->resolveEffectiveRole($request, $user);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$id,
            'password' => 'nullable|string|min:8',
            'username' => 'sometimes|required|string|max:255|unique:users,username,'.$id,
            'phone' => 'nullable|string|max:20|unique:users,phone,'.$id,
            'avatar' => 'nullable|string',
            'role' => 'nullable|string|exists:roles,name',
            'sales_group_id' => [
                Rule::requiredIf(function () use ($request, $user, $effectiveRole) {
                    if (! in_array($effectiveRole, User::GROUP_REQUIRED_ROLES, true)) {
                        return false;
                    }

                    if ($request->has('sales_group_id')) {
                        return $request->input('sales_group_id') === null
                            || $request->input('sales_group_id') === '';
                    }

                    return $user->sales_group_id === null;
                }),
                'nullable',
                'exists:sales_groups,id',
            ],
            'printify_account_id' => ['nullable', 'integer', 'exists:printify_accounts,id'],
            'printify_shop_ids' => ['sometimes', 'array'],
            'printify_shop_ids.*' => ['integer', 'exists:printify_shops,id'],
            'default_printify_shop_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $validator->validate();

        $shopIds = $effectiveRole === 'admin' ? [] : $this->requestedPrintifyShopIds($request, $user);
        $defaultShopId = $this->requestedDefaultPrintifyShopId($request, $shopIds, $user);

        $printifyAccountId = $request->filled('printify_account_id') ? (int) $request->input('printify_account_id') : null;

        if ($assignmentError = $this->resolvePrintifyAssignmentError($effectiveRole, $shopIds, $defaultShopId, $printifyAccountId)) {
            [$code, $message] = $assignmentError;

            return $this->error($message, 422, ['code' => $code]);
        }

        $data = $request->only(['name', 'email', 'username', 'phone', 'avatar']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if ($request->has('sales_group_id') || $request->has('role')) {
            if ($effectiveRole === 'admin') {
                $data['sales_group_id'] = null;
            } elseif ($request->has('sales_group_id')) {
                $data['sales_group_id'] = $request->input('sales_group_id');
            }
        }

        try {
            $user->update($data);
        } catch (UniqueConstraintViolationException) {
            return $this->respondToUniqueViolation($request, $user->id);
        }

        if ($request->filled('role')) {
            $user->syncRoles([$request->role]);
        } elseif ($request->has('role') && empty($request->role)) {
            $user->syncRoles([]);
            $user->update(['sales_group_id' => null]);
        }

        if ($effectiveRole === 'admin') {
            $user->printifyShops()->detach();
        } elseif ($request->has('printify_shop_ids') || $request->has('default_printify_shop_id')) {
            $this->syncPrintifyShops($user, $shopIds, $defaultShopId, $request->user()->id);
        }

        return $this->success($user->load(['roles', 'salesGroup', 'printifyShops.account']), 'Cập nhật người dùng thành công');
    }

    public function updateStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $request->validate([
            'status' => 'required|boolean',
        ]);

        $user->update(['status' => $request->status]);

        return $this->success($user, 'Cập nhật trạng thái thành công');
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->hasRole('admin')) {
            return $this->error('Không thể xoá tài khoản Admin', 403);
        }

        $user->delete();

        return $this->success(null, 'Xoá người dùng thành công');
    }

    private function resolveEffectiveRole(Request $request, User $user): ?string
    {
        if ($request->has('role')) {
            return $request->filled('role') ? $request->role : null;
        }

        return $user->roles->first()?->name;
    }

    /**
     * Requested shop ids: explicit array from the request, falling back to the user's
     * currently-assigned shops when the request does not touch this field (update only).
     *
     * @return list<int>
     */
    private function requestedPrintifyShopIds(Request $request, ?User $user = null): array
    {
        if ($request->has('printify_shop_ids')) {
            return array_values(array_unique(array_map('intval', $request->input('printify_shop_ids', []))));
        }

        return $user?->printifyShops->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];
    }

    /**
     * @param  list<int>  $shopIds
     */
    private function requestedDefaultPrintifyShopId(Request $request, array $shopIds, ?User $user = null): ?int
    {
        if ($request->has('default_printify_shop_id')) {
            return $request->filled('default_printify_shop_id') ? (int) $request->input('default_printify_shop_id') : null;
        }

        $currentDefaultId = $user?->printifyShops->firstWhere('pivot.is_default', true)?->id;

        if ($currentDefaultId !== null && in_array((int) $currentDefaultId, $shopIds, true)) {
            return (int) $currentDefaultId;
        }

        return $shopIds[0] ?? null;
    }

    /**
     * Business-rule check for the seller/group_leader shop assignment, run after structural
     * validation (required/exists rules) has already passed. Returns [stable_code, vi_message]
     * for the conditions the frontend order UI branches on, or null when the assignment
     * is valid or the role does not require one (including admin, which never needs a shop).
     *
     * @param  list<int>  $shopIds
     * @return array{0: string, 1: string}|null
     */
    private function resolvePrintifyAssignmentError(?string $effectiveRole, array $shopIds, ?int $defaultShopId, ?int $printifyAccountId = null): ?array
    {
        if (! in_array($effectiveRole, User::GROUP_REQUIRED_ROLES, true)) {
            return null;
        }

        if (empty($shopIds)) {
            return ['printify_shop_assignment_required', 'Vui lòng gán ít nhất một shop Printify cho người dùng này.'];
        }

        if ($defaultShopId === null || ! in_array($defaultShopId, $shopIds, true)) {
            return ['printify_default_shop_invalid', 'Shop mặc định phải nằm trong danh sách shop đã gán.'];
        }

        // Existence is already guaranteed by the exists:printify_shops,id rule at this point.
        $shops = PrintifyShop::with('account')->whereIn('id', $shopIds)->get();

        foreach ($shops as $shop) {
            if (! $shop->is_active) {
                return ['printify_shop_not_ready', "Shop Printify \"{$shop->title}\" hiện không sẵn sàng."];
            }

            if ($shop->account === null || ! $shop->account->is_active) {
                return ['printify_account_inactive', "Printify account của shop \"{$shop->title}\" hiện không hoạt động."];
            }

            if ($printifyAccountId !== null && $shop->printify_account_id !== $printifyAccountId) {
                return ['printify_shop_account_mismatch', "Shop \"{$shop->title}\" không thuộc Printify account đã chọn."];
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $shopIds
     */
    private function syncPrintifyShops(User $user, array $shopIds, ?int $defaultShopId, int $assignedBy): void
    {
        DB::transaction(function () use ($user, $shopIds, $defaultShopId, $assignedBy) {
            $existingPivots = $user->printifyShops->keyBy('id');

            $pivotData = collect($shopIds)->mapWithKeys(function (int $shopId) use ($defaultShopId, $assignedBy, $existingPivots) {
                $existing = $existingPivots->get($shopId);

                return [$shopId => [
                    'is_default' => $shopId === $defaultShopId,
                    'assigned_by' => $existing ? $existing->pivot->assigned_by : $assignedBy,
                    'assigned_at' => $existing ? $existing->pivot->assigned_at : now(),
                ]];
            })->all();

            $user->printifyShops()->sync($pivotData);
        });
    }

    /**
     * Disambiguates a UniqueConstraintViolationException raised by create()/update() by
     * re-checking each unique-constrained column, since email/username/phone can all race
     * the same validate-then-write window.
     */
    private function respondToUniqueViolation(Request $request, ?int $ignoreUserId): JsonResponse
    {
        $collides = fn (string $column, $value) => User::where($column, $value)
            ->when($ignoreUserId, fn ($q) => $q->whereKeyNot($ignoreUserId))
            ->exists();

        if ($request->filled('email') && $collides('email', $request->input('email'))) {
            return $this->error('Email đã được sử dụng.', 422, ['email' => ['Email đã được sử dụng.']]);
        }

        if ($request->filled('username') && $collides('username', $request->input('username'))) {
            return $this->error('Username đã được sử dụng.', 422, ['username' => ['Username đã được sử dụng.']]);
        }

        if ($request->filled('phone') && $collides('phone', $request->input('phone'))) {
            return $this->error('Số điện thoại đã được sử dụng.', 422, ['phone' => ['Số điện thoại đã được sử dụng.']]);
        }

        return $this->error('Không thể lưu người dùng do xung đột dữ liệu. Vui lòng thử lại.', 422);
    }
}
