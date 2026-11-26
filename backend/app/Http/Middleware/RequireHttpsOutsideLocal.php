<?php // Declara o middleware que exige transporte HTTPS fora do ambiente local.

namespace App\Http\Middleware; // Organiza o middleware HTTP da aplicação.

use Closure; // Permite continuar o fluxo da requisição.
use Illuminate\Http\Request; // Fornece acesso ao esquema e aos cabeçalhos recebidos.
use Symfony\Component\HttpFoundation\Response; // Define o tipo de resposta HTTP retornada.

class RequireHttpsOutsideLocal // Rejeita credenciais enviadas por uma conexão HTTP sem TLS.
{ // Inicia a classe do middleware.
    public function handle(Request $request, Closure $next): Response // Verifica HTTPS antes de executar a aplicação.
    { // Inicia o tratamento da requisição.
        if (! app()->environment('local', 'testing') && ! $request->isSecure()) { // Restringe a exigência aos ambientes não locais.
            return response()->json(['message' => 'A conexão HTTPS é obrigatória neste ambiente.'], 400); // Evita processar tokens em conexão sem TLS.
        } // Finaliza a validação de transporte.

        return $next($request); // Continua quando HTTPS é usado ou o ambiente é local/de teste.
    } // Finaliza o tratamento da requisição.
} // Finaliza a classe do middleware.
