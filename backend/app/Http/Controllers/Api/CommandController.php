<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ControlCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommandController extends Controller
{
    /** Valida limites, enfileira a solicitação do usuário e audita sua origem. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'in:setpoint,flag'],
            'value' => ['required', 'numeric'],
        ]);
        $value = (float) $validated['value'];

        if (
            ($validated['name'] === 'setpoint' && ($value < 20 || $value > 99))
            || ($validated['name'] === 'flag' && ! in_array($value, [0.0, 1.0], true))
        ) {
            return response()->json([
                'message' => $validated['name'] === 'setpoint'
                    ? 'Setpoint must be between 20 and 99 C.'
                    : 'Flag must be 0 (OFF) or 1 (ON).',
                'errors' => ['value' => ['The value is outside the allowed range.']],
            ], 422);
        }

        $command = ControlCommand::create([
            'name' => $validated['name'],
            'value' => $value,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);
        AuditLog::record($request, 'command.queued', 'user', $request->user()->id, [
            'command_id' => $command->id,
            'name' => $command->name,
        ]);

        return response()->json(['id' => $command->id, 'status' => $command->status], 201);
    }

    /** Entrega ao MATLAB comandos pendentes em ordem e inclui o usuário que os iniciou. */
    public function pending(): JsonResponse
    {
        $commands = ControlCommand::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'name', 'value'])
            ->map(fn (ControlCommand $command): array => [
                'id' => $command->id,
                'name' => $command->name,
                'value' => (float) $command->value,
                'user_id' => $command->created_by, // Informa ao cliente de aquisição o usuário associado à sessão de controle.
            ]);

        return response()->json(['commands' => $commands]);
    }

    /** Confirma o comando em transação para impedir aplicação duplicada concorrente. */
    public function acknowledge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'status' => ['required', 'in:applied,rejected'],
            'reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $validated): JsonResponse {
            $command = ControlCommand::query()
                ->lockForUpdate()
                ->find($validated['id']);

            if ($command === null) {
                return response()->json(['message' => 'Command not found.'], 404);
            }

            if ($command->status !== 'pending') {
                return response()->json(['message' => 'Command has already been acknowledged.'], 409);
            }

            $command->update([
                'status' => $validated['status'],
                'reason' => $validated['reason'] ?? null,
                'acknowledged_at' => now(),
            ]);
            AuditLog::record($request, 'command.'.$command->status, 'matlab', metadata: [
                'command_id' => $command->id,
                'reason' => $command->reason,
            ]);

            return response()->json(['id' => $command->id, 'status' => $command->status]);
        });
    }
}
