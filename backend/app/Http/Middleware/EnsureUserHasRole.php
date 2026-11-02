<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Usage: ->middleware('role:super_admin,deliverability_manager')
     * A viewer never passes a role gate for an operational change.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! empty($roles) && ! $user->hasRole(...$roles)) {
            return response()->json([
                'message' => 'This action requires a higher permission level.',
                'required_roles' => $roles,
            ], 403);
        }

        return $next($request);
    }
}
