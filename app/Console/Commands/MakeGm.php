<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeGm extends Command
{
    protected $signature = 'user:make-gm {email} {--role=gm : gm or owner}';

    protected $description = 'Grants a user the GM or Owner role so they can access the GM console.';

    public function handle(): int
    {
        $role = $this->option('role');
        if (! in_array($role, ['gm', 'owner'], true)) {
            $this->error('Role must be "gm" or "owner".');

            return self::FAILURE;
        }

        $email = $this->argument('email');
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        // User has a #[Fillable(['name', 'email', 'password'])] mass-assignment guard, so `role` must be set directly.
        $user->role = $role;
        $user->save();

        $this->info("{$user->email} is now a {$role}.");

        return self::SUCCESS;
    }
}
