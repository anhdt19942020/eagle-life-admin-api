<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesGroup;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesGroupController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = SalesGroup::query()->withCount('users');

        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $groups = $query->latest()->paginate($request->integer('per_page', 15));

        return $this->success($groups, 'Lấy danh sách nhóm bán hàng thành công');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'platform' => ['required', 'string', Rule::in(SalesGroup::PLATFORMS)],
            'code' => 'nullable|string|max:50|unique:sales_groups,code',
            'status' => 'nullable|boolean',
        ]);

        $group = SalesGroup::create([
            'name' => $data['name'],
            'platform' => $data['platform'],
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? true,
        ]);

        return $this->success($group, 'Tạo nhóm bán hàng thành công', 201);
    }

    public function show(SalesGroup $salesGroup)
    {
        $salesGroup->loadCount('users');
        $salesGroup->load(['users:id,name,email,employee_code,sales_group_id,status']);

        return $this->success($salesGroup, 'Lấy chi tiết nhóm bán hàng thành công');
    }

    public function update(Request $request, SalesGroup $salesGroup)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'platform' => ['sometimes', 'string', Rule::in(SalesGroup::PLATFORMS)],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('sales_groups', 'code')->ignore($salesGroup->id),
            ],
            'status' => 'sometimes|boolean',
        ]);

        $salesGroup->update($data);

        return $this->success($salesGroup->fresh(), 'Cập nhật nhóm bán hàng thành công');
    }

    public function destroy(SalesGroup $salesGroup)
    {
        if ($salesGroup->users()->exists()) {
            return $this->error('Không thể xoá nhóm đang có thành viên', 422);
        }

        $salesGroup->delete();

        return $this->success(null, 'Xoá nhóm bán hàng thành công');
    }
}
