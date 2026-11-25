# Projeto Integrador — Laboratório de Sistemas de Controle

Protótipo de interface para monitoramento e controle de um simulador de sistemas de controle, acompanhado de uma simulação de planta térmica com controlador PI. A arquitetura pretendida é **Simulador → NI USB-6003 → MATLAB → Dashboard**. O projeto está em uma fase inicial: a interface existe, mas ainda não há integração funcional com o NI USB-6003/MATLAB, backend, banco de dados ou autenticação real.

## Estado atual

### Interface web

A aplicação está em `frontend/index.html`. É uma página estática que carrega React 18, Babel, Axios, Chart.js e TailwindCSS por CDN; a aplicação React e suas telas ficam no próprio HTML. Os arquivos auxiliares são:

- `frontend/js/api.js`: configura Axios para `http://localhost:8000/api` e adiciona um token Bearer. Na ausência de token, usa o valor demonstrativo `seu_token_aqui`.
- `frontend/js/simulacao.js`: simula a resposta térmica de primeira ordem e um controlador PI discreto, com saturação do sinal entre 0 e 10 V e anti-windup.
- `frontend/js/stats.js`: calcula média, variância amostral, assimetria, curtose e uma contagem simples de outliers (valores a mais de 2,5 desvios padrão da média); exige pelo menos quatro valores.

A interface apresenta:

- Login e cadastro que tentam chamar `/auth/login` e `/auth/register`.
- Dashboard com temperatura, setpoint, liga/desliga do controle, gráfico e estatísticas.
- “Sombra Digital”, uma simulação local com setpoints, perturbação e parâmetros PI; seus ganhos não são enviados a um equipamento.
- Uma tela administrativa com registros e informações de backup demonstrativos.

Sem backend, o dashboard gera leituras aleatórias no navegador a cada dois segundos e mostra o estado “Modo Simulado”. A simulação PI também roda localmente. O login rápido apenas grava um usuário escolhido no `localStorage`; não autentica ninguém. A tabela de logs e a informação de backup são dados fixos de demonstração. Os botões de controle e restauração podem fazer requisições HTTP, mas não executam ações persistentes sem um servidor.

### Scripts Python de exemplo (legado)

`code_base/cliente.py`, `code_base/esp.py` e `code_base/usuario.py` são exemplos antigos de clientes HTTP, não um servidor nem a integração de hardware planejada:

- `cliente.py` gera temperatura e nível aleatórios a cada cinco segundos, envia uma leitura por `POST /readings`, consulta comandos por `GET /commands` e tenta confirmá-los por `POST /commands/ack`.
- `esp.py` também envia leituras aleatórias a cada cinco segundos para `/readings`; apesar do nome do arquivo, não se comunica com um ESP nem com o NI USB-6003.
- `usuario.py` pede um nome e valor e tenta enfileirar um comando em `POST /commands/add`.

Os scripts apontam para `http://localhost/api` e usam um token de exemplo. São somente demonstrações de tráfego HTTP e não fazem parte do fluxo Simulador → MATLAB → Dashboard. As rotas, o formato definitivo das respostas, a validação do token e o processamento/armazenamento dessas chamadas ainda dependem do backend a ser implementado.

## Comunicação com o ambiente simulado

Hoje há dois fluxos de simulação independentes no navegador:

1. **Dashboard offline:** quando não consegue carregar `/readings/history?limit=60` do backend, o navegador cria uma série de temperaturas aleatórias suavizadas em direção a 25 °C ou ao setpoint selecionado, de acordo com o estado do botão de controle. Isso é apenas uma visualização local; não vem de um equipamento e não é persistido.
2. **Sombra Digital:** ao submeter o formulário, `simulacao.js` calcula no navegador uma resposta de planta térmica discretizada, usando ganho, constante de tempo, passo de simulação e parâmetros PI. A saída inclui temperatura, setpoint, erro e sinal de controle, exibidos em quatro gráficos. Nenhum dado é enviado ao MATLAB ou ao hardware.

