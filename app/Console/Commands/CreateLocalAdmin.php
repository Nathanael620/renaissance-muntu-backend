<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateLocalAdmin extends Command
{
    protected $signature = 'admin:create-local-admin';

    protected $description = 'Create an active admin account in the local Renaissance-Muntu database';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('This command can only run when APP_ENV=local.');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $connectionConfig = $connection->getConfig();
        $host = strtolower((string) ($connectionConfig['host'] ?? ''));

        if (
            $connection->getDriverName() !== 'mysql'
            || ($connectionConfig['database'] ?? null) !== 'renaissance_muntu'
            || ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        ) {
            $this->error('The active connection is not the local MySQL renaissance_muntu database.');

            return self::FAILURE;
        }

        $actualDatabase = $connection->selectOne('SELECT DATABASE() AS database_name')?->database_name;
        if ($actualDatabase !== 'renaissance_muntu') {
            $this->error('The active MySQL database is not renaissance_muntu.');

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
            if (User::query()->where('email', $email)->exists()) {
                return false;
            }

            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'admin',
                'is_active' => true,
            ]);

            return true;
        });

        if (! $created) {
            $this->error('An account with this email address already exists.');

            return self::FAILURE;
        }

        $this->info('The local admin account was created.');

        return self::SUCCESS;
    }
}
