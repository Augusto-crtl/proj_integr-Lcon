# Verificação de logs e backups

## Escopo

Os cenários automatizados usam um arquivo SQLite temporário e uma pasta de armazenamento exclusiva sob a pasta temporária do sistema. Eles não acessam `backend/database/database.sqlite` nem gravam em `backend/storage/app/private/backups`. Cada cenário remove apenas seus próprios artefatos ao terminar.

## Resultados verificados

Executado em 26/11/2026 com `php artisan test --filter=LogsAndBackupsWorkflowTest`, a partir de `backend/`:

| Verificação | Resultado |
| --- | --- |
| Usuário comum envia comando e o evento fica associado ao usuário, com IP e horário | Aprovado |
| Usuário comum tenta consultar logs, listar/criar backups e restaurar | Todas as rotas administrativas responderam 403 |
| Administrador consulta evento auditado | Aprovado; o evento retornou usuário, IP e data/hora |
| API administrativa cria e lista backup SQLite | Aprovado; arquivo não vazio presente no diretório temporário privado |
| Restauração remove alteração posterior ao snapshot | Aprovado; a tabela `readings` voltou de duas linhas para uma |
| Cópia de segurança do estado imediatamente anterior à restauração | Aprovado; o arquivo da cópia de segurança foi encontrado |
| Evento diário de backup | Aprovado; evento vence às 02:00 em `America/Sao_Paulo` no relógio simulado e o comando-alvo `backup:sqlite` criou um arquivo válido |

Resultado da suíte específica: **3 testes aprovados, 31 assertions**. A suíte completa foi executada com `php artisan test`: **14 testes aprovados, 77 assertions**.

`php artisan schedule:list` também foi consultado. O Laravel apresentou `0 5 * * *`, que corresponde a 02:00 em Brasília convertidas para UTC, e listou a próxima execução. Esse comando confirma o registro da agenda; o worker persistente ainda precisa estar ativo no host da demonstração.

O backup é um snapshot integral do banco. Ao restaurar um backup antigo, eventos registrados depois do snapshot deixam de existir no banco restaurado; o evento de sucesso da restauração é gravado em seguida nesse banco. Por isso, o teste confere o evento `backup.created` antes da restauração e o evento `backup.restore_succeeded` depois dela.

## Verificação operacional no ambiente de desenvolvimento

1. Acesse o painel com a conta administradora e abra **Admin (Logs/Backup)**. Gere um comando como usuário comum, saia e confira o evento, o usuário/ator, o endereço IP e o horário.
2. Com token de uma conta comum, chame `GET /api/admin/logs`, `GET /api/admin/backups`, `POST /api/admin/backups` e `POST /api/admin/restore`. As rotas devem responder 403. O teste automatizado cobre as quatro ações.
3. Como administrador, crie um backup pela interface. Confira se aparece na lista e se foi criado um arquivo `.sqlite` em `backend/storage/app/private/backups`.
4. Em uma base descartável, crie o backup, faça uma alteração, restaure o backup escolhido e confirme que a alteração desapareceu. Confira também se uma segunda cópia foi criada para proteger o estado substituído.
5. Confira a agenda com `php artisan schedule:list`. Para manter o scheduler ativo localmente, deixe `php artisan schedule:work` rodando em outro terminal, a partir da pasta `backend`. Após as 02:00 de Brasília, confirme no terminal a execução do comando e no diretório de backups a criação de um arquivo novo.

Os testes automatizados confirmam configuração, autorização e funcionamento do comando em ambiente isolado. Eles não mantêm `schedule:work` ativo por 24 horas; a execução diária do processo no computador usado para a demonstração depende de iniciar esse worker (ou configurar um agendador do sistema operacional) e observar a próxima ocorrência.
