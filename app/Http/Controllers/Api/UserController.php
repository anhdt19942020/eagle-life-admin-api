<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = User::with(['roles', 'salesGroup']);

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
        $request->validate([
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
        ]);

        $role = $request->filled('role') ? $request->role : null;
        $salesGroupId = $role === 'admin' ? null : $request->input('sales_group_id');

        $latestUser = User::orderBy('id', 'desc')->first();
        $nextId = $latestUser ? $latestUser->id + 1 : 1;
        $employeeCode = 'NV' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

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

        if ($role) {
            $user->assignRole($role);
        }

        return $this->success($user->load(['roles', 'salesGroup']), 'Tạo người dùng thành công', 201);
    }

    public function show($id)
    {
        $user = User::with(['roles', 'salesGroup'])->findOrFail($id);

        return $this->success($user, 'Lấy chi tiết người dùng thành công');
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $effectiveRole = $this->resolveEffectiveRole($request, $user);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
            'password' => 'nullable|string|min:8',
            'username' => 'nullable|string|max:255|unique:users,username,' . $id,
            'phone' => 'nullable|string|max:20|unique:users,phone,' . $id,
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
        ]);

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

        $user->update($data);

        if ($request->filled('role')) {
            $user->syncRoles([$request->role]);
        } elseif ($request->has('role') && empty($request->role)) {
            $user->syncRoles([]);
            $user->update(['sales_group_id' => null]);
        }

        return $this->success($user->load(['roles', 'salesGroup']), 'Cập nhật người dùng thành công');
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
}
