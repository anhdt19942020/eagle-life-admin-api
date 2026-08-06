<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintifyShop;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = User::with(['roles', 'salesGroup', 'printifyShop.account']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
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

        $users = $query->latest()->paginate($request->per_page ?? 15);

        return $this->success($users, 'Lấy danh sách người dùng thành công');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'username' => 'nullable|string|max:255|unique:users',
            'phone' => 'nullable|string|max:20|unique:users',
            'avatar' => 'nullable|string',
            'role' => 'nullable|string|exists:roles,name',
            'sales_group_id' => [
                Rule::requiredIf(fn () => in_array($request->input('role'), User::GROUP_REQUIRED_ROLES, true)),
                'nullable',
                'exists:sales_groups,id',
            ],
            'printify_account_id' => ['nullable', 'integer', 'exists:printify_accounts,id'],
            'printify_shop_id' => $this->printifyShopIdRules(null),
        ]);

        $validator->validate();

        $role = $request->filled('role') ? $request->role : null;

        if ($assignmentError = $this->resolvePrintifyAssignmentError($request, $role, null)) {
            [$code, $message] = $assignmentError;

            return $this->error($message, 422, ['code' => $code]);
        }

        $salesGroupId = $role === 'admin' ? null : $request->input('sales_group_id');
        $printifyShopId = $role === 'admin' ? null : $request->input('printify_shop_id');

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
                'printify_shop_id' => $printifyShopId,
                'printify_shop_assigned_by' => $printifyShopId ? $request->user()->id : null,
                'printify_shop_assigned_at' => $printifyShopId ? now() : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->respondToUniqueViolation($request, null);
        }

        if ($role) {
            $user->assignRole($role);
        }

        return $this->success($user->load(['roles', 'salesGroup', 'printifyShop.account']), 'Tạo người dùng thành công', 201);
    }

    public function show($id)
    {
        $user = User::with(['roles', 'salesGroup', 'printifyShop.account'])->findOrFail($id);

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
            'username' => 'nullable|string|max:255|unique:users,username,'.$id,
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
            'printify_shop_id' => $this->printifyShopIdRules($user->id),
        ]);

        $validator->validate();

        if ($assignmentError = $this->resolvePrintifyAssignmentError($request, $effectiveRole, $user)) {
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

        if ($effectiveRole === 'admin') {
            $data['printify_shop_id'] = null;
            $data['printify_shop_assigned_by'] = null;
            $data['printify_shop_assigned_at'] = null;
        } elseif ($request->has('printify_shop_id')
            && (int) $request->input('printify_shop_id') !== (int) $user->printify_shop_id) {
            $data['printify_shop_id'] = $request->input('printify_shop_id');
            $data['printify_shop_assigned_by'] = $request->user()->id;
            $data['printify_shop_assigned_at'] = now();
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

        return $this->success($user->load(['roles', 'salesGroup', 'printifyShop.account']), 'Cập nhật người dùng thành công');
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
     * @return array<int, string|\Illuminate\Contracts\Validation\Rule>
     */
    private function printifyShopIdRules(?int $ignoreUserId): array
    {
        return [
            'nullable',
            'integer',
            'exists:printify_shops,id',
            Rule::unique('users', 'printify_shop_id')->ignore($ignoreUserId),
        ];
    }

    /**
     * Business-rule check for the seller/group_leader shop assignment, run after structural
     * validation (required/exists/unique) has already passed. Returns [stable_code, vi_message]
     * for the three conditions the frontend order UI branches on, or null when the assignment
     * is valid or the role does not require one (including admin, which never needs a shop).
     *
     * @return array{0: string, 1: string}|null
     */
    private function resolvePrintifyAssignmentError(Request $request, ?string $effectiveRole, ?User $user): ?array
    {
        if (! in_array($effectiveRole, User::GROUP_REQUIRED_ROLES, true)) {
            return null;
        }

        $shopId = $request->has('printify_shop_id')
            ? ($request->filled('printify_shop_id') ? (int) $request->input('printify_shop_id') : null)
            : $user?->printify_shop_id;

        if ($shopId === null) {
            return ['printify_shop_assignment_required', 'Vui lòng gán một shop Printify cho người dùng này.'];
        }

        // Existence is already guaranteed by the exists:printify_shops,id rule at this point.
        $shop = PrintifyShop::with('account')->find($shopId);

        if (! $shop->is_active) {
            return ['printify_shop_not_ready', 'Shop Printify đã chọn hiện không sẵn sàng.'];
        }

        if ($shop->account === null || ! $shop->account->is_active) {
            return ['printify_account_inactive', 'Printify account của shop này hiện không hoạt động.'];
        }

        if ($request->filled('printify_account_id')
            && (int) $request->input('printify_account_id') !== (int) $shop->printify_account_id) {
            return ['printify_shop_not_ready', 'Shop không thuộc Printify account đã chọn.'];
        }

        return null;
    }

    /**
     * Disambiguates a UniqueConstraintViolationException raised by create()/update() by
     * re-checking each unique-constrained column, since email/username/phone and
     * printify_shop_id can all race the same validate-then-write window.
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

        if ($request->filled('printify_shop_id') && $collides('printify_shop_id', $request->input('printify_shop_id'))) {
            return $this->error('Shop này vừa được gán cho người dùng khác.', 422, [
                'printify_shop_id' => ['Shop này đã được gán cho người dùng khác.'],
            ]);
        }

        return $this->error('Không thể lưu người dùng do xung đột dữ liệu. Vui lòng thử lại.', 422);
    }
}
