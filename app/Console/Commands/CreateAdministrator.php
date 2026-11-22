<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdministrator extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Interactively create an administrator without exposing the password in shell history';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Name'), 'email' => $this->ask('Email'), 'password' => $this->secret('Password (at least 12 characters)'), 'password_confirmation' => $this->secret('Confirm password')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

return self::FAILURE;
        }
        $user = new User($data);
        $user->is_admin = true;
        $user->save();
        $this->info('Administrator created. Sign in at /admin.');

        return self::SUCCESS;
    }
}
