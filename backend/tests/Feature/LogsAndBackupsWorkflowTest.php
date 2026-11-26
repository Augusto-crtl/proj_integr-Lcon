<?php // Declara a suíte de integração isolada para auditoria e recuperação.

namespace Tests\Feature; // Organiza os testes de comportamento da aplicação.

use App\Models\Reading; // Permite preparar dados antes e depois dos backups.
use App\Models\User; // Permite criar contas administrativas e comuns.
use Illuminate\Support\Facades\Artisan; // Executa migrations e tarefas agendadas no teste.
use Illuminate\Support\Facades\Date; // Controla o relógio usado internamente pelo agendador Laravel.
use Illuminate\Support\Facades\DB; // Permite conferir dados restaurados no SQLite.
use Illuminate\Support\Facades\File; // Limpa apenas os artefatos temporários criados pelo teste.
use Illuminate\Support\Facades\Schedule; // Consulta eventos de agendamento registrados pela aplicação.
use Laravel\Sanctum\Sanctum; // Autentica usuários nos endpoints protegidos.
use Tests\TestCase as BaseTestCase; // Usa a inicialização de aplicação configurada pelo projeto.

class LogsAndBackupsWorkflowTest extends BaseTestCase // Verifica os fluxos completos sem usar dados persistentes do usuário.
{ // Inicia a classe de testes.
    private string $databasePath; // Guarda o caminho do banco SQLite descartável.
    private string $storagePath; // Guarda a pasta de armazenamento descartável.

    protected function setUp(): void // Prepara banco e armazenamento isolados antes de cada cenário.
    { // Inicia a preparação do teste.
        parent::setUp(); // Inicializa a aplicação Laravel para o teste atual.
        $this->databasePath = tempnam(sys_get_temp_dir(), 'lcon-workflow-'); // Cria um arquivo SQLite temporário fora do banco do projeto.
        $this->storagePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lcon-storage-'.bin2hex(random_bytes(8)); // Gera uma pasta temporária exclusiva.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->databasePath]); // Direciona a aplicação ao banco temporário.
        DB::purge('sqlite'); // Descarta qualquer conexão aberta com outra configuração.
        app()->useStoragePath($this->storagePath); // Direciona backups e logs de framework para armazenamento isolado.
        File::ensureDirectoryExists($this->storagePath); // Prepara a raiz temporária de armazenamento.
        Artisan::call('migrate:fresh'); // Cria o esquema completo somente no arquivo SQLite temporário.
    } // Finaliza a preparação do teste.

    protected function tearDown(): void // Remove somente os arquivos isolados deste teste.
    { // Inicia a limpeza do teste.
        Date::setTestNow(); // Restaura o relógio normal após cenários de agendamento.
        DB::purge('sqlite'); // Fecha a conexão antes de remover o banco temporário.
        if (isset($this->databasePath) && File::exists($this->databasePath)) { // Confirma que o banco temporário existe.
            File::delete($this->databasePath); // Apaga exclusivamente o banco criado por este teste.
        } // Finaliza a limpeza do banco temporário.
        if (isset($this->storagePath) && File::isDirectory($this->storagePath)) { // Confirma que o armazenamento temporário existe.
            File::deleteDirectory($this->storagePath); // Apaga somente a pasta exclusiva deste teste.
        } // Finaliza a limpeza do armazenamento temporário.
        parent::tearDown(); // Encerra o ciclo de vida Laravel do teste atual.
    } // Finaliza a limpeza do teste.

    public function test_audit_logs_record_user_action_and_ip_and_admin_routes_reject_regular_users(): void // Verifica auditoria e autorização efetivas.
    { // Inicia o cenário de auditoria e autorização.
        $user = User::factory()->create(); // Cria uma conta comum para emitir um comando auditável.
        Sanctum::actingAs($user); // Autentica a conta comum nos endpoints de usuário.
        $this->postJson('/api/commands/add', ['name' => 'setpoint', 'value' => 45])->assertCreated(); // Gera um evento de comando pelo fluxo normal.
        $this->getJson('/api/admin/logs')->assertForbidden(); // Confirma que conta comum não consulta logs.
        $this->getJson('/api/admin/backups')->assertForbidden(); // Confirma que conta comum não lista backups.
        $this->postJson('/api/admin/backups')->assertForbidden(); // Confirma que conta comum não cria backups.
        $this->postJson('/api/admin/restore', ['backup' => 'invalido.sqlite', 'confirmation' => true])->assertForbidden(); // Confirma que conta comum não restaura o banco.
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.queued', 'user_id' => $user->id]); // Confirma persistência do autor e da ação.
        $admin = User::factory()->create(['is_admin' => true]); // Cria uma conta administrativa para consultar os eventos.
        Sanctum::actingAs($admin); // Autentica a conta administrativa.
        $response = $this->getJson('/api/admin/logs')->assertOk()->assertJsonPath('logs.0.action', 'command.queued'); // Confirma que o administrador consulta o evento recente.
        $log = $response->json('logs.0'); // Obtém o evento exibido pela API administrativa.
        $this->assertSame($user->email, $log['user']['email']); // Confirma que o evento apresenta o usuário correto.
        $this->assertNotEmpty($log['ip_address']); // Confirma que o IP da sessão foi registrado e descriptografado.
        $this->assertNotEmpty($log['created_at']); // Confirma que o evento apresenta data e hora.
    } // Finaliza o cenário de auditoria e autorização.

