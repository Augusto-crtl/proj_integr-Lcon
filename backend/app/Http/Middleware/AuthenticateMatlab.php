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
        $previousTokens = config('services.matlab.previous_tokens', []); // Lê credenciais antigas para permitir rotação sem parada imediata do cliente.
        $providedToken = $request->bearerToken();

        if ($expectedToken === '') {
            return response()->json(['message' => 'MATLAB service authentication is not configured.'], 503);
        }

        $acceptedTokens = array_filter([$expectedToken, ...$previousTokens]); // Reúne apenas tokens configurados no ambiente do servidor.
        $authenticated = $providedToken !== null && collect($acceptedTokens)->contains(fn (string $token): bool => hash_equals($token, $providedToken)); // Compara credenciais em tempo constante.

        if (! $authenticated) {
            return response()->json(['message' => 'Unauthenticated MATLAB service.'], 401);
        }

        return $next($request);
    }
}
