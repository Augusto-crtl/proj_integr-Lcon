# Projeto Integrador — Laboratório de Sistemas de Controle

Aplicação de monitoramento e controle para um simulador de sistemas de controle, acompanhada de uma simulação local de planta térmica com controlador PI. A arquitetura é **Simulador → NI USB-6003 → MATLAB → Laravel → Dashboard**. O backend Laravel implementa a API REST usada pelo dashboard e pelo cliente MATLAB; a integração com hardware real ainda precisa ser validada no ambiente do laboratório.

## Estado atual

### Interface web

A interface React continua em `frontend/index.html` e é servida pelo Laravel em `backend/`. A página carrega React 18, Babel, Axios, Chart.js e TailwindCSS por CDN; a aplicação React e suas telas ficam no próprio HTML. Os arquivos auxiliares são:

- `frontend/js/api.js`: configura Axios para a API na mesma origem e envia o token Bearer da sessão quando disponível.
- `frontend/js/simulacao.js`: simula a resposta térmica de primeira ordem e um controlador PI discreto, com saturação do sinal entre 0 e 10 V e anti-windup.
- `frontend/js/stats.js`: calcula média, variância amostral, assimetria, curtose e uma contagem simples de outliers (valores a mais de 2,5 desvios padrão da média); exige pelo menos quatro valores.

A interface apresenta:

- Login e cadastro que tentam chamar `/auth/login` e `/auth/register`.
- Dashboard com temperatura, setpoint, liga/desliga do controle, gráfico e estatísticas.
- “Sombra Digital”, uma simulação local com setpoints, perturbação e parâmetros PI; seus ganhos não são enviados a um equipamento.
- Uma tela administrativa que consulta eventos reais; o endpoint de restauração registra a solicitação e informa que o serviço ainda não está configurado.

O dashboard não fabrica leituras quando a API está indisponível: ele mantém os últimos dados e mostra o estado offline. A simulação PI ainda roda localmente e não comanda o dispositivo. Login e cadastro usam tokens Laravel Sanctum; não há login rápido nem dados de usuário/admin de demonstração. O estado do controlador vem das leituras MATLAB, não de cliques na interface.

### Scripts Python de exemplo (legado)

`code_base/cliente.py`, `code_base/esp.py` e `code_base/usuario.py` são exemplos antigos de clientes HTTP, não um servidor nem a integração de hardware planejada:

- `cliente.py` gera temperatura e nível aleatórios a cada cinco segundos, envia uma leitura por `POST /readings`, consulta comandos por `GET /commands` e tenta confirmá-los por `POST /commands/ack`.
- `esp.py` também envia leituras aleatórias a cada cinco segundos para `/readings`; apesar do nome do arquivo, não se comunica com um ESP nem com o NI USB-6003.
- `usuario.py` pede um nome e valor e tenta enfileirar um comando em `POST /commands/add`.

Os scripts apontam para `http://localhost/api` e usam um token de exemplo. São somente demonstrações de tráfego HTTP e não fazem parte do fluxo Simulador → MATLAB → Dashboard.

## Comunicação com o ambiente simulado

Hoje há dois fluxos de simulação independentes no navegador:

1. **Dashboard offline:** quando não consegue carregar `/readings/history?limit=60`, a interface mostra o estado offline e não inventa leituras. Os botões não alteram localmente o estado do controlador.
2. **Sombra Digital:** ao submeter o formulário, `simulacao.js` calcula no navegador uma resposta de planta térmica discretizada, usando ganho, constante de tempo, passo de simulação e parâmetros PI. A saída inclui temperatura, setpoint, erro e sinal de controle, exibidos em quatro gráficos. Nenhum dado é enviado ao MATLAB ou ao hardware.

### Arquitetura integrada

O caminho de aquisição e visualização é **Simulador → NI USB-6003 → MATLAB → Dashboard**:

1. O simulador físico produz os sinais do processo.
2. O NI USB-6003 (dispositivo de aquisição da National Instruments) faz a interface de entrada/saída entre o simulador e o computador que executa MATLAB.
3. `temperature_client_node.m` adquire e processa as medições e as publica ao Laravel por uma API autenticada.
4. O dashboard recebe os dados via backend, registra leituras/histórico e apresenta estado e gráficos.

O caminho de controle funciona no sentido inverso: **Dashboard → backend → MATLAB → NI USB-6003 → Simulador**. O dashboard enfileira comandos autorizados; MATLAB valida/aplica o comando pela interface de aquisição e o backend registra o resultado. O contrato REST inicial está descrito abaixo. WebSocket (ou Socket.IO) ainda pode ser adotado para atualizações instantâneas; hoje o dashboard consulta o histórico por polling.

`backend/` contém a API Laravel 12. `temperature_client_node.m` publica uma leitura por segundo e busca comandos pela API usando um MATLAB background worker, mantendo o loop DAQ a 100 Hz fora das chamadas de rede. A API e o hardware ainda precisam ser testados juntos com um NI USB-6003 real. O navegador consulta o histórico por polling a cada dois segundos; WebSocket e processamento efetivo de backup/restauração ainda não estão configurados.

