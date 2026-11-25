<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMatlab
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.matlab.token');
        $providedToken = $request->bearerToken();

        if ($expectedToken === '') {
            return response()->json(['message' => 'MATLAB service authentication is not configured.'], 503);
        }

        if ($providedToken === null || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json(['message' => 'Unauthenticated MATLAB service.'], 401);
        }

        return $next($request);
    }
}
