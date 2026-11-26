<?php // Declara o arquivo PHP do serviço de backup SQLite.

namespace App\Services; // Organiza o serviço no namespace da aplicação.

use Illuminate\Support\Facades\DB; // Permite consultar o driver e o caminho do banco ativo.
use Illuminate\Support\Facades\File; // Fornece operações de arquivo compatíveis com Laravel.
use Illuminate\Support\Str; // Gera identificadores aleatórios para arquivos temporários.
use RuntimeException; // Representa falhas operacionais de backup e restauração.

class SqliteBackupService // Implementa cópias e restaurações do banco SQLite local.
{ // Inicia a definição do serviço.
    private const RETENTION_LIMIT = 30; // Mantém no máximo trinta cópias automáticas ou manuais.

    public function __construct() // Prepara o serviço com o diretório privado de backups.
    { // Inicia o construtor.
        $this->directory = storage_path('app/private/backups'); // Define o destino protegido dos arquivos de backup.
    } // Finaliza o construtor.

    private string $directory = ''; // Guarda o caminho do diretório de backups.

    public function create(): array // Cria um backup consistente e devolve seus metadados.
    { // Inicia a criação do backup.
        // Verifica driver e arquivo para não copiar bancos não suportados ou voláteis.
        $connection = DB::connection(); // Obtém a conexão de banco atualmente ativa.
        if ($connection->getDriverName() !== 'sqlite') { // Verifica se a conexão usa SQLite.
            throw new RuntimeException('O backup automático está configurado somente para SQLite.'); // Interrompe para evitar cópia inconsistente de outro banco.
        } // Finaliza a validação do driver.

        $databasePath = $connection->getDatabaseName(); // Lê o caminho efetivo do arquivo SQLite.
        if ($databasePath === ':memory:' || ! File::exists($databasePath)) { // Rejeita banco em memória ou arquivo inexistente.
            throw new RuntimeException('O arquivo do banco SQLite não está disponível para backup.'); // Informa que não há arquivo persistente copiável.
        } // Finaliza a validação do arquivo.

        if ($connection->getPdo()->inTransaction() && app()->environment('testing')) { // Evita cópia inconsistente dentro da transação isolada dos testes.
            throw new RuntimeException('Não é possível criar um backup durante uma transação ativa.'); // Solicita uma nova tentativa fora da transação.
        } // Finaliza a validação de transação.

        // Consolida WAL antes da cópia e publica o resultado somente após validá-lo.
        $connection->getPdo()->query('PRAGMA wal_checkpoint(FULL)'); // Consolida páginas WAL no arquivo principal antes da cópia.

        File::ensureDirectoryExists($this->directory); // Cria o diretório privado se ainda não existir.
        $name = now()->format('Y-m-d_H-i-s').'_'.Str::lower(Str::random(6)).'.sqlite'; // Cria nome único com data e identificador.
        $destination = $this->directory.DIRECTORY_SEPARATOR.$name; // Monta o caminho final do novo backup.
        $temporary = $destination.'.tmp'; // Define um caminho temporário para gravação atômica.

        if (! copy($databasePath, $temporary)) { // Copia o arquivo ativo para o caminho temporário.
            throw new RuntimeException('Não foi possível gravar o arquivo de backup.'); // Falha sem publicar uma cópia incompleta.
        } // Finaliza a verificação da cópia temporária.

        if (filesize($temporary) === 0 || ! rename($temporary, $destination)) { // Confirma conteúdo e publica o arquivo com renomeação atômica.
            File::delete($temporary); // Remove eventual arquivo temporário inválido.
            throw new RuntimeException('O arquivo de backup ficou incompleto e foi descartado.'); // Sinaliza que o backup não pôde ser concluído.
        } // Finaliza a publicação do backup.

        $this->prune(); // Remove as cópias mais antigas quando o limite de retenção é excedido.
        return $this->metadata($destination); // Devolve os dados necessários para mostrar a cópia criada.
    } // Finaliza a criação do backup.

    public function list(): array // Lista backups locais válidos em ordem decrescente de data.
    { // Inicia a listagem dos backups.
        if (! File::isDirectory($this->directory)) { // Trata o caso em que nenhum backup foi criado.
            return []; // Devolve uma lista vazia para a interface administrativa.
        } // Finaliza o caso sem diretório de backups.

        return collect(File::files($this->directory)) // Obtém os arquivos existentes no diretório privado.
            ->filter(fn ($file): bool => $file->getExtension() === 'sqlite' && $file->getSize() > 0) // Mantém somente arquivos SQLite não vazios.
            ->sortByDesc(fn ($file): int => $file->getMTime()) // Ordena os backups do mais recente para o mais antigo.
            ->map(fn ($file): array => $this->metadata($file->getPathname())) // Converte cada arquivo em metadados seguros.
            ->values() // Reindexa os itens como uma lista JSON.
            ->all(); // Retorna os metadados como array PHP.
    } // Finaliza a listagem dos backups.

