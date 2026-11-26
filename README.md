# Projeto Integrador · Controle e Monitoramento do Kit LTR701

Aplicação web para acompanhar e comandar o sistema térmico do Kit LTR701. A aquisição e o controle físico são executados no MATLAB com uma **NI USB‑6003**; o Laravel fornece a API e persiste os dados; o navegador apresenta o painel, o histórico, as estatísticas e a área administrativa.

> **Arquitetura escolhida:** MATLAB + NI‑DAQ ↔ API REST Laravel ↔ painel React. O ESP32 descrito na proposta original foi substituído pela NI USB‑6003. A conexão física completa ainda precisa de validação no laboratório.

## Sumário

- [Situação do projeto](#situação-do-projeto)
- [Arquitetura e fluxo de dados](#arquitetura-e-fluxo-de-dados)
- [Estrutura do repositório](#estrutura-do-repositório)
- [Tecnologias e decisões](#tecnologias-e-decisões)
- [Requisitos](#requisitos)
- [Instalação local](#instalação-local)
- [Conectar o MATLAB e a NI USB6003](#conectar-o-matlab-e-a-ni-usb6003)
- [Painel, estatísticas e anomalias](#painel-estatísticas-e-anomalias)
- [API REST](#api-rest)
- [Segurança e dados](#segurança-e-dados)
- [Administração, auditoria e backups](#administração-auditoria-e-backups)
- [Testes e documentação de verificação](#testes-e-documentação-de-verificação)
- [Validação no laboratório e próximos itens](#validação-no-laboratório-e-próximos-itens)

## Situação do projeto

| Área | Implementação atual |
| --- | --- |
| Aquisição e controle | Cliente MATLAB para NI‑DAQ; loop de controle configurado em 100 Hz. A operação com o equipamento físico ainda precisa ser validada. |
| Comunicação | HTTP/REST entre MATLAB e Laravel; o navegador consulta o histórico por polling a cada dois segundos. WebSocket e MQTT não estão configurados. |
| Login e cadastro | Laravel Sanctum com tokens Bearer, senha com hash e limite de tentativas nas rotas públicas. Cadastro pode ser desabilitado por configuração. |
| Dashboard | Temperatura, estado, setpoint, comandos, histórico e estatística descritiva. Layout responsivo servido pelo Laravel. |
| Detecção de anomalias | Regra estatística móvel no dashboard, aplicada às leituras reais e destacada no gráfico. |
| Persistência e criptografia | SQLite por padrão; Laravel cifra variáveis de leitura, valores dos comandos, IPs e metadados de auditoria com `APP_KEY`. |
| Administração | Logs protegidos por perfil administrativo, criação/listagem de backups e restauração confirmada. |
| Backups automáticos | Rotina diária às 02:00 no fuso `America/Sao_Paulo`; depende do scheduler permanecer ativo. |
| Desempenho medido | Latência, vazão, perda de mensagens e RTT ainda precisam ser medidos e registrados com o sistema em execução. |

## Arquitetura e fluxo de dados

1. O MATLAB lê a entrada analógica `Dev1/ai0`, converte o sinal em temperatura e executa o controlador PI em ciclo local de 100 Hz.
2. O MATLAB escreve os sinais de controle nas saídas `Dev1/ao0` (aquecedor) e `Dev1/ao1` (ventilador), conforme a ligação definida no script.
3. Um worker MATLAB em segundo plano publica telemetria e consulta comandos pela API Laravel aproximadamente uma vez por segundo.
4. O Laravel valida e armazena as leituras e os comandos no SQLite. A tela web consulta o histórico a cada dois segundos e exibe o estado mais recente.
5. Um usuário autenticado envia setpoint ou liga/desliga pela interface. O comando fica na fila até o MATLAB buscá-lo e confirmar se foi aplicado ou rejeitado.

O loop físico não espera pela rede: aquisição e controle continuam localmente enquanto o worker trata as chamadas HTTP. O sistema não usa WebSocket; a atualização do painel é periódica. A proposta admite comunicação por HTTP, portanto essa é a opção atualmente implementada. Caso a avaliação exija atualização instantânea por WebSocket, será necessário adicionar essa camada.

## Estrutura do repositório

| Caminho | Responsabilidade |
| --- | --- |
| [`temperature_client_node.m`](temperature_client_node.m) | Aquisição NI‑DAQ, controle PI, worker HTTP, fila e confirmação de comandos. |
| [`frontend/index.html`](frontend/index.html) | Aplicação React carregada pelo Laravel: login, dashboard, simulação e administração. |
| [`frontend/js/api.js`](frontend/js/api.js) | Cliente Axios e inclusão do token Sanctum nas chamadas do navegador. |
| [`frontend/js/stats.js`](frontend/js/stats.js) | Estatística descritiva da série de temperaturas. |
| [`frontend/js/simulacao.js`](frontend/js/simulacao.js) | Simulação local de planta térmica e controlador PI da Sombra Digital. |
| `backend/routes/api.php` | Rotas REST e associação dos middlewares de autenticação e autorização. |
| `backend/app/Http/Controllers/Api/` | Autenticação, leituras, comandos e operações administrativas. |
| `backend/app/Http/Middleware/` | Autenticação do serviço MATLAB, autorização admin, exigência de HTTPS e política de cadastro. |
| `backend/app/Models/` | Usuários, leituras, comandos e auditoria; casts de criptografia Laravel. |
| `backend/app/Services/SqliteBackupService.php` | Criação, listagem, retenção e restauração de cópias SQLite. |
| `backend/database/migrations/` | Esquema das tabelas e evolução incremental do banco. |
| `backend/routes/console.php` | Comando Artisan e agenda diária de backup. |
| `backend/tests/Feature/` | Testes da API, autorização e ciclo de logs/backups. |
| `code_base/` | Exemplos Python antigos que geram dados aleatórios; não fazem parte da conexão MATLAB/NI atual. |
| [`docs/verificacao-logs-backups.md`](docs/verificacao-logs-backups.md) | Procedimento operacional e resultados da verificação isolada de logs e backups. |

O Laravel serve `frontend/index.html` em `/` e os arquivos auxiliares em `/js/{asset}`. Não há etapa de build do frontend: React, Babel, Axios, Chart.js e TailwindCSS são carregados por CDN. Mantenha `frontend/` ao lado de `backend/`.

## Tecnologias e decisões

| Camada | Escolha | Motivo e limite |
| --- | --- | --- |
| Hardware e controle | NI USB‑6003, MATLAB e NI‑DAQ | A placa substitui o ESP32 da proposta. O MATLAB mantém o controle local e usa a rede para telemetria e comandos. |
| Backend | PHP 8.2+ e Laravel 12 | Rotas REST, validação, autenticação, ORM, criptografia, migrations e scheduler. |
| Banco | SQLite | Banco padrão para demonstração local e compatível com o serviço de backup atual. A restauração/backup implementados aceitam SQLite em arquivo; MySQL e PostgreSQL não estão cobertos pelo serviço de backup. |
| Frontend | React 18, JavaScript, TailwindCSS e Chart.js | Interface de página única com gráficos responsivos, sem pipeline de compilação local. |
| Comunicação | HTTP com JSON | Adequado ao worker MATLAB e à API; a atualização do navegador usa polling em vez de WebSocket. |

## Requisitos

- **Backend:** PHP 8.2+, Composer e extensões PDO SQLite exigidas pelo Laravel.
- **Cliente de aquisição:** MATLAB, Data Acquisition Toolbox, Parallel Computing Toolbox e suporte NI‑DAQ instalado.
- **Hardware configurado no script:** dispositivo `Dev1`, entrada `ai0` em tensão e saídas `ao0`/`ao1` em tensão. Confirme nomes, terminais, aterramento, escala do sensor e limites do atuador antes de operar.
- **Navegador:** conexão com a internet para carregar as bibliotecas incluídas por CDN.
- **Python legado (opcional):** Python e `requests` apenas para os exemplos em `code_base/`.

## Instalação local

No PowerShell, a partir da raiz do repositório:

```powershell
Set-Location backend # Entra na pasta da aplicação Laravel.
composer install # Instala as dependências PHP declaradas pelo projeto.
Copy-Item .env.example .env # Cria a configuração local do backend.
php artisan key:generate # Gera a chave usada pela criptografia Laravel.
if (-not (Test-Path database\database.sqlite)) { New-Item -ItemType File -Path database\database.sqlite | Out-Null } # Cria o SQLite somente se ele ainda não existir.
php artisan migrate # Cria ou atualiza as tabelas sem apagar os dados existentes.
php artisan serve --host 127.0.0.1 --port 8000 # Inicia o servidor local de desenvolvimento.
```

Abra `http://127.0.0.1:8000`. Configure em `backend/.env` um token aleatório e exclusivo para o MATLAB. Use as variáveis `MATLAB_LARAVEL_API_TOKEN`, `MATLAB_LARAVEL_API_PREVIOUS_TOKENS` e `REGISTRATION_ENABLED`; mantenha `APP_KEY` e tokens fora do controle de versão. O arquivo `.env.example` traz os nomes e valores de desenvolvimento.

Para conectar outro computador durante uma demonstração local, use o endereço privado do servidor e restrinja o acesso à LAN/VPN. O servidor `php artisan serve` é apenas para desenvolvimento; não o exponha diretamente à internet.

### Criar uma conta administradora

Cadastros novos começam como usuários comuns. Depois de cadastrar a conta que será administradora, execute `php artisan tinker` dentro de `backend/` e promova-a:

```php
$user = App\Models\User::where('email', 'admin@example.com')->firstOrFail(); // Localiza a conta pelo e-mail cadastrado.
$user->is_admin = true; // Concede o perfil administrativo à conta escolhida.
$user->save(); // Persiste a alteração de perfil no banco.
```

## Conectar o MATLAB e a NI USB6003

Defina a URL completa da API e o mesmo token configurado no backend na sessão MATLAB. Para o servidor local na mesma máquina:

```matlab
setenv("MATLAB_LARAVEL_API_URL", "http://127.0.0.1:8000/api"); % Define o endereço local da API Laravel.
setenv("MATLAB_LARAVEL_API_TOKEN", "<token-de-servico>"); % Define a credencial compartilhada fora do código-fonte.
temperature_client_node; % Inicia a aquisição NI-DAQ e o controle do sistema.
```

Se o MATLAB estiver em outro computador, troque o host pelo endereço privado do servidor. Em ambiente de produção, use HTTPS; o middleware Laravel rejeita HTTP fora dos ambientes `local` e `testing`. Configure o certificado e o proxy reverso corretamente. Nunca coloque o token diretamente no script MATLAB ou em um repositório compartilhado.

O script exige as duas variáveis de ambiente e não usa URL padrão. Ele foi configurado para:

- Ler `Dev1/ai0` e interpretar o sinal como tensão.
- Converter a medição por `temp_C = 10 × tensão` e aplicar o filtro implementado no script.
- Executar o loop de controle a 100 Hz, com limites de saída definidos no código.
- Enviar temperatura, estado, setpoint e, quando disponível, o ID do usuário do comando recebido aproximadamente uma vez por segundo.
- Buscar comandos aproximadamente uma vez por segundo e confirmar aplicação/rejeição pela API.
- Colocar o comando em OFF ao iniciar e quando a comunicação/heartbeat falhar; ao encerrar, tentar zerar as saídas.

Esses parâmetros refletem o código, não substituem a calibração ou validação de segurança do equipamento físico.

## Painel, estatísticas e anomalias

O dashboard mostra temperatura atual, conexão, estado do controlador, setpoint aplicado, formulário de controle e gráfico histórico. O histórico é consultado a cada dois segundos; sem telemetria recente, o painel indica desconexão. O comando web só é apresentado como aplicado após uma leitura posterior confirmar o novo estado ou setpoint.

### Estatística descritiva

`frontend/js/stats.js` calcula média, variância amostral, assimetria, curtose e quantidade de outliers para a série carregada no painel. A contagem descritiva de outliers usa distância superior a 2,5 desvios padrão da média da série. São necessárias ao menos quatro amostras para exibir esse resumo.

### Detector de anomalias

O detector do dashboard compara cada leitura real com até dez leituras anteriores. Ele espera ao menos cinco referências e sinaliza um ponto quando a distância da média anterior excede `max(0,25 °C, 3 × desvio padrão)` da janela. Os pontos sinalizados ficam vermelhos no gráfico e até cinco alertas recentes mostram horário, temperatura e diferença em relação à média usada.

A regra é um alerta estatístico inicial, não um diagnóstico nem um intertravamento de segurança. O piso de 0,25 °C e a janela devem ser avaliados com dados do sensor e processo reais. A detecção é pausada durante a simulação local; os dados simulados não são enviados à API nem ao hardware. Não há modelo de aprendizado de máquina treinado no projeto; o detector implementado é uma regra estatística.

### Simulações

- **Simulação do dashboard:** pode ser ativada manualmente quando a API não tem leituras; gera dados locais no navegador e não envia comandos reais.
- **Sombra Digital:** simula uma planta térmica e controlador PI com gráficos de temperatura, setpoint, erro e sinal. Seus parâmetros `Kp` e `Ki` não são enviados ao MATLAB.

## API REST

Prefixo: `/api`. Rotas de usuário exigem `Authorization: Bearer <token-sanctum>`. Rotas de telemetria e comandos do cliente MATLAB exigem `Authorization: Bearer <MATLAB_LARAVEL_API_TOKEN>`.

| Método e rota | Acesso | Função e validação principal |
| --- | --- | --- |
| `POST /auth/register` | Público; 10 req/min junto com login | Cria usuário comum. Campos: `name`, `email`, `password` (mínimo de 8 caracteres). Pode ser desligado com `REGISTRATION_ENABLED=false`. |
| `POST /auth/login` | Público; 10 req/min junto com cadastro | Valida credenciais e devolve token Sanctum. |
| `GET /auth/user` | Usuário autenticado | Retorna usuário e perfil atual. |
| `POST /auth/logout` | Usuário autenticado | Revoga o token atual. |
| `POST /readings` | Serviço MATLAB | Recebe `source`, `recorded_at` e `variables.temp`; aceita `system_status` ON/OFF, `setpoint` de 20 a 99 e `user_id` opcional de usuário existente dentro de `variables`. |
| `GET /readings/history?limit=60` | Usuário autenticado | Retorna leituras em ordem cronológica; `limit` varia de 1 a 500. Inclui `user_id`, `recorded_at`, `received_at` e variáveis descriptografadas. |
| `POST /commands/add` | Usuário autenticado | Enfileira `setpoint` (20–99) ou `flag` (0 = OFF, 1 = ON), registra usuário autor e audita a ação. |
| `GET /commands` | Serviço MATLAB | Retorna até 100 comandos pendentes, com ID, nome, valor e autor `user_id`. |
| `POST /commands/ack` | Serviço MATLAB | Recebe `id` e status `applied` ou `rejected`; rejeição aceita `reason`. |
| `GET /admin/logs` | Administrador | Retorna até 200 eventos com ator, ação, IP, metadados, usuário e horário. |
| `GET /admin/backups` | Administrador | Lista os backups SQLite disponíveis sem revelar caminhos absolutos. |
| `POST /admin/backups` | Administrador | Cria backup manual e registra o resultado na auditoria. |
| `POST /admin/restore` | Administrador | Exige `backup` e `confirmation: true`; cria uma cópia do estado atual antes de restaurar. |

No recebimento, `variables.user_id` é validado e salvo como `readings.user_id`, fora do conjunto de variáveis físicas cifradas. O MATLAB o obtém da fila de comandos. Se uma leitura não vier associada a uma sessão de controle, o campo fica nulo.

As respostas de validação usam HTTP 422, falta/erro de autenticação usa 401, usuário sem perfil administrativo usa 403, e configurações/erros operacionais de backup podem retornar 422.

## Segurança e dados

- **Senhas:** armazenadas com hash Laravel; nunca são devolvidas no payload de usuário.
- **Sessões web/API:** Sanctum emite tokens Bearer revogáveis. A rota de cadastro e a de login compartilham limite de 10 requisições por minuto.
- **Administração:** middleware no backend exige `is_admin` para logs, criação/listagem de backups e restauração. Esconder o menu no frontend não é a barreira de autorização.
- **Serviço MATLAB:** usa token Bearer separado. A configuração aceita token atual e lista temporária de tokens anteriores para rotação controlada.
- **Criptografia em repouso:** Laravel cifra variáveis de leitura, valores de comando, endereços IP e metadados de auditoria com `APP_KEY`. Habilite também `SESSION_ENCRYPT=true` no ambiente da aplicação.
- **Chaves:** mantenha `APP_KEY` em segredo e com backup protegido. Perder ou trocar a chave sem configurar `APP_PREVIOUS_KEYS` pode tornar dados já cifrados ilegíveis.
- **Transporte:** HTTPS é exigido pelo middleware fora de `local` e `testing`. Configure TLS no servidor/proxy e encaminhamento correto do esquema original antes de implantar.
- **Cadastro:** defina `REGISTRATION_ENABLED=false` depois de provisionar as contas necessárias em uma instalação restrita.
- **Usuário nas leituras:** leituras podem ser associadas ao usuário identificado no comando MATLAB recebido; eventos automáticos do serviço usam o ator `matlab` e não representam uma sessão humana autenticada.
- **Banco:** o arquivo padrão é `backend/database/database.sqlite`. Restrinja o acesso ao arquivo e ao diretório de backups no sistema operacional.

Na configuração de produção use `APP_ENV=production`, `APP_DEBUG=false`, HTTPS e segredos fornecidos pelo ambiente/gerenciador de segredos. **Este checkout tem um arquivo `.env` na raiz rastreado pelo Git. Revise-o antes de compartilhar o repositório; se contiver credenciais reais, remova-as do controle de versão e faça a rotação dos valores expostos.** O Laravel usa `backend/.env`, que deve permanecer local e ignorado pelo Git.

### Rotacionar o token do MATLAB

1. Configure o token novo em `MATLAB_LARAVEL_API_TOKEN` no backend.
2. Coloque temporariamente o token antigo em `MATLAB_LARAVEL_API_PREVIOUS_TOKENS` e recarregue a configuração Laravel.
3. Atualize `MATLAB_LARAVEL_API_TOKEN` no ambiente MATLAB e confirme a comunicação.
4. Remova o token antigo de `MATLAB_LARAVEL_API_PREVIOUS_TOKENS` e recarregue a configuração.

## Administração, auditoria e backups

O painel **Admin (Logs/Backup)** permite consultar auditoria, criar e listar cópias e restaurar uma delas após confirmação. As operações também estão disponíveis pela API administrativa.

- Backups ficam em `backend/storage/app/private/backups`, fora da pasta pública web.
- A retenção máxima é de 30 arquivos SQLite; cópias excedentes antigas são removidas.
- `php artisan backup:sqlite` executa uma cópia manual pelo terminal.
- O scheduler agenda a cópia diária para 02:00 de `America/Sao_Paulo`. Em `schedule:list`, Laravel pode exibir a agenda equivalente em UTC (05:00).
- O scheduler deve continuar ativo: em desenvolvimento, mantenha `php artisan schedule:work` rodando em outro terminal dentro de `backend/`. Em produção, use um worker/scheduler supervisionado ou o agendador do sistema operacional.
- A restauração exige confirmação e cria primeiro uma cópia do banco ativo. Como a restauração substitui o snapshot inteiro, eventos de auditoria posteriores ao backup restaurado também desaparecem; o resultado da restauração é registrado no banco restaurado.
- Backup e restauração foram implementados somente para SQLite em arquivo persistente; não funcionam com SQLite `:memory:` ou outros drivers.

Siga o [roteiro de verificação de logs e backups](docs/verificacao-logs-backups.md) para testar autorização, eventos de auditoria, criação, listagem, restauração e agendamento em ambiente isolado.

## Testes e documentação de verificação

Na pasta `backend/`, execute a suíte:

```powershell
php artisan test # Executa os testes unitários e de integração Laravel.
```

Na última execução documentada, passaram **14 testes e 77 assertions**. Os testes de logs/backups usam banco e armazenamento temporários, não o SQLite persistente da demonstração. Eles verificam autorização 403, logs com autor/IP/horário, ciclo backup-restauração e vencimento do evento diário. A execução diária real depende de manter o scheduler ativo no host.

O documento de verificação registra o procedimento e resultados reproduzíveis. Esta documentação descreve a arquitetura e o código; ela não contém ainda medições experimentais de latência, vazão, perda de mensagens ou round-trip time.

## Validação no laboratório e próximos itens

- Conferir `Dev1`, canal `ai0`, escala do sensor, terminais e ligações reais das saídas `ao0`/`ao1` antes de aplicar sinal aos atuadores.
- Validar a escala de temperatura, os limites do PI e o comportamento de desligamento seguro com o Kit LTR701 e a NI USB‑6003 reais.
- Medir e registrar latência de requisição/resposta, throughput, perda de mensagens e RTT com método, carga e condições de rede descritos.
- Justificar formalmente as escolhas (MATLAB/NI, Laravel, React e SQLite) e comparar alternativas no relatório técnico da disciplina.
- Se o avaliador exigir comunicação instantânea no navegador, adicionar WebSocket; atualmente a API usa HTTP e o frontend usa polling.

Os scripts em `code_base/` são demonstrações antigas com valores aleatórios e não substituem nenhum desses testes de hardware ou desempenho.
