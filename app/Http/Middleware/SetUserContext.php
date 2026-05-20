<?php

namespace App\Http\Middleware;

use App\Services\UserContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUserContext
{
    public function __construct(private UserContext $userContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $this->userContext->set($request->user());
        }

        return $next($request);
    }
}
