<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\WordPressPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Sign in with email OR username (like WordPress).
     * Users imported from WordPress keep their old password: the WordPress hash is verified
     * once and immediately replaced by a modern bcrypt hash.
     */
    public function login(Request $request): JsonResponse
    {
        // "email" is still accepted for older admin builds
        if (! $request->filled('login') && $request->filled('email')) {
            $request->merge(['login' => $request->input('email')]);
        }

        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $login = trim($credentials['login']);
        $user = User::query()
            ->where(fn ($q) => $q->where('email', mb_strtolower($login))->orWhere('username', $login))
            ->first();

        if (! $user) {
            Hash::check($credentials['password'], Hash::make(Str::random(40))); // same timing as a real check
        }

        if (! $user || ! $this->passwordMatches($credentials['password'], $user)) {
            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        if (! $user->canAccessAdmin()) {
            throw ValidationException::withMessages(['login' => 'This account is not allowed to use the admin panel.']);
        }

        // Upgrade WordPress (or outdated bcrypt) hashes to the current Laravel hash
        if (WordPressPassword::isWordPressHash($user->password) || Hash::needsRehash($user->password)) {
            $user->password = $credentials['password'];
        }
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        $token = $user->createToken(
            'admin-'.substr((string) $request->userAgent(), 0, 60),
            ['admin'],
            now()->addMinutes((int) config('sanctum.expiration', 480)),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $user->only(['id', 'name', 'username', 'email']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->only(['id', 'name', 'username', 'email'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'confirmed', 'max:255', Password::defaults()],
        ]);

        $user = $request->user();
        if (! $this->passwordMatches($data['current_password'], $user)) {
            throw ValidationException::withMessages(['current_password' => __('validation.current_password')]);
        }

        $user->password = $data['password'];
        $user->save();

        // Sign out every other device
        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

        return response()->json(['message' => 'Password updated']);
    }

    private function passwordMatches(string $plain, User $user): bool
    {
        $hash = (string) $user->password;

        if (WordPressPassword::isWordPressHash($hash)) {
            return WordPressPassword::check($plain, $hash);
        }

        if (str_starts_with($hash, '$2y$')) {
            return Hash::check($plain, $hash);
        }

        if (str_starts_with($hash, '$argon2')) {
            return password_verify($plain, $hash);
        }

        // Unknown/empty hash: never allow login, but spend similar time (no user enumeration by timing)
        Hash::check($plain, Hash::make(Str::random(40)));

        return false;
    }
}
