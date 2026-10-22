<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AdminInvitationAcceptanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $invitation = AdminInvitation::query()
                ->where('token_hash', hash('sha256', $data['token']))
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->accepted_at || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'token' => 'Cette invitation est invalide ou expirée.',
                ]);
            }

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'token' => 'Cette invitation ne peut plus être utilisée.',
                ]);
            }

            $user = User::query()->create([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => $data['password'],
                'role' => $invitation->role,
                'is_active' => true,
            ]);
            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        return response()->json([
            'message' => 'Le compte a été activé. Vous pouvez maintenant vous connecter.',
            'email' => $user->email,
        ], 201);
    }
}
