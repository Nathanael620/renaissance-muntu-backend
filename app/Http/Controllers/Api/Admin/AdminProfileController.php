<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminProfileController extends Controller
{
    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => $request->user()->preferences ?? [
                'dark_mode' => false,
                'email_notifications' => true,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($data);

        return response()->json(['data' => $user->only(['id', 'name', 'email'])]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dark_mode' => ['sometimes', 'boolean'],
            'email_notifications' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $user->preferences = array_merge($user->preferences ?? [], $data);
        $user->save();

        return response()->json(['preferences' => $user->preferences]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Le mot de passe a été mis à jour.']);
    }
}
