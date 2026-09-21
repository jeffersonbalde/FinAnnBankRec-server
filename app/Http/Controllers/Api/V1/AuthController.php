<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Log in with email + password and start a first-party session cookie.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, remember: true)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $request->user()->is_active) {
            $this->clearSession($request);

            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Please contact the administrator.'],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return UserResource::make($request->user())
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    /**
     * Change the authenticated user's password. Email cannot be changed here.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Destroy the current session.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->clearSession($request);

        return response()->json(['message' => 'Logged out.']);
    }

    private function clearSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
