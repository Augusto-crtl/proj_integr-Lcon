# Projeto Integrador — Laboratório de Sistemas de Controle

Aplicação de monitoramento e controle do Kit LTR701, com painel web, API Laravel e cliente MATLAB para aquisição e controle com hardware NI. O repositório também oferece simulações locais no navegador. A integração completa com o NI USB-6003 e o simulador físico ainda precisa ser validada no laboratório.

## Arquitetura

- `frontend/index.html` contém a interface React e é servido pelo Laravel; os recursos auxiliares ficam em `frontend/js/`.
- `backend/` contém a aplicação Laravel 12, a API REST e a persistência. O padrão é SQLite.
- `temperature_client_node.m` lê a entrada analógica e controla as saídas NI no MATLAB. O loop de controle opera a 100 Hz; um worker em segundo plano publica telemetria e busca comandos pela API uma vez por segundo.
- `code_base/` contém clientes Python de demonstração antigos. Eles geram dados aleatórios e não representam a integração MATLAB/NI atual.

Mantenha `backend/` e `frontend/` lado a lado: as rotas web Laravel carregam `frontend/index.html` e os arquivos JavaScript diretamente dessa pasta. O frontend não tem uma etapa de build configurada; bibliotecas como React, Axios, Chart.js e TailwindCSS são carregadas por CDN.

## Funcionalidades implementadas

- Cadastro e login com tokens Bearer do Laravel Sanctum; novos usuários recebem o perfil comum.
- Painel de temperatura e estado do controlador, com atualização do histórico por polling a cada dois segundos.
- Envio de comandos de setpoint e liga/desliga ao MATLAB; a interface aguarda uma leitura posterior para indicar que o valor foi aplicado.
- Simulação manual do dashboard e Sombra Digital, ambas executadas localmente no navegador. A simulação do dashboard só gera amostras quando ativada; seus controles não enviam comandos à API. Os parâmetros PI da Sombra Digital não são enviados ao MATLAB ou ao hardware.
- Área administrativa para consulta de auditoria, criação e listagem de backups SQLite e restauração confirmada.
- Criptografia Laravel para variáveis das leituras, valores de comandos, endereços IP e metadados de auditoria, usando `APP_KEY`.

Não há WebSocket. Sem telemetria atual, o dashboard indica dados indisponíveis ou desconectados; a simulação manual é uma opção local explícita.

## API REST

Prefixo: `/api`. As rotas de usuário usam `Authorization: Bearer <token-sanctum>`. As rotas MATLAB usam `Authorization: Bearer <MATLAB_LARAVEL_API_TOKEN>`, uma credencial de serviço separada.

| Método e rota | Acesso | Descrição |
| --- | --- | --- |
| `POST /auth/register` | Público, limite de 10 requisições por minuto junto com login | Cria usuário comum. Corpo: `name`, `email`, `password` (mínimo de 8 caracteres). |
| `POST /auth/login` | Público, limite de 10 requisições por minuto junto com cadastro | Autentica usuário e emite token Sanctum. Corpo: `email`, `password`. |
| `GET /auth/user` | Usuário autenticado | Retorna os dados do usuário atual e seu perfil. |
| `POST /auth/logout` | Usuário autenticado | Revoga o token atual. |
| `POST /readings` | Serviço MATLAB | Persiste uma leitura; requer `source`, `recorded_at` e `variables.temp`. `variables.system_status` aceita `ON` ou `OFF`; `variables.setpoint` aceita de 20 a 99. |
| `GET /readings/history?limit=60` | Usuário autenticado | Retorna um array de leituras em ordem cronológica. O limite opcional vai de 1 a 500 e o padrão é 60. Cada item inclui `recorded_at`, `received_at` e `variables`. |
| `POST /commands/add` | Usuário autenticado | Enfileira `setpoint` (20 a 99) ou `flag` (0 = OFF, 1 = ON). |
| `GET /commands` | Serviço MATLAB | Retorna até 100 comandos pendentes, em ordem de criação. |
| `POST /commands/ack` | Serviço MATLAB | Confirma um comando com `id` e estado `applied` ou `rejected`; rejeições aceitam `reason`. |
| `GET /admin/logs` | Administrador | Retorna até 200 eventos de auditoria. |
| `GET /admin/backups` | Administrador | Lista backups SQLite disponíveis. |
| `POST /admin/backups` | Administrador | Cria backup manual do banco ativo. |
| `POST /admin/restore` | Administrador | Restaura o `backup` informado após `confirmation: true`, preservando antes uma cópia do banco ativo. |

