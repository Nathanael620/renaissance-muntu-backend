<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ])->validate();

        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::guard('web')->attempt([
            'email' => mb_strtolower($credentials['email']),
            'password' => $credentials['password'],
        ], $remember)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        $user = Auth::guard('web')->user();
        if (! $user || ! $user->is_active || ! in_array($user->role, \App\Models\User::ROLES, true)) {
            Auth::guard('web')->logout();

            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->json([], 204);
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, permissions: array<int, string>}
     */
    private function userPayload(\App\Models\User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'permissions' => $user->adminPermissions(),
        ];
    }
}
