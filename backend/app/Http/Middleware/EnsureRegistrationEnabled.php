<?php // Declara o middleware que controla o cadastro público.

namespace App\Http\Middleware; // Organiza o middleware HTTP da aplicação.

use Closure; // Permite continuar o fluxo da requisição.
use Illuminate\Http\Request; // Fornece acesso à requisição recebida.
use Symfony\Component\HttpFoundation\Response; // Define o tipo de resposta HTTP retornada.

class EnsureRegistrationEnabled // Impede cadastro público quando a implantação o desabilita.
{ // Inicia a classe do middleware.
    public function handle(Request $request, Closure $next): Response // Avalia a política antes de executar a rota.
    { // Inicia o tratamento da requisição.
        if (! config('services.registration.enabled', true)) { // Consulta a opção centralizada em configuração.
            return response()->json(['message' => 'O cadastro público está desabilitado.'], 403); // Nega cadastro quando a política estiver fechada.
        } // Finaliza a validação da política.

        return $next($request); // Continua para a rota quando o cadastro está permitido.
    } // Finaliza o tratamento da requisição.
} // Finaliza a classe do middleware.
