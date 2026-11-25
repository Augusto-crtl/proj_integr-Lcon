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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        AuditLog::record($request, 'auth.logged_out', 'user', $request->user()->id);

        return response()->json(['message' => 'Logged out.']);
    }

    private function issueToken(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('web-dashboard')->plainTextToken,
            'user' => $this->userPayload($user),
        ], $status);
    }

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
