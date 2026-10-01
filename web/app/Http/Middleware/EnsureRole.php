<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(
        Request $request,
        Closure $next,
        string $role
    ): Response {
        $profile = $request->attributes->get('current_user');

        if (! $profile || ! $profile->role) {
            abort(403, 'Unauthorized.');
        }

        if ($profile->role->code !== $role) {
            abort(403, 'You are not authorized to access this area.');
        }

        return $next($request);
    }
}