% Inicializa a DAQ e separa o controle de alta frequência das chamadas HTTP.
function temperature_client_node()
clc

apiBaseUrl = getenv("MATLAB_LARAVEL_API_URL");
if isempty(apiBaseUrl)
    error("temperature_client_node:MissingApiUrl", ...
        "Set MATLAB_LARAVEL_API_URL to the reachable Laravel server URL.");
end
apiBaseUrl = strip(string(strtrim(apiBaseUrl)), "/");

apiToken = strtrim(getenv("MATLAB_LARAVEL_API_TOKEN"));
if isempty(apiToken)
    error("temperature_client_node:MissingApiToken", ...
        "Set MATLAB_LARAVEL_API_TOKEN before starting the controller.");
end

% Configura canais físicos e zera as saídas antes de habilitar o controle.
d = daq("ni");
ch1 = addinput(d, "Dev1", "ai0", "voltage");
ch1.TerminalConfig = "SingleEnded";
addoutput(d, "Dev1", "ao0", "Voltage");
addoutput(d, "Dev1", "ao1", "Voltage");
d.Rate = 100;
write(d, [0 0]);

% Usa filas e worker para que a comunicação com Laravel não bloqueie o loop DAQ.
readingQueueReady = parallel.pool.PollableDataQueue;
stateQueue = parallel.pool.PollableDataQueue;
workerFuture = parfeval(backgroundPool, @laravelApiWorker, 0, ...
    readingQueueReady, stateQueue, apiBaseUrl, apiToken);
cleanupObj = onCleanup(@() cleanupController(d, workerFuture));
readingQueue = poll(readingQueueReady, 5);
if isempty(readingQueue)
    assertWorkerRunning(workerFuture);
    error("temperature_client_node:ApiWorkerNotReady", ...
        "The Laravel API background worker did not initialize its reading queue.");
end

systemStatus = "OFF";
setpoint = 30.0;
telemetryInterval = 1.0;
workerHeartbeatTimeout = 6.0; % Mantém a saída segura por mais tempo durante requisições lentas.
lastTelemetrySent = tic;
lastWorkerHeartbeat = tic;

% Define período, filtro digital e ganhos usados pelo controlador PI local.
Ts = 1/d.Rate;
tau = 0.012732395447352;
a0 = 1.0 + (2.0*tau/Ts);
a1 = 1.0 - (2.0*tau/Ts);
xf0 = 0.0;
yf0 = 0.0;
Kp = 0.8;
Ki = 0.3;
I = 0.0;

fprintf("Control loop started. Laravel API updates are handled in the background.\n");

while true
    loopTimer = tic;

    stateUpdate = poll(stateQueue, 0);
    while ~isempty(stateUpdate)
        if isstruct(stateUpdate) && isfield(stateUpdate, "log") % Detecta mensagens de falha enviadas pelo worker.
            fprintf(2, "[API worker] %s\n", stateUpdate.log); % Mostra a falha no fluxo de erro.
            stateUpdate = poll(stateQueue, 0); % Lê a próxima atualização pendente.
            continue % Evita interpretar o registro como estado do sistema.
        end
        if isstruct(stateUpdate) && ...
                isfield(stateUpdate, "status") && ...
                isfield(stateUpdate, "setpoint")
            candidateStatus = string(stateUpdate.status);
            candidateSetpoint = stateUpdate.setpoint;
            if (candidateStatus == "ON" || candidateStatus == "OFF") && ...
                    isnumeric(candidateSetpoint) && isscalar(candidateSetpoint) && ...
                    isfinite(candidateSetpoint) && ...
                    candidateSetpoint >= 20 && candidateSetpoint <= 99
                systemStatus = candidateStatus;
                setpoint = candidateSetpoint;
                lastWorkerHeartbeat = tic;
            end
        end
        stateUpdate = poll(stateQueue, 0);
    end

    if strcmp(workerFuture.State, "finished")
        assertWorkerRunning(workerFuture);
    end

    if toc(lastWorkerHeartbeat) >= workerHeartbeatTimeout
        systemStatus = "OFF";
    end

    % Mede e filtra a temperatura, calcula a ação PI e atualiza as saídas físicas.
    measurement = read(d);  
    temp_C = 10.0 * measurement.Dev1_ai0;
    xf1 = xf0;
    xf0 = temp_C;
    yf1 = yf0;
    yf0 = (xf0 + xf1 - (a1 * yf1)) / a0;
    currentTemp = yf0;

    if strcmp(systemStatus, "ON")
        erro = setpoint - yf0;
        I = I + (erro * Ki * Ts);
        sinal_controle = (Kp * erro) + I;
        sinal_controle = min(max(sinal_controle, 0.0), 9.5);
        sinal_ventilador = 2.5;
    else
        I = 0.0;
        sinal_controle = 0.0;
        sinal_ventilador = 0.0;
    end

    write(d, [sinal_controle sinal_ventilador]);

    if toc(lastTelemetrySent) >= telemetryInterval
        send(readingQueue, struct( ...
            "temp", currentTemp, ...
            "system_status", char(systemStatus), ...
            "setpoint", setpoint));
        lastTelemetrySent = tic;
    end

    fprintf("Status: %s | Target: %.1f | Temp: %.2f C\n", ...
        systemStatus, setpoint, currentTemp);

    elapsed = toc(loopTimer);
    if elapsed < Ts
        pause(Ts - elapsed);
    end
