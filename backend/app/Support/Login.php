<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Signing a browser in and out of its session. Signing in moves the session
 * to a new id, so an id planted before it (session fixation) signs no one in;
 * with "keep me signed in", a remember cookie starts a new session for 30 days
 * after this one ends (config/session.php, auth.guards.web.remember).
 */
final class Login
{
    public static function start(User $user, bool $remember): void
    {
        self::guard()->login($user, $remember);
    }

    /**
     * Sign this browser out. Only this one: its remember cookie is deleted, and
     * the account's other browsers stay signed in.
     */
    public static function end(Request $request): void
    {
        self::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** Whether this browser was kept signed in: it sent a remember cookie. */
    public static function remembered(Request $request): bool
    {
        return $request->cookies->has(self::guard()->getRecallerName());
    }

    private static function guard(): SessionGuard
    {
        $guard = Auth::guard('web');

        return $guard instanceof SessionGuard ? $guard : throw new LogicException('The web guard keeps a session.');
    }
}
