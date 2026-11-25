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

d = daq("ni");
ch1 = addinput(d, "Dev3", "ai0", "voltage");
ch1.TerminalConfig = "SingleEnded";
addoutput(d, "Dev3", "ao0", "Voltage");
addoutput(d, "Dev3", "ao1", "Voltage");
d.Rate = 100;
write(d, [0 0]);

readingQueue = parallel.pool.PollableDataQueue;
stateQueue = parallel.pool.PollableDataQueue;
workerFuture = parfeval(backgroundPool, @laravelApiWorker, 0, ...
    readingQueue, stateQueue, apiBaseUrl, apiToken);
cleanupObj = onCleanup(@() cleanupController(d, workerFuture));

systemStatus = "OFF";
setpoint = 30.0;
telemetryInterval = 1.0;
workerHeartbeatTimeout = 3.0;
lastTelemetrySent = tic;
lastWorkerHeartbeat = tic;

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
        workerError = workerFuture.Error;
        if isempty(workerError)
            error("temperature_client_node:ApiWorkerStopped", ...
                "The Laravel API background worker stopped without reporting an exception.");
        end
        error("temperature_client_node:ApiWorkerStopped", ...
            "The Laravel API background worker stopped unexpectedly:%s%s", ...
            newline, getReport(workerError, "extended", "hyperlinks", "off"));
    end

    if toc(lastWorkerHeartbeat) >= workerHeartbeatTimeout
        systemStatus = "OFF";
    end

    measurement = read(d);
    temp_C = 10.0 * measurement.Dev3_ai0;
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

function laravelApiWorker(readingQueue, stateQueue, apiBaseUrl, apiToken)
apiOptions = weboptions( ...
    "MediaType", "application/json", ...
    "Timeout", 1, ...
    "HeaderFields", {"Authorization", ['Bearer ' char(apiToken)]});

commandPollInterval = 1.0;
readingPostInterval = 1.0;
heartbeatInterval = 0.5;
systemStatus = "OFF";
setpoint = 30.0;
commandsApiOk = true;
readingsApiOk = true;
apiFailureReported = false;
mustRearmAfterApiFailure = false;
hasReading = false;
latestReading = struct();
lastCommandPoll = tic;
lastReadingPost = tic;
lastHeartbeat = tic;

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

    if toc(lastCommandPoll) >= commandPollInterval
        lastCommandPoll = tic;
        try
            commandResponse = webread(apiBaseUrl + "/commands", apiOptions);
            if ~isstruct(commandResponse) || ~isfield(commandResponse, "commands")
                error("temperature_client_node:InvalidCommandResponse", ...
                    "GET /commands must return a JSON object with a commands array.");
            end

            commands = commandResponse.commands;
            rejectActivation = mustRearmAfterApiFailure;
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
                                "Resubmit activation after the API connection recovers.";
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
                webwrite(apiBaseUrl + "/commands/ack", acknowledgement, apiOptions);
            end
            commandsApiOk = true;
        catch ME
            markApiUnavailable("Laravel command API unavailable", ME);
            commandsApiOk = false;
            readingsApiOk = false;
            mustRearmAfterApiFailure = true;
        end
    end

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
            mustRearmAfterApiFailure = true;
        end
    end

    if commandsApiOk && readingsApiOk
        if apiFailureReported
            disp("Laravel API connection recovered.");
            apiFailureReported = false;
        end
        mustRearmAfterApiFailure = false;
    end

    if toc(lastHeartbeat) >= heartbeatInterval
        publishState();
        lastHeartbeat = tic;
    end
end

    function markApiUnavailable(message, exception)
        systemStatus = "OFF";
        if ~apiFailureReported
            warning("%s; forcing system OFF: %s", message, exception.message);
            apiFailureReported = true;
        end
        publishState();
    end

    function publishState()
        send(stateQueue, struct( ...
            "status", char(systemStatus), ...
            "setpoint", setpoint));
    end
end

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
