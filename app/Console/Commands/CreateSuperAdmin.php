<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create-super-admin';

    protected $description = 'Create the first active super administrator interactively';

    public function handle(): int
    {
        if (User::query()->where('role', 'super_admin')->where('is_active', true)->exists()) {
            $this->error('An active super administrator already exists.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator name'));
        $email = mb_strtolower(trim((string) $this->ask('Administrator email')));
        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $created = DB::transaction(function () use ($name, $email, $password): bool {
            $lock = DB::table('admin_account_locks')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if ($lock === null) {
                throw new \RuntimeException('The admin initialization lock is missing. Run migrations first.');
            }

            if (User::query()->where('role', 'super_admin')->where('is_active', true)->exists()) {
                return false;
            }

            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'super_admin',
                'is_active' => true,
            ]);

            return true;
        });

        if (! $created) {
            $this->error('An active super administrator already exists.');

            return self::FAILURE;
        }

        $this->info('The initial super administrator was created.');

        return self::SUCCESS;
    }
}