## Implementação Laravel

O projeto Laravel fica em `backend/` e serve a interface existente, autentica usuários, persiste leituras e comandos e registra eventos administrativos. A simulação PI permanece inteiramente no navegador.

### Backend (Laravel 12 / PHP 8.2+)

- A API REST implementa autenticação, leituras, comandos, histórico, logs e operações administrativas em `backend/routes/api.php`.
- Eloquent persiste as medições MATLAB, comandos pendentes/confirmados e eventos de auditoria.
- Middleware separa a autenticação dos usuários (Sanctum) da credencial de serviço MATLAB e protege operações administrativas por role.
- As requisições validam os dados e autenticam as rotas; senhas usam hash Laravel e os dados confidenciais são criptografados com casts Laravel.
- SQLite é usado localmente; MySQL/PostgreSQL podem ser configurados no `.env` para produção, com HTTPS obrigatório no ambiente de implantação.

### Contrato de comunicação MATLAB ↔ Laravel

As rotas abaixo formam o contrato implementado para `temperature_client_node.m` e para o dashboard. O serviço MATLAB usa um Bearer token próprio, configurado no ambiente do Laravel; ele não compartilha tokens Sanctum dos usuários.

| Operação | Rota | Uso |
| --- | --- | --- |
| Registrar / autenticar | `POST /api/auth/register`, `POST /api/auth/login` | Criar usuário comum e emitir token Sanctum. |
| Receber leitura | `POST /api/readings` | MATLAB envia origem, timestamp e variáveis da aquisição. |
| Consultar histórico | `GET /api/readings/history?limit=60` | Dashboard recupera leituras; cada item deve incluir `recorded_at` e `variables.temp`. |
| Enfileirar comando | `POST /api/commands/add` | Interface registra parâmetros, por exemplo setpoint ou flag. |
| Buscar comandos | `GET /api/commands` | Serviço MATLAB autenticado consulta comandos pendentes, em ordem de criação. |
| Confirmar comando | `POST /api/commands/ack` | MATLAB confirma comandos aplicados ou rejeitados. |
| Consultar logs | `GET /api/admin/logs` | Administrador consulta eventos de operação e segurança. |
| Restaurar backup | `POST /api/admin/restore` | Registra a solicitação administrativa e responde `503` até configurar um serviço de restauração. |

As leituras, valores de comandos, endereços IP e metadados de auditoria são criptografados com os casts Laravel e a `APP_KEY` da aplicação. Essa chave deve ser protegida e mantida fora do controle de versão. A API usa SQLite para desenvolvimento; configure MySQL/PostgreSQL pelo `.env` em produção.

Payload de leitura enviado a cada segundo:

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

`GET /api/commands` retorna `{"commands":[{"id":123,"name":"setpoint","value":45},{"id":124,"name":"flag","value":1}]}`. A interface enfileira `setpoint` (20–99 °C) e `flag` (0 = OFF, 1 = ON) usando o contrato de `frontend/index.html`. Para cada comando, MATLAB envia `{"id":123,"status":"applied"}` ou `{"id":123,"status":"rejected","reason":"..."}` a `/api/commands/ack`. A API autentica a origem, retorna apenas comandos pendentes e registra a confirmação; o estado do controlador é exibido a partir das leituras, não inferido apenas do clique no navegador.

Se uma chamada à API falhar ou o MATLAB for iniciado, o worker força o estado para OFF. Ativações ON pendentes durante a indisponibilidade ou na inicialização são rejeitadas; o usuário precisa enviar uma nova ativação pelo dashboard depois que a conexão estiver confirmada. A aquisição e o controle local continuam no período de 100 Hz, mas se o worker deixar de responder por três segundos o controlador também vai para OFF.

WebSocket (ou Socket.IO) ainda não está implementado; até lá, a interface consulta o histórico por polling. REST atende autenticação, configuração de parâmetros, histórico e operações administrativas.

### Frontend

- A tela React existente é servida pelo Laravel; o dashboard, gráfico, estatísticas e formulário de controle permanecem no protótipo.
- A autenticação usa tokens Sanctum; o backend aplica autorização administrativa independentemente da navegação/role exibida no navegador.
- A interface apresenta estado da conexão, leituras históricas e confirmações pendentes de comandos; atualizações continuam em polling.
- A Sombra Digital segue local e não envia seus ganhos PI ao MATLAB.

### Armazenamento, segurança e operação

