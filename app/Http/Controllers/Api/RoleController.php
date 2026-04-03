<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use App\Traits\ApiResponse;

class RoleController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $roles = Role::with('permissions')->get();
        return $this->success($roles, 'Lấy danh sách vai trò thành công');
    }
}