### Arquitetura pretendida

O caminho de aquisição e visualização é **Simulador → NI USB-6003 → MATLAB → Dashboard**:

1. O simulador físico produz os sinais do processo.
2. O NI USB-6003 (dispositivo de aquisição da National Instruments) faz a interface de entrada/saída entre o simulador e o computador que executa MATLAB.
3. MATLAB adquire e processa as medições e as disponibiliza ao sistema web por uma integração a definir, por exemplo, uma API autenticada.
4. O dashboard recebe os dados via backend, registra leituras/histórico e apresenta estado e gráficos.

O caminho de controle deverá funcionar no sentido inverso: **Dashboard → backend → MATLAB → NI USB-6003 → Simulador**. O dashboard envia parâmetros autorizados; MATLAB valida/aplica o comando pela interface de aquisição e o sistema registra o resultado. O protocolo entre MATLAB e backend, o formato das mensagens, a estratégia de atualização em tempo real e os limites/validações dos sinais ainda precisam ser especificados. REST pode atender autenticação, configuração e histórico; WebSocket (ou Socket.IO) pode publicar atualizações ao dashboard.

Essa arquitetura ainda não está implementada. Atualmente o navegador consulta o histórico por polling a cada dois segundos quando há um backend disponível, e os scripts Python legados geram dados aleatórios e fazem requisições HTTP a cada cinco segundos. Não há comunicação com MATLAB, NI USB-6003 ou simulador físico.

## Alvo de implementação: migração para Laravel

A próxima etapa é substituir a estrutura estática e simulada por uma aplicação organizada em Laravel, preservando a experiência de monitoramento e controle, mas conectando-a a uma API e a dados reais. O frontend poderá ser integrado ao Laravel ou mantido como aplicação React; essa decisão deve ser consolidada na arquitetura da migração. A proposta funcional é:

### Backend (Laravel / PHP 8+)

- Criar uma API REST modular, validada e documentada para autenticação, leituras, parâmetros/comandos, histórico, logs e operações administrativas.
- Receber e registrar as medições publicadas pela integração MATLAB, com timestamp e origem/dispositivo de aquisição.
- Receber parâmetros do painel, armazenar os comandos pendentes e disponibilizá-los ao MATLAB; registrar confirmação/resultado da execução.
- Retornar séries históricas e estado do sistema para o dashboard.
- Usar Eloquent e consultas parametrizadas, validação de entrada, autorização por política/role, middleware de autenticação, limitação de requisições e HTTPS em produção.
- Usar MySQL ou PostgreSQL. Usar `Hash` para senhas e `Crypt`/OpenSSL para dados confidenciais que precisem ser recuperados; não tratar hash de senha como criptografia reversível.

### Contrato de comunicação a implementar

As rotas abaixo são uma proposta baseada nas chamadas já presentes no protótipo e nos scripts Python; ainda não estão implementadas nem constituem um contrato final:

| Operação | Rota proposta | Uso |
| --- | --- | --- |
| Registrar / autenticar | `POST /api/auth/register`, `POST /api/auth/login` | Criar sessão/credencial e autenticar usuários. |
| Receber leitura | `POST /api/readings` | Integração MATLAB envia identificador de origem, timestamp e variáveis de medição. |
| Consultar histórico | `GET /api/readings/history?limit=60` | Dashboard recupera leituras autorizadas. |
| Enfileirar comando | `POST /api/commands/add` | Interface registra parâmetros, por exemplo setpoint ou flag. |
| Buscar comandos | `GET /api/commands` | Integração MATLAB autenticada consulta comandos pendentes. |
| Confirmar comando | `POST /api/commands/ack` | Integração MATLAB registra o recebimento/resultado do comando. |
| Consultar logs | `GET /api/admin/logs` | Administrador consulta eventos de operação e segurança. |
| Restaurar backup | `POST /api/admin/restore` | Administrador autorizado inicia uma restauração auditada. |

