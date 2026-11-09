<?php

namespace App\Http\Middleware;

use App\Http\Controllers\StaffSessionController;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->active || $request->session()->get('staff_access_version') !== $user->access_version) {
            return app(StaffSessionController::class)->destroy($request);
        }

        return $next($request);
    }
}
