<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Usage: ->middleware('permission:reports.view-team')
 *        ->middleware('permission:reports.view-own|reports.view-team')  (any of)
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions)
    {
        $user = $request->user();
        $any = explode('|', $permissions);

        abort_unless($user && $user->hasAnyPermission(...$any), 403, 'You do not have permission to do this.');

        return $next($request);
    }
}