    public function test_admin_backup_endpoint_lists_restores_data_and_preserves_safety_copy(): void // Verifica o ciclo administrativo de backup e restauração.
    { // Inicia o cenário de recuperação de dados.
        $admin = User::factory()->create(['is_admin' => true]); // Cria a conta autorizada para administrar backups.
        Sanctum::actingAs($admin); // Autentica a conta administrativa.
        Reading::create(['source' => 'teste', 'recorded_at' => now(), 'variables' => ['temp' => 25.0]]); // Insere o estado que será salvo no backup.
        $response = $this->postJson('/api/admin/backups')->assertCreated()->assertJsonPath('message', 'Backup criado com sucesso.'); // Cria backup pela API administrativa.
        $backupName = $response->json('backup.name'); // Guarda o nome retornado pela aplicação.
        $backupPath = storage_path('app/private/backups').DIRECTORY_SEPARATOR.$backupName; // Resolve o arquivo no armazenamento temporário configurado.
        $this->assertFileExists($backupPath); // Confirma que a API gravou um arquivo privado não vazio.
        $this->getJson('/api/admin/backups')->assertOk()->assertJsonPath('backups.0.name', $backupName); // Confirma que o backup aparece na listagem.
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.created', 'user_id' => $admin->id]); // Confirma a auditoria da criação antes da restauração do banco.
        Reading::create(['source' => 'teste', 'recorded_at' => now(), 'variables' => ['temp' => 99.0]]); // Acrescenta uma alteração descartável após o backup.
        $this->assertSame(2, DB::table('readings')->count()); // Confirma o estado alterado antes da restauração.
        $restore = $this->postJson('/api/admin/restore', ['backup' => $backupName, 'confirmation' => true])->assertOk()->assertJsonPath('backup', $backupName); // Restaura após confirmação explícita.
        $this->assertSame(1, DB::table('readings')->count()); // Confirma que a leitura criada após o backup foi removida.
        $safetyName = $restore->json('safety_backup'); // Obtém a cópia automática do estado anterior à restauração.
        $this->assertFileExists(storage_path('app/private/backups').DIRECTORY_SEPARATOR.$safetyName); // Confirma que o estado substituído também foi preservado.
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restore_succeeded', 'user_id' => $admin->id]); // Confirma que a restauração foi auditada.
    } // Finaliza o cenário de recuperação de dados.

    public function test_daily_backup_schedule_creates_a_backup_at_two_am(): void // Verifica o evento diário com relógio de teste.
    { // Inicia o cenário do agendador.
        Date::setTestNow('2026-11-27 05:00:00 UTC'); // Fixa o relógio às 02:00 no fuso de Brasília.
        $this->assertSame('02:00', Date::now()->setTimezone('America/Sao_Paulo')->format('H:i')); // Confirma o horário local usado pela equipe.
        $events = Schedule::events(); // Obtém a lista de tarefas agendadas pela aplicação.
        $this->assertCount(1, $events); // Confirma o registro de uma rotina diária de backup.
        $this->assertSame('0 2 * * *', $events[0]->getExpression()); // Confirma a frequência diária às 02:00.
        $this->assertTrue($events[0]->isDue(app())); // Confirma que a agenda vence no horário simulado em Brasília.
        $this->assertSame(0, Artisan::call('backup:sqlite')); // Executa no mesmo processo a tarefa chamada pela agenda.
        $this->assertDirectoryExists(storage_path('app/private/backups')); // Confirma que a tarefa criou o diretório de destinos.
        $backups = File::files(storage_path('app/private/backups')); // Lista os arquivos gravados pelo evento agendado.
        $this->assertCount(1, $backups); // Confirma que a tarefa diária executou o comando de backup.
        $this->assertGreaterThan(0, $backups[0]->getSize()); // Confirma que o arquivo agendado contém dados.
    } // Finaliza o cenário do agendador.
} // Finaliza a classe de testes.
