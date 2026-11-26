<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /** Valida, cria uma conta comum, registra auditoria e emite token Sanctum. */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create($validated);
        AuditLog::record($request, 'auth.registered', 'user', $user->id);

        return $this->issueToken($user, 201);
    }

    /** Confere a senha, registra o resultado da autenticação e emite a sessão. */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            AuditLog::record(
                $request,
                'auth.login_failed',
                'user',
                metadata: ['email' => $validated['email']],
            );

            return response()->json(['message' => 'Invalid email or password.'], 422);
        }

        AuditLog::record($request, 'auth.login_succeeded', 'user', $user->id);

        return $this->issueToken($user);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /** Revoga o token atual para encerrar somente a sessão autenticada nesta chamada. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        AuditLog::record($request, 'auth.logged_out', 'user', $request->user()->id);

        return response()->json(['message' => 'Logged out.']);
    }

    /** Monta a resposta comum de autenticação para cadastro e login. */
    private function issueToken(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('web-dashboard')->plainTextToken,
            'user' => $this->userPayload($user),
        ], $status);
    }

    /** Expõe apenas os campos de perfil necessários ao cliente. */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role(),
        ];
    }
}