As rotas de integração devem autenticar o serviço MATLAB com credenciais próprias, em vez de compartilhar o token demonstrativo. Os payloads, códigos de erro, paginação, estados do comando e associação entre leituras, origem de aquisição e usuário devem ser definidos antes da integração final.

Para atualizações em tempo real, implementar WebSocket (ou Socket.IO) para publicar novas leituras e mudanças de estado ao dashboard. REST continua adequado para autenticação, configuração de parâmetros, histórico e operações administrativas. Até essa implementação, a interface permanece em polling.

### Frontend

- Migrar a tela estática para componentes React organizados (ou Vue, se essa for a decisão do projeto), com JavaScript ES6+, Axios, TailwindCSS/Bootstrap e Chart.js/Recharts.
- Implementar autenticação real via sessão segura do Laravel ou JWT, protegendo rotas e removendo login rápido, tokens de exemplo e confiança em roles armazenadas no navegador.
- Manter formulários de controle e dashboard responsivos e acessíveis.
- Mostrar leituras em tempo real, séries históricas, média, variância, assimetria, curtose e identificação de outliers/anomalias, deixando claro o intervalo de dados e o método estatístico usado.
- Apresentar status de conexão, estado do dispositivo/controlador e mensagens de erro vindas da API.
- Restringir a interface administrativa a administradores; a autorização correspondente também deve ser aplicada no backend.

### Armazenamento, segurança e operação

- Criptografar antes de persistir os dados coletados que forem abrangidos pela política de confidencialidade (leituras, parâmetros e logs), mantendo chaves fora do repositório e gerenciando sua rotação. Descriptografar somente no caminho autorizado de exibição/uso.
- Registrar timestamp e identidade do usuário, serviço MATLAB ou origem de aquisição responsável, respeitando o modelo de dados aplicável a cada evento.
- Restringir logs, administração e restauração a usuários com perfil administrativo; usuários comuns podem controlar o sistema e consultar séries históricas autorizadas.
- Automatizar backups pelo menos diariamente, proteger os arquivos e testar periodicamente a restauração. Disponibilizar a restauração somente por fluxo administrativo auditado.
- Proteger credenciais e tráfego, validar e autorizar cada operação no servidor, armazenar senhas com hash seguro e registrar eventos de autenticação e controle sem expor segredos.

## Executar o protótipo

Abra `frontend/index.html` em um navegador para explorar a interface e as simulações locais. Algumas funcionalidades podem depender de servir os arquivos por HTTP, conforme a configuração do navegador. Sem um backend Laravel em `http://localhost:8000/api`, as tentativas de autenticação, histórico, controle e restauração não serão persistentes; o dashboard continuará usando os dados simulados do navegador.

Os scripts em `code_base/` requerem Python e a biblioteca `requests`, além de um backend compatível para que as chamadas tenham efeito. Configure endereço e credenciais de teste seguros antes de usá-los; os valores presentes são placeholders.

## Escopo deste repositório hoje

| Recurso | Situação |
| --- | --- |
| Interface de login, dashboard, controle e administração | Protótipo estático; login rápido e dados administrativos são demonstrativos. |
| Simulação PI da planta | Executada localmente no navegador; sem ligação com MATLAB ou hardware. |
| Simulação de leituras no dashboard | Fallback local quando o backend não está disponível. |
| Integração NI USB-6003/MATLAB | Planejada; não há integração de hardware ou servidor implementado. |
| Scripts Python de cliente HTTP | Exemplos legados que geram dados aleatórios; não representam a arquitetura pretendida. |
| API Laravel, autenticação e autorização reais | A implementar. |
| Banco de dados, criptografia de dados persistidos e logs reais | A implementar. |
| WebSocket, backups diários e restauração administrativa | A implementar. |