end
end

function assertWorkerRunning(workerFuture)
if strcmp(workerFuture.State, "finished")
    workerError = workerFuture.Error;
    if isempty(workerError)
        error("temperature_client_node:ApiWorkerStopped", ...
            "The Laravel API background worker stopped without reporting an exception.");
    end
    error("temperature_client_node:ApiWorkerStopped", ...
        "The Laravel API background worker stopped unexpectedly:%s%s", ...
        newline, getReport(workerError, "extended", "hyperlinks", "off"));
end
end

% Consulta comandos e publica telemetria fora do ciclo físico de 100 Hz.
function laravelApiWorker(readingQueueReady, stateQueue, apiBaseUrl, apiToken)
readingQueue = parallel.pool.PollableDataQueue;
send(readingQueueReady, readingQueue);

apiOptions = weboptions( ...
    "MediaType", "application/json", ... % Define o corpo das chamadas como JSON.
    "HeaderFields", string({'Authorization', ['Bearer ' char(apiToken)]; ...
                            'Accept', 'application/json'}), ... % Solicita respostas JSON autenticadas.
    "Timeout", 3); % Define o limite de espera das requisições.

commandPollInterval = 1.0;
readingPostInterval = 1.0;
heartbeatInterval = 0.5;
systemStatus = "OFF";
setpoint = 30.0;
commandsApiOk = true;
readingsApiOk = true;
apiFailureReported = false;
requireFreshActivation = true;
activeUserId = []; % Guarda o usuário do último comando aplicado para associar as leituras desta sessão.
hasReading = false;
latestReading = struct();
lastCommandPoll = tic;
lastReadingPost = tic;
lastHeartbeat = tic;
commandsDrained = false;

publishState();

