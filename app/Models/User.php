<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ROLES = ['super_admin', 'admin', 'editor', 'viewer'];

    private const ROLE_PERMISSIONS = [
        'super_admin' => [
            'dashboard.view',
            'messages.view',
            'messages.manage',
            'partnerships.view',
            'partnerships.manage',
            'resources.view',
            'resources.manage',
            'admins.manage',
            'profile.manage',
        ],
        'admin' => [
            'dashboard.view',
            'messages.view',
            'messages.manage',
            'partnerships.view',
            'partnerships.manage',
            'resources.view',
            'resources.manage',
            'profile.manage',
        ],
        'editor' => ['dashboard.view', 'resources.view', 'resources.manage', 'profile.manage'],
        'viewer' => [
            'dashboard.view',
            'messages.view',
            'partnerships.view',
            'resources.view',
            'profile.manage',
        ],
    ];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'preferences' => 'array',
        ];
    }

    public function hasAdminPermission(string $permission): bool
    {
        return $this->is_active
            && in_array($this->role, self::ROLES, true)
            && in_array($permission, self::ROLE_PERMISSIONS[$this->role] ?? [], true);
    }

    /**
     * @return array<int, string>
     */
    public function adminPermissions(): array
    {
        if (! $this->is_active || ! in_array($this->role, self::ROLES, true)) {
            return [];
        }

        return self::ROLE_PERMISSIONS[$this->role];
    }
}
