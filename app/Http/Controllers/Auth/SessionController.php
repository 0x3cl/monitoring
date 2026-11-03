<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Session authentication for the dashboard.
 *
 * The Vue application is served from this same origin, so Laravel's session
 * guard and CSRF protection cover it without any additional token mechanism.
 */
class SessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Accepts an email address or a plain username: both live in the
        // users table's email column, so the guard needs no special casing.
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            (bool) ($credentials['remember'] ?? false)
        )) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return new JsonResponse(['data' => $this->userPayload($request)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return new JsonResponse(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->userPayload($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function userPayload(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->isAdmin(),
        ];
    }
}