- Leituras, valores de comandos e metadados/IP dos logs usam casts criptografados Laravel e a chave local `APP_KEY`; a chave deve ficar fora do repositório. Planeje rotação de chaves para produção.
- Registrar timestamp e identidade do usuário, serviço MATLAB ou origem de aquisição responsável, respeitando o modelo de dados aplicável a cada evento.
- Restringir logs, administração e restauração a usuários com perfil administrativo; usuários comuns podem controlar o sistema e consultar séries históricas autorizadas.
- Backups automáticos e execução de restauração ainda precisam ser configurados. Até isso ser feito, a rota de restauração registra a solicitação administrativa e responde `503`, sem afirmar sucesso.
- Proteger credenciais e tráfego, validar e autorizar cada operação no servidor, armazenar senhas com hash seguro e registrar eventos de autenticação e controle sem expor segredos.

## Executar a aplicação

No PowerShell:

```powershell
Set-Location backend
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database\database.sqlite
php artisan migrate
```

Defina um segredo forte apenas no `backend/.env`:

```dotenv
MATLAB_LARAVEL_API_TOKEN=<segredo-forte-compartilhado-com-matlab>
```

Inicie a aplicação vinculando-a somente ao endereço necessário. Se MATLAB e Laravel estiverem neste mesmo computador, use localhost:

```powershell
php artisan serve --host 127.0.0.1 --port 8000
```

Se MATLAB estiver em outro computador, vincule Laravel somente ao endereço privado da LAN ou da VPN:

```powershell
$LaravelHostIp = "192.168.1.50" # substitua pelo IPv4 privado da LAN ou da VPN
php artisan serve --host $LaravelHostIp --port 8000
```

No mesmo computador, abra `http://127.0.0.1:8000` e configure `MATLAB_LARAVEL_API_URL` como `http://127.0.0.1:8000/api`. Para computadores separados, abra `http://192.168.1.50:8000` no host Laravel e configure MATLAB com `http://192.168.1.50:8000/api`, substituindo o exemplo pelo IPv4 privado da LAN ou VPN. Os computadores devem compartilhar a mesma LAN privada ou VPN. Se o Firewall do Windows bloquear uma conexão remota, permita TCP `8000` apenas pela interface privada/VPN e restrinja a origem ao computador MATLAB. Não use `0.0.0.0`, não encaminhe a porta pelo roteador e não use um IPv4 público com HTTP. `php artisan serve` é apenas para desenvolvimento e teste em rede confiável.

Cadastros criam usuários comuns. Para promover uma conta a administradora, execute `php artisan tinker` e rode no prompt:

```php
$user = App\Models\User::where('email', 'admin@example.com')->firstOrFail();
$user->is_admin = true;
$user->save();
```

A autorização administrativa também é validada pelo backend.

Execute os testes de API com `php artisan test`.

Os scripts em `code_base/` requerem Python e a biblioteca `requests`; são exemplos legados e não fazem parte da integração MATLAB.

### Executar a ponte MATLAB

Quando o backend Laravel e o dispositivo NI estiverem configurados, basta uma sessão MATLAB:

```matlab
setenv("MATLAB_LARAVEL_API_URL", "http://192.168.1.50:8000/api")
setenv("MATLAB_LARAVEL_API_TOKEN", "<token-de-servico>")
temperature_client_node
```

Use `http://127.0.0.1:8000/api` quando MATLAB e Laravel estiverem no mesmo computador. Caso contrário, substitua `192.168.1.50` pelo IPv4 privado da LAN ou VPN do computador Laravel, onde o servidor está vinculado. Configure o mesmo segredo de serviço em `MATLAB_LARAVEL_API_TOKEN` no `backend/.env` e na sessão MATLAB; não o salve no repositório. A URL é obrigatória para evitar que o cliente se conecte silenciosamente ao host errado. O script mantém o DAQ no loop principal e executa as chamadas Laravel em um background worker. MATLAB faz requisições HTTP diretamente, portanto essa comunicação não depende de CORS. Envie a ativação pelo dashboard autenticado; o backend autoriza e enfileira o comando para MATLAB.

## Escopo deste repositório hoje

| Recurso | Situação |
| --- | --- |
| Interface de login, dashboard, controle e administração | React servido pelo Laravel; autenticação, leituras, comandos e logs integrados à API. |
| Simulação PI da planta | Executada localmente no navegador; sem ligação com MATLAB ou hardware. |
| Simulação de leituras no dashboard | Removida; a interface informa indisponibilidade da API sem criar dados falsos. |
| Ponte MATLAB ↔ API Laravel | Implementada em `temperature_client_node.m` e `backend/`; aguarda validação com hardware real. |
| Scripts Python de cliente HTTP | Exemplos legados que geram dados aleatórios; não representam a arquitetura pretendida. |
| API Laravel, autenticação, autorização, leituras, comandos e auditoria | Implementadas; configuradas por padrão para SQLite local. |
| Criptografia de leituras, valores de comandos e metadados de auditoria | Implementada via casts Laravel, protegida pela `APP_KEY`. |
| WebSocket, backups diários e execução real de restauração | Ainda não implementados; `/api/admin/restore` registra o pedido e retorna `503`. |