    public function restore(string $name): array // Restaura somente um arquivo previamente listado pelo nome.
    { // Inicia a restauração do backup selecionado.
        // Aceita somente nomes gerados pelo serviço e caminhos dentro da pasta privada.
        $connection = DB::connection(); // Obtém a conexão de banco atualmente ativa.
        if ($connection->getDriverName() !== 'sqlite') { // Verifica se a conexão usa SQLite.
            throw new RuntimeException('A restauração automática está configurada somente para SQLite.'); // Impede substituir bancos de outros drivers por engano.
        } // Finaliza a validação do driver.

        if (! preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[a-z0-9]{6}\.sqlite$/', $name)) { // Restringe o nome a backups gerados pelo serviço.
            throw new RuntimeException('O backup selecionado não é válido.'); // Rejeita caminhos e nomes arbitrários.
        } // Finaliza a validação do nome.

        $source = $this->directory.DIRECTORY_SEPARATOR.$name; // Constrói o caminho dentro do diretório fixo de backups.
        if (! File::exists($source) || filesize($source) === 0) { // Verifica existência e conteúdo do backup selecionado.
            throw new RuntimeException('O backup selecionado não foi encontrado ou está vazio.'); // Informa que o arquivo não pode ser restaurado.
        } // Finaliza a validação do arquivo selecionado.

        $databasePath = $connection->getDatabaseName(); // Lê o caminho do banco SQLite ativo.
        if ($databasePath === ':memory:' || ! File::exists($databasePath)) { // Rejeita caminhos sem arquivo persistente.
            throw new RuntimeException('O arquivo do banco SQLite não está disponível para restauração.'); // Evita uma substituição sem destino válido.
        } // Finaliza a validação do banco de destino.

        // Protege o estado atual, fecha a conexão e troca o banco por meio de arquivo temporário.
        $safetyBackup = $this->create(); // Salva o estado atual para permitir recuperação após a operação.
        DB::purge(); // Fecha a conexão antes de substituir o arquivo no Windows.
        $temporary = $databasePath.'.restore-'.Str::lower(Str::random(8)); // Prepara arquivo temporário no mesmo volume do banco.

        try { // Garante que toda tentativa restaure a conexão antes de sair do método.
            if (! copy($source, $temporary)) { // Copia o backup selecionado sem alterar ainda o banco ativo.
                throw new RuntimeException('Não foi possível preparar o backup selecionado.'); // Informa a falha ao painel.
            } // Finaliza a validação da cópia temporária.

            if (! rename($temporary, $databasePath)) { // Troca o banco ativo pela cópia por meio de renomeação.
                throw new RuntimeException('Não foi possível substituir o banco pela cópia selecionada.'); // Informa a falha sem ocultar o resultado.
            } // Finaliza a substituição do banco.
        } finally { // Executa a limpeza e reconexão em sucesso ou falha.
            File::delete($temporary); // Remove qualquer temporário restante após a operação.
            DB::reconnect(); // Reconecta o Laravel ao banco que ficou ativo.
        } // Finaliza a recuperação da conexão.

        return ['restored' => $name, 'safety_backup' => $safetyBackup['name']]; // Informa qual cópia foi aplicada e qual protege o estado anterior.
    } // Finaliza a restauração.

    private function metadata(string $path): array // Produz metadados públicos sem revelar caminhos internos.
    { // Inicia a conversão dos metadados.
        return [ // Devolve somente campos apropriados para a interface.
            'name' => basename($path), // Expõe o nome validado do arquivo, sem o caminho absoluto.
            'size' => filesize($path), // Informa o tamanho em bytes para contexto administrativo.
            'created_at' => date(DATE_ATOM, filemtime($path)), // Formata a data do arquivo em padrão interoperável.
        ]; // Finaliza o objeto de metadados.
    } // Finaliza a conversão dos metadados.

    private function prune(): void // Aplica o limite de retenção aos arquivos mais antigos.
    { // Inicia a limpeza de retenção.
        // Mantém os 30 backups mais recentes e exclui apenas os arquivos excedentes.
        $files = collect(File::files($this->directory)) // Lê os arquivos armazenados no diretório de backups.
            ->filter(fn ($file): bool => $file->getExtension() === 'sqlite') // Considera apenas os backups SQLite definitivos.
            ->sortByDesc(fn ($file): int => $file->getMTime()) // Mantém os backups mais novos primeiro.
            ->values(); // Normaliza os índices para cálculo do limite.

        $files->slice(self::RETENTION_LIMIT) // Seleciona apenas arquivos além do limite configurado.
            ->each(fn ($file) => File::delete($file->getPathname())); // Remove cada backup excedente.
    } // Finaliza a aplicação da retenção.
} // Finaliza a definição do serviço.
