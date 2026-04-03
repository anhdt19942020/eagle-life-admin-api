<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Traits\ApiResponse;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['Tài khoản hoặc mật khẩu không chính xác.'],
            ]);
        }

        $user = Auth::user();
        
        if (!$user->status) {
            throw ValidationException::withMessages([
                'email' => ['Tài khoản của bạn đã bị khóa.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $user->load('roles.permissions');

        return $this->success([
            'access_token' => $token,
            'user' => $user
        ], 'Đăng nhập thành công');
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('roles.permissions');
        return $this->success($user);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->success(null, 'Đăng xuất thành công');
    }
}