Exemplo de leitura MATLAB:

```json
{
  "source": "matlab-ni-usb-6003",
  "recorded_at": "2026-11-25T19:00:00.000Z",
  "variables": {
    "temp": 25.4,
    "system_status": "OFF",
    "setpoint": 30
  }
}
```

Exemplo de resposta de `GET /commands`:

```json
{"commands":[{"id":123,"name":"setpoint","value":45},{"id":124,"name":"flag","value":1}]}
```

O MATLAB confirma cada comando em `/commands/ack`, incluindo `reason` para rejeições. Em falha de comunicação, inicialização ou ausência de heartbeat por seis segundos, o controle vai para OFF; uma ativação ON pendente pode ser rejeitada e precisa ser enviada novamente após a recuperação da conexão. Ao encerrar, o cliente tenta zerar as saídas analógicas.

## Requisitos

- Backend: PHP 8.2 ou posterior, Composer e extensões PHP exigidas pelo Laravel e pelo driver PDO SQLite.
- Cliente MATLAB: MATLAB, Data Acquisition Toolbox, Parallel Computing Toolbox e suporte NI DAQ instalado e configurado.
- Hardware esperado pelo script: dispositivo `Dev1`, entrada analógica `ai0` em tensão, saídas analógicas `ao0` e `ao1`. Confirme se esses nomes e conexões correspondem ao equipamento do laboratório antes de operar.
- Scripts Python legados: Python e biblioteca `requests`.

## Instalação e execução local

No PowerShell, a partir da raiz do repositório:

```powershell
Set-Location backend
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database\database.sqlite
php artisan migrate
```

Configure no `backend/.env` um segredo forte para a comunicação MATLAB:

```dotenv
MATLAB_LARAVEL_API_TOKEN=<segredo-compartilhado-com-o-cliente-matlab>
```

Não grave o segredo ou `APP_KEY` no repositório. Inicie o servidor Laravel em `backend/`:

```powershell
php artisan serve --host 127.0.0.1 --port 8000
```

Acesse `http://127.0.0.1:8000`. Para conectar MATLAB em outro computador, vincule o servidor ao endereço privado adequado da LAN ou VPN e use esse endereço no cliente. Restrinja o acesso à rede privada; `php artisan serve` é para desenvolvimento, não para implantação pública.

Cadastros criam contas comuns. Para promover uma conta já cadastrada, use `php artisan tinker` em `backend/`:

```php
$user = App\Models\User::where('email', 'admin@example.com')->firstOrFail();
$user->is_admin = true;
$user->save();
```

Execute os testes existentes a partir de `backend/` com `php artisan test`.

## Cliente MATLAB

Configure o token no ambiente da sessão Laravel e na sessão MATLAB. No MATLAB, defina a URL completa da API e inicie o script:

```matlab
setenv("MATLAB_LARAVEL_API_URL", "http://127.0.0.1:8000/api")
setenv("MATLAB_LARAVEL_API_TOKEN", "<token-de-servico>")
temperature_client_node
```

Se o MATLAB estiver em outro computador, substitua `127.0.0.1` pelo endereço privado do servidor. O cliente exige ambas as variáveis de ambiente; não há endereço de API padrão. O script publica temperatura, estado e setpoint aproximadamente uma vez por segundo, enquanto mantém o loop de aquisição e controle a 100 Hz.

## Administração e backups

O backup automático SQLite é agendado diariamente às 02:00, com retenção máxima de 30 cópias, no diretório privado `backend/storage/app/private/backups`. O scheduler precisa estar ativo. Em desenvolvimento, execute `php artisan schedule:work` em outro terminal, a partir de `backend/`. A restauração pela API requer confirmação e cria uma cópia do banco atual antes da substituição. Essas operações exigem SQLite em arquivo persistente; não funcionam com banco em memória ou outros drivers.

## Escopo e limitações atuais

| Recurso | Estado |
| --- | --- |
| Interface, autenticação, API, histórico e comandos | Implementados; integração com hardware físico pendente de validação. |
| Simulação do dashboard e Sombra Digital | Simulações locais no navegador; não comandam o hardware. |
| Auditoria e administração | Logs e operações administrativas protegidos por perfil. |
| Backups SQLite | Criação manual e agendada, listagem e restauração implementadas. |
| Atualização instantânea | WebSocket não implementado; o dashboard usa polling. |
| Clientes em `code_base/` | Exemplos legados com dados aleatórios, fora do fluxo MATLAB/NI. |
