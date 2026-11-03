<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// gallery:user: how the first admin is made, and how an admin who lost their
// password gets back in.

test('it makes an admin account, asking for the password twice', function (): void {
    command('gallery:user Owner --admin')
        ->expectsQuestion('Password', 'long-enough')
        ->expectsQuestion('The same password again', 'long-enough')
        ->expectsOutput('Admin account Owner created.')
        ->assertSuccessful();

    $user = User::query()->where('username', 'owner')->firstOrFail();
    expect($user->is_admin)->toBeTrue()
        ->and(Hash::check('long-enough', $user->password))->toBeTrue()
        ->and($user->favoriteLists()->where('is_default', true)->exists())->toBeTrue();
});

test('it refuses a bad username, a short password, or two that differ', function (): void {
    command('gallery:user "no spaces"')->expectsOutput('A username is 3 to 32 letters, digits, dots, dashes or underscores.')->assertFailed();
    command('gallery:user owner')->expectsQuestion('Password', 'short')->expectsOutput('A password is 8 to 200 characters.')->assertFailed();
    command('gallery:user owner')
        ->expectsQuestion('Password', 'long-enough')
        ->expectsQuestion('The same password again', 'different-one')
        ->expectsOutput('The passwords don\'t match.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('it makes an account an admin or not, but keeps one admin', function (): void {
    $user = User::factory()->create(['username' => 'reader']);

    command('gallery:user READER --admin')->expectsOutput('reader is an admin.')->assertSuccessful();
    command('gallery:user reader --no-admin')->expectsOutput('reader is the last admin. Make another admin first.')->assertFailed();
    expect($user->refresh()->is_admin)->toBeTrue();
});

test('it sets a new password and signs the account out everywhere', function (): void {
    $user = User::factory()->create(['username' => 'reader']);
    $user->issueToken();

    command('gallery:user reader --password')
        ->expectsQuestion('Password', 'brand-new-one')
        ->expectsQuestion('The same password again', 'brand-new-one')
        ->assertSuccessful();

    expect(Hash::check('brand-new-one', $user->refresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});
