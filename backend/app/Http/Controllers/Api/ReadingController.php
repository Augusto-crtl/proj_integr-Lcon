<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:100'],
            'recorded_at' => ['required', 'date'],
            'variables' => ['required', 'array'],
            'variables.user_id' => ['nullable', 'integer', 'exists:users,id'], // Valida a conta humana que iniciou a sessão de controle no MATLAB.
            'variables.temp' => ['required', 'numeric'],
            'variables.system_status' => ['sometimes', 'in:ON,OFF'],
            'variables.setpoint' => ['sometimes', 'numeric', 'between:20,99'],
        ]);

        $userId = $validated['variables']['user_id'] ?? null; // Obtém o usuário da sessão autenticada pelo fluxo de comandos.
        unset($validated['variables']['user_id']); // Remove o identificador auxiliar das variáveis físicas criptografadas.
        $reading = Reading::create($validated + ['user_id' => $userId]); // Persiste usuário, origem, horário e variáveis da aquisição.

        return response()->json(['id' => $reading->id], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,500'],
        ]);
        $limit = $validated['limit'] ?? 60;

        $readings = Reading::query()
            ->latest('recorded_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Reading $reading): array => [
                'id' => $reading->id,
                'user_id' => $reading->user_id, // Expõe o usuário relacionado à leitura no histórico autorizado.
                'source' => $reading->source,
                'recorded_at' => $reading->recorded_at->toISOString(),
                'received_at' => $reading->created_at->toISOString(), // Informa quando o Laravel recebeu a leitura, sem depender do relógio MATLAB.
                'variables' => $reading->variables,
            ]);

        return response()->json($readings);
    }
}
