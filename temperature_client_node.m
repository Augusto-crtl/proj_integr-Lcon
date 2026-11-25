function temperature_client_node()
clear
clc

d = daq("ni");

ch1 = addinput(d, "Dev3","ai0","voltage");
ch1.TerminalConfig = "SingleEnded";

addoutput(d,"Dev3","ao0","Voltage"); %Atuador 2
addoutput(d,"Dev3","ao1","Voltage"); %Atuador 1

d.Rate = 100;

% MATLAB Wi-Fi Cliente Node com Fechamento Limpo
clear client;

node_ip = "127.0.0.1"; 
node_port = 2000;

fprintf("Tentando se conectar ao servidor Node.js em %s:%d...\n", node_ip, node_port);
client = tcpclient(node_ip, node_port);
fprintf("Conectado com sucesso!\n");

% =========================================================================
% CONFIGURAÇÃO DO FECHAMENTO LIMPO (onCleanup)
% =========================================================================
% Criamos um objeto que será destruído automaticamente quando o script parar.
% Quando destruído, ele executa a função anônima que limpa a conexão.
cleanupObj = onCleanup(@() limparConexao(client));
% =========================================================================

% Variáveis locais de controle
systemStatus = "OFF";
setpoint = 30.0; %25.0 
Ts = 1/d.Rate;
tau = 0.012732395447352;
a0 = 1.0 + (2.0*tau/Ts);
a1 = 1.0 - (2.0*tau/Ts);
xf0 = 0.0;
yf0 = 0.0;
Kp = 0.8;
Ki = 0.3; 
I = 0.0;
%currentTemp = 21.0;%esse mantem?
%ambientTemp = 21.0; %esse mantem?

%dt = 0.05; % Taxa desejada (20Hz) %esse mantem?

fprintf("Loop de controle iniciado. Pressione Ctrl+C para interromper com segurança.\n\n");

while true
    tic; 
    Dev3_1 = read(d);
    temp_V = Dev3_1.Dev3_ai0;

    temp_C = 10.0*temp_V;
    xf1 = xf0;
    xf0 = temp_C;
    yf1 = yf0;
    yf0 = (xf0+xf1-(a1*yf1))/a0;
    currentTemp = yf0;

    % 1. Executa a física do loop
    if strcmp(systemStatus, "ON")
        erro = setpoint - yf0;
        I = I+(erro*Ki*Ts);
        sinal_controle = (Kp*erro)+I;
        %heatingRate = 0.1 * (setpoint - currentTemp);
        %noise = (rand() - 0.5) * 0.05;
        %currentTemp = currentTemp + heatingRate + noise;
        if sinal_controle > 9.5
            sinal_controle = 9.5;
        end
        if sinal_controle < 0.0
            sinal_controle = 0.0;
        end
        sinal_ventilador = 2.5;
    else
        I = 0.0;
        sinal_controle = 0.0;
        sinal_ventilador = 0.0;
        %coolingRate = 0.05 * (ambientTemp - currentTemp);
        %currentTemp = currentTemp + coolingRate;
    end

    write(d,[sinal_controle sinal_ventilador]);
    % 2. Envia os dados para o Node.js
    payload = sprintf("temperature=%.2f\n", currentTemp);
    write(client, uint8(char(payload)));

    % 3. Verifica se há resposta do Node.js
    if client.NumBytesAvailable > 0
        response = read(client, client.NumBytesAvailable, "string");

        statusTokens = regexp(response, 'status=([^&|\n]+)', 'tokens');
        if ~isempty(statusTokens), systemStatus = statusTokens{1}{1}; end

        setpointTokens = regexp(response, 'setpoint=([\d.]+)', 'tokens');
        if ~isempty(setpointTokens), setpoint = str2double(setpointTokens{1}{1}); end
    end

    fprintf("Status: %s | Target: %.1f | Temp: %.2f°C\n", systemStatus, setpoint, currentTemp);

    % Compensa o tempo gasto na rede para manter os 20Hz cravados
    tempoGasto = toc;
    if tempoGasto < Ts
        pause(Ts - tempoGasto); 
    end
end
% =========================================================================
% FUNÇÃO DE LIMPEZA
% =========================================================================
function limparConexao(tcpObj)
fprintf('\n[CLEANUP] Interrupção detectada! Fechando conexão TCP de forma limpa...\n');
try
    dcOutput = 0;
    write(d,[dcOutput dcOutput]);
    clear d 

    % Deleta explicitamente o objeto cliente do ecossistema do MATLAB
    % Isso força o encerramento do Socket TCP a nível de sistema operacional
    delete(tcpObj); 
    fprintf('[CLEANUP] Porta liberada com sucesso no Node.js!\n');
catch
    fprintf('[CLEANUP] Erro ou conexão já estava fechada.\n');
end
end
end