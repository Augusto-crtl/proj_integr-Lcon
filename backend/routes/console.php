<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\SqliteBackupService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('backup:sqlite', function (SqliteBackupService $backups) { // Define um comando operacional para criar backup SQLite.
    $backup = $backups->create(); // Cria a cópia consistente pelo serviço compartilhado com o painel.
    $this->info('Backup criado: '.$backup['name']); // Exibe o identificador do arquivo criado no terminal.
})->purpose('Cria um backup do banco SQLite configurado'); // Documenta o propósito do comando Artisan.

Schedule::command('backup:sqlite')->daily()->at('02:00')->withoutOverlapping(); // Agenda backup diário às 2h e evita execuções simultâneas.
