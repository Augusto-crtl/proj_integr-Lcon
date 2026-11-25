<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
        AuditLog::record($request, 'backup.restore_requested', 'user', $request->user()->id);

        return response()->json([
            'message' => 'Restore was logged, but no backup restore service is configured.',
        ], 503);
    }
}
