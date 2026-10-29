<?php

namespace App\Http\Middleware;

use App\PersonalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePersonalLogin
{
    public function __construct(private PersonalAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->access->authenticated($request), 401, 'Sign in to continue.');

        return $next($request);
    }
}
