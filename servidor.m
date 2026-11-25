clear all

% TCP server for temperature_client_node.m
node_ip = "127.0.0.1";
node_port = 2000;

% Configure these values before starting the server.
% Keep the system OFF unless it is safe to operate the connected hardware.
systemStatus = "OFF";
setpoint = 30.0;

server = tcpserver(node_ip, node_port);
configureTerminator(server, "LF");
disp("Server started at " + node_ip + ":" + string(node_port) + ...
    ". Waiting for client...");

try
    while ~server.Connected
        pause(0.1);
    end
    disp("Client connected.");

    while server.Connected
        if server.NumBytesAvailable > 0
            message = strtrim(readline(server));

            temperatureTokens = regexp(message, ...
                '^temperature=(-?\d+(?:\.\d+)?)$', 'tokens', 'once');
            if ~isempty(temperatureTokens)
                temperature = str2double(temperatureTokens{1});
                fprintf("Temperature received: %.2f C\n", temperature);

                response = sprintf("status=%s&setpoint=%.1f", ...
                    systemStatus, setpoint);
                writeline(server, response);
            else
                warning("Ignoring unrecognized client message: %s", message);
            end
        else
            pause(0.01);
        end
    end
catch ME
    disp("Connection closed or server error: " + ME.message);
end

clear server;
disp("Server stopped.");
