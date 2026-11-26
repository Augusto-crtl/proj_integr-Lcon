<?php

use App\Http\Middleware\AuthenticateMatlab;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureRegistrationEnabled; // Controla o cadastro conforme a configuração de ambiente.
use App\Http\Middleware\RequireHttpsOutsideLocal; // Exige HTTPS fora do ambiente local.
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'matlab.token' => AuthenticateMatlab::class,
            'admin' => EnsureAdmin::class,
            'registration.enabled' => EnsureRegistrationEnabled::class, // Aplica a política à rota pública de cadastro.
        ]);
        $middleware->append(RequireHttpsOutsideLocal::class); // Protege API e páginas por transporte HTTPS em produção.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
