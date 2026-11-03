<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates an administrator for the dashboard.
 *
 * Administrators are never seeded, so there is no default account to forget
 * about and no shared password baked into the repository.
 */
class CreateAdmin extends Command
{
    protected $signature = 'sentinel:create-admin
                            {--name= : The administrator\'s name}
                            {--email= : The administrator\'s email address or username}
                            {--password= : The password, for non-interactive use}';

    protected $description = 'Create a SENTINEL dashboard administrator';

    public function handle(): int
    {
        $name = $this->option('name') ?: text(
            label: 'Name',
            required: true,
        );

        $email = $this->option('email') ?: text(
            label: 'Email address or username',
            required: true,
        );

        $plainPassword = $this->option('password') ?: password(
            label: 'Password',
            required: true,
        );

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
        ], [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'string', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
            'is_admin' => true,
        ])->save();

        $this->components->info("Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
