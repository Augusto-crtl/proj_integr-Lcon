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
            'variables.temp' => ['required', 'numeric'],
            'variables.system_status' => ['sometimes', 'in:ON,OFF'],
            'variables.setpoint' => ['sometimes', 'numeric', 'between:20,99'],
        ]);

        $reading = Reading::create($validated);

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
                'source' => $reading->source,
                'recorded_at' => $reading->recorded_at->toISOString(),
                'received_at' => $reading->created_at->toISOString(), // Informa quando o Laravel recebeu a leitura, sem depender do relógio MATLAB.
                'variables' => $reading->variables,
            ]);

        return response()->json($readings);
    }
}
