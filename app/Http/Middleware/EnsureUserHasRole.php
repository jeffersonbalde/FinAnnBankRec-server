<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow the request only when the authenticated user holds one of the
     * given roles. Usage: ->middleware('role:admin') or 'role:admin,financial_analyst'.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_active) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        }

        $allowed = array_map(
            fn (string $role): UserRole => UserRole::from($role),
            $roles
        );

        if ($allowed !== [] && ! $user->hasRole(...$allowed)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
