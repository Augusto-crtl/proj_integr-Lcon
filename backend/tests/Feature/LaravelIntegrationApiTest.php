<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaravelIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.matlab.token' => 'test-matlab-service-token']);
    }

    public function test_matlab_readings_are_persisted_and_returned_in_dashboard_contract(): void
    {
        $this->withToken('test-matlab-service-token')
            ->postJson('/api/readings', [
                'source' => 'matlab-ni-usb-6003',
                'recorded_at' => '2026-11-25T19:00:00.000Z',
                'variables' => [
                    'temp' => 25.4,
                    'system_status' => 'OFF',
                    'setpoint' => 30,
                ],
            ])
            ->assertCreated()
            ->assertJsonStructure(['id']);

        $this->assertDatabaseCount('readings', 1);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/readings/history?limit=60')
            ->assertOk()
            ->assertJsonPath('0.source', 'matlab-ni-usb-6003')
            ->assertJsonPath('0.variables.temp', 25.4)
            ->assertJsonPath('0.variables.system_status', 'OFF');
    }

    public function test_control_commands_are_queued_until_matlab_acknowledges_them(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $command = $this->postJson('/api/commands/add', [
            'name' => 'setpoint',
            'value' => 45,
        ])->assertCreated()->json('id');

        $this->withToken('test-matlab-service-token')
            ->getJson('/api/commands')
            ->assertOk()
            ->assertJsonPath('commands.0.id', $command)
            ->assertJsonPath('commands.0.name', 'setpoint')
            ->assertJsonPath('commands.0.value', 45);

        $this->withToken('test-matlab-service-token')
            ->postJson('/api/commands/ack', ['id' => $command, 'status' => 'applied'])
            ->assertOk()
            ->assertJsonPath('status', 'applied');

        $this->withToken('test-matlab-service-token')
            ->getJson('/api/commands')
            ->assertOk()
            ->assertExactJson(['commands' => []]);
    }

    public function test_service_token_and_admin_routes_enforce_separate_authorization(): void
    {
        $this->postJson('/api/readings', [
            'source' => 'matlab-ni-usb-6003',
            'recorded_at' => now()->toISOString(),
            'variables' => ['temp' => 25],
        ])->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/logs')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/restore')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Restore was logged, but no backup restore service is configured.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restore_requested']);
    }

    public function test_registered_user_receives_revocable_bearer_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secure-password',
        ])->assertCreated()
            ->assertJsonPath('user.role', 'user');

        $token = $response->json('token');

        $this->withToken($token)->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'test@example.com');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
