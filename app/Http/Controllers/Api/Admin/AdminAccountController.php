<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->whereIn('role', User::ROLES)
            ->latest()
            ->paginate(20, ['id', 'name', 'email', 'role', 'is_active', 'created_at']);

        return response()->json($users);
    }

    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
        ]);
        $data['email'] = mb_strtolower($data['email']);

        if (User::query()->where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Un compte existe déjà pour cette adresse e-mail.'], 409);
        }

        $token = Str::random(64);
        $invitation = AdminInvitation::query()->create([
            ...$data,
            'token_hash' => hash('sha256', $token),
            'created_by' => $request->user()->id,
            'expires_at' => now()->addHours(24),
        ]);

        return response()->json([
            'invitation' => [
                'id' => $invitation->id,
                'name' => $invitation->name,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at,
            ],
            'token' => $token,
        ], 201);
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($data === []) {
            return response()->json(['message' => 'Aucune modification fournie.'], 422);
        }

        $user = User::query()->findOrFail($userId);
        if ($user->is($request->user()) && (array_key_exists('role', $data) || (($data['is_active'] ?? true) === false))) {
            return response()->json(['message' => 'Vous ne pouvez pas modifier votre propre accès depuis cet écran.'], 422);
        }

        DB::transaction(function () use ($user, $data): void {
            $accountLock = DB::table('admin_account_locks')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if ($accountLock === null) {
                throw new \RuntimeException('The admin account lock is missing. Run migrations first.');
            }

            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $nextRole = $data['role'] ?? $lockedUser->role;
            $nextActive = (bool) ($data['is_active'] ?? $lockedUser->is_active);

            if ($lockedUser->role === 'super_admin' && $lockedUser->is_active
                && ($nextRole !== 'super_admin' || ! $nextActive)) {
                $otherActiveSuperAdmins = User::query()
                    ->where('role', 'super_admin')
                    ->where('is_active', true)
                    ->where('id', '!=', $lockedUser->id)
                    ->lockForUpdate()
                    ->count();

                if ($otherActiveSuperAdmins === 0) {
                    abort(422, 'Le dernier super administrateur actif ne peut pas être désactivé ou rétrogradé.');
                }
            }

            $lockedUser->fill($data)->save();
        });

        return response()->json([
            'data' => $user->fresh()->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
        ]);
    }
}
