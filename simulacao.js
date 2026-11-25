// Ajuste K_PLANTA e TAU_PLANTA conforme a função de transferência do kit do seu grupo
const K_PLANTA = 7.5;    // Ganho estático (°C / V)
const TAU_PLANTA = 25.0; // Constante de tempo (s)
const TS = 0.2;          // Passo de simulação (s)

function executarSimulacaoPI(params) {
  const a = Math.exp(-TS / TAU_PLANTA);
  const b = K_PLANTA * (1 - a);

  let temp = params.tempInicial;
  let integral = 0;
  let perturbacaoAplicada = false;

  const tempos = [];
  const temperaturas = [];
  const setpoints = [];
  const erros = [];
  const sinaisControle = [];

  const totalPassos = Math.floor(params.tempoFinal / TS);

  for (let k = 0; k <= totalPassos; k++) {
    const t = Number((k * TS).toFixed(1));
    const spAtual = t >= params.instanteMudancaSp ? params.sp2 : params.sp1;

    if (t >= params.instantePerturbacao && !perturbacaoAplicada) {
      temp += params.perturbacao;
      perturbacaoAplicada = true;
    }

    const erro = spAtual - temp;
    integral += erro * TS;
    let u = params.kp * erro + params.ki * integral;

    // Saturação física do aquecedor (0 a 10 V) + anti-windup
    if (u > 10) {
      u = 10;
      integral -= erro * TS;
    } else if (u < 0) {
      u = 0;
      integral -= erro * TS;
    }

    tempos.push(t);
    temperaturas.push(Number(temp.toFixed(2)));
    setpoints.push(spAtual);
    erros.push(Number(erro.toFixed(2)));
    sinaisControle.push(Number(u.toFixed(2)));

    const cargaPerturbacao = t >= params.instantePerturbacao ? (1 - a) * params.perturbacao : 0;
    const deltaT = temp - params.tempInicial;
    temp = params.tempInicial + a * deltaT + b * u + cargaPerturbacao;
  }

  return { tempos, temperaturas, setpoints, erros, sinaisControle };
}