while true
    newReading = poll(readingQueue, 0.1);
    while true
        queuedReading = poll(readingQueue, 0);
        if isempty(queuedReading)
            break;
        end
        newReading = queuedReading;
    end
    if ~isempty(newReading)
        latestReading = newReading;
        hasReading = true;
    end

    commandsDrained = false;
    % Processa comandos pendentes e exige nova ativação após falha ou inicialização.
    if toc(lastCommandPoll) >= commandPollInterval
        lastCommandPoll = tic;
        try
            commandResponse = webread(apiBaseUrl + "/commands", apiOptions);
            if ~isstruct(commandResponse) || ~isfield(commandResponse, "commands")
                error("temperature_client_node:InvalidCommandResponse", ...
                    "GET /commands must return a JSON object with a commands array.");
            end

            commands = commandResponse.commands;
            commandsDrained = isempty(commands);
            rejectActivation = requireFreshActivation;
            if rejectActivation
                systemStatus = "OFF";
            end

            for commandIndex = 1:numel(commands)
                if iscell(commands)
                    command = commands{commandIndex};
                else
                    command = commands(commandIndex);
                end

                if ~isstruct(command) || ...
                        ~isfield(command, "id") || ...
                        ~isfield(command, "name") || ...
                        ~isfield(command, "value")
                    error("temperature_client_node:InvalidCommand", ...
                        "Each command must contain id, name, and value.");
                end

                commandName = string(command.name);
                commandValue = command.value;
                if isfield(command, "user_id") && ~isempty(command.user_id) % Lê a identidade humana associada ao comando enfileirado.
                    activeUserId = command.user_id; % Mantém o autor do comando para incluir na telemetria subsequente.
                end % Finaliza a atualização do usuário da sessão de controle.
                acknowledgementStatus = "applied";
                rejectionReason = "";

                if ~((isnumeric(commandValue) || islogical(commandValue)) && ...
                        isscalar(commandValue) && isfinite(double(commandValue)))
                    acknowledgementStatus = "rejected";
                    rejectionReason = "Command value must be a finite number.";
                elseif commandName == "setpoint"
                    commandValue = double(commandValue);
                    if commandValue < 20 || commandValue > 99
                        acknowledgementStatus = "rejected";
                        rejectionReason = "Setpoint must be between 20 and 99 C.";
                    else
                        setpoint = commandValue;
                    end
                elseif commandName == "flag"
                    commandValue = double(commandValue);
                    if commandValue == 0
                        systemStatus = "OFF";
                    elseif commandValue == 1
                        if rejectActivation
                            acknowledgementStatus = "rejected";
                            rejectionReason = ...
                                "Submit activation again after the API connection is available.";
                        else
                            systemStatus = "ON";
                        end
                    else
                        acknowledgementStatus = "rejected";
                        rejectionReason = "Flag must be 0 (OFF) or 1 (ON).";
                    end
                else
                    acknowledgementStatus = "rejected";
                    rejectionReason = "Unsupported command name.";
                end

                acknowledgement = struct( ...
                    "id", command.id, ...
                    "status", char(acknowledgementStatus));
                if strlength(rejectionReason) > 0
                    acknowledgement.reason = char(rejectionReason);
                end
                webwrite(apiBaseUrl + "/commands/ack", acknowledgement, apiOptions); % Confirma o comando processado.
                publishState(); % Atualiza o controlador após cada confirmação.
            end
            commandsApiOk = true;
        catch ME
            markApiUnavailable("Laravel command API unavailable", ME);
            commandsApiOk = false;
            readingsApiOk = false;
            requireFreshActivation = true;
        end
    end

    % Publica a amostra mais recente com timestamp UTC e usuário da sessão de controle.
    if hasReading && toc(lastReadingPost) >= readingPostInterval
        lastReadingPost = tic;
        try
            timestamp = char(datetime("now", ...
                "TimeZone", "UTC", ...
                "Format", "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'"));
            variables = struct( ...
                "temp", latestReading.temp, ...
                "system_status", char(systemStatus), ...
                "setpoint", setpoint);
            if ~isempty(activeUserId) % Inclui o usuário somente quando há uma sessão vinculada a um comando aplicado.
                variables.user_id = activeUserId; % Evita serializar vetor MATLAB vazio como campo inválido no JSON.
            end % Finaliza a inclusão condicional da identidade da sessão.
            reading = struct( ...
                "source", "matlab-ni-usb-6003", ...
                "recorded_at", timestamp, ...
                "variables", variables);
            webwrite(apiBaseUrl + "/readings", reading, apiOptions);
            readingsApiOk = true;
        catch ME
            markApiUnavailable("Laravel readings API unavailable", ME);
            commandsApiOk = false;
            readingsApiOk = false;
            systemStatus = "OFF";
            requireFreshActivation = true;
        end
    end

    if commandsApiOk && readingsApiOk
        if apiFailureReported
            disp("Laravel API connection recovered.");
            apiFailureReported = false;
        end
        if commandsDrained
            requireFreshActivation = false;
        end
    end

    if toc(lastHeartbeat) >= heartbeatInterval
        publishState();
        lastHeartbeat = tic;
    end
end

    % Desliga o controle na falha da API e comunica o erro ao loop DAQ.
    function markApiUnavailable(message, exception)
        systemStatus = "OFF"; % Desliga o controlador após uma falha de API.
        send(stateQueue, struct("log", string(message) + ": " + exception.message)); % Envia o erro ao loop principal.
        apiFailureReported = true; % Marca a falha para controlar a recuperação.
        publishState(); % Propaga o estado OFF ao controlador.
    end

    % Propaga estado e setpoint atuais do worker para o controlador físico.
    function publishState()
        send(stateQueue, struct( ...
            "status", char(systemStatus), ...
            "setpoint", setpoint));
    end
end

% Ao encerrar, tenta zerar as saídas e interromper o worker em segundo plano.
function cleanupController(daqObj, workerFuture)
fprintf('\nStopping controller and Laravel API worker...\n');
try
    write(daqObj, [0 0]);
catch ME
    fprintf('Could not set DAQ outputs to zero: %s\n', ME.message);
end

try
    cancel(workerFuture);
catch ME
    fprintf('Could not stop Laravel API worker: %s\n', ME.message);
end
end
