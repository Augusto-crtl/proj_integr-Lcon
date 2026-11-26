<?php // Declara uma migration incremental para adicionar o vínculo de usuário às leituras existentes.

use Illuminate\Database\Migrations\Migration; // Fornece a classe base de migrations do Laravel.
use Illuminate\Database\Schema\Blueprint; // Define a coluna e a chave estrangeira da tabela.
use Illuminate\Support\Facades\Schema; // Executa alterações estruturais no banco de dados.

return new class extends Migration // Cria uma migration anônima reconhecida pelo Laravel.
{ // Inicia a definição da migration.
    public function up(): void // Aplica a alteração necessária para o modelo atual.
    { // Inicia a migração para frente.
        if (! Schema::hasColumn('readings', 'user_id')) { // Evita duplicar a coluna em instalações novas cuja migration inicial já a criou.
            Schema::table('readings', function (Blueprint $table): void { // Altera a tabela sem remover as leituras existentes.
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete(); // Adiciona vínculo opcional para manter leituras históricas válidas.
            }); // Finaliza a alteração condicional da tabela.
        } // Finaliza a verificação da coluna.
    } // Finaliza a migração para frente.

    public function down(): void // Reverte somente a coluna adicionada por esta migration.
    { // Inicia a reversão.
        if (Schema::hasColumn('readings', 'user_id')) { // Confirma a existência da coluna antes de reverter a alteração.
            Schema::table('readings', function (Blueprint $table): void { // Abre a tabela para remover a alteração.
                $table->dropConstrainedForeignId('user_id'); // Remove chave estrangeira e coluna caso seja necessário reverter.
            }); // Finaliza a reversão condicional da tabela.
        } // Finaliza a verificação para reversão.
    } // Finaliza a reversão.
}; // Finaliza a migration.
