<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\SqliteBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function logs(): JsonResponse
    {
        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'actor' => $log->actor,
                'action' => $log->action,
                'ip_address' => $log->ip_address,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toISOString(),
                'user' => $log->user === null ? null : [
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ],
            ]);

        return response()->json(['logs' => $logs]);
    }

    public function restore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'backup' => ['required', 'string', 'max:100'], // Exige a identificação explícita do backup escolhido.
            'confirmation' => ['required', 'accepted'], // Exige confirmação deliberada antes de substituir o banco.
        ]);

        try {
            $result = app(SqliteBackupService::class)->restore($validated['backup']); // Restaura o backup validado e preserva o estado atual.
            AuditLog::record($request, 'backup.restore_succeeded', 'user', $request->user()->id, $result); // Registra a restauração concluída e a cópia de segurança.
            return response()->json(['message' => 'Backup restaurado com sucesso.', 'backup' => $result['restored'], 'safety_backup' => $result['safety_backup']]); // Confirma o resultado ao painel.
        } catch (\RuntimeException $exception) {
            AuditLog::record($request, 'backup.restore_failed', 'user', $request->user()->id, ['reason' => $exception->getMessage()]); // Registra a falha sem ocultar a causa operacional.
            return response()->json(['message' => $exception->getMessage()], 422); // Informa por que a restauração não pôde prosseguir.
        }
    }

    public function backups(): JsonResponse
    {
        try {
            return response()->json(['backups' => app(SqliteBackupService::class)->list()]); // Devolve a lista segura dos backups disponíveis.
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422); // Informa quando o driver configurado não é compatível.
        }
    }

    public function createBackup(Request $request): JsonResponse
    {
        try {
            $backup = app(SqliteBackupService::class)->create(); // Cria uma cópia manual do banco SQLite atual.
            AuditLog::record($request, 'backup.created', 'user', $request->user()->id, ['backup' => $backup['name']]); // Registra a criação manual para auditoria.
            return response()->json(['message' => 'Backup criado com sucesso.', 'backup' => $backup], 201); // Confirma a criação e informa o arquivo.
        } catch (\RuntimeException $exception) {
            AuditLog::record($request, 'backup.create_failed', 'user', $request->user()->id, ['reason' => $exception->getMessage()]); // Registra a falha da tentativa de backup.
            return response()->json(['message' => $exception->getMessage()], 422); // Devolve o motivo da falha ao painel.
        }
    }
}
