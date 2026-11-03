<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Makes an account, or changes one, from the server: how the first admin is
 * made, since sign-up never makes admins, and how an admin who lost their
 * password gets back in. The password is asked for, never passed on the
 * command line, where the shell's history would keep it.
 */
#[Description('Create an account, or change one: make it an admin, or set its password')]
#[Signature('gallery:user
        {username : The account\'s username}
        {--admin : Make it an admin}
        {--no-admin : Make it an ordinary account}
        {--password : Set a new password (asked for); a new account always gets one}')]
final class ManageUser extends Command
{
    public function handle(): int
    {
        $username = (string) $this->argument('username');
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            if (preg_match(RegisterRequest::USERNAME_PATTERN, $username) !== 1) {
                $this->error('A username is 3 to 32 letters, digits, dots, dashes or underscores.');

                return self::FAILURE;
            }
            $password = $this->askPassword();
            if ($password === null) {
                return self::FAILURE;
            }
            $user = User::query()->create(['username' => $username, 'password' => $password, 'is_admin' => (bool) $this->option('admin')]);
            $user->defaultList();
            $this->info(($user->is_admin ? 'Admin account' : 'Account') . " {$user->username} created.");

            return self::SUCCESS;
        }

        if ($this->option('no-admin') && $user->isLastAdmin()) {
            $this->error("{$user->username} is the last admin. Make another admin first.");

            return self::FAILURE;
        }
        if ($this->option('admin') || $this->option('no-admin')) {
            $user->is_admin = (bool) $this->option('admin');
        }
        if ($this->option('password')) {
            $password = $this->askPassword();
            if ($password === null) {
                return self::FAILURE;
            }
            $user->password = $password;
            $user->tokens()->delete();
        }
        $user->save();
        $this->info("{$user->username} is " . ($user->is_admin ? 'an admin.' : 'an ordinary account.'));

        return self::SUCCESS;
    }

    /** A new password, typed twice; null (and why) if it won't do. */
    private function askPassword(): ?string
    {
        $password = (string) $this->secret('Password');
        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', ...RegisterRequest::passwordRules()]]);
        if ($validator->fails()) {
            $this->error('A password is 8 to 200 characters.');

            return null;
        }
        if ((string) $this->secret('The same password again') !== $password) {
            $this->error('The passwords don\'t match.');

            return null;
        }

        return $password;
    }
}
