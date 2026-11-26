// Calcula estatísticas descritivas e evita estimativas instáveis em séries curtas.
function calcularEstatisticas(valores) {
  const n = valores.length;
  if (n < 4) return null;

  const media = valores.reduce((acc, v) => acc + v, 0) / n;
  // Usa variância amostral e desvio padrão para normalizar as demais medidas.
  const variancia =
    valores.reduce((acc, v) => acc + Math.pow(v - media, 2), 0) / (n - 1);
  const dp = Math.sqrt(variancia);

  const assimetria =
    dp > 0 ? valores.reduce((acc, v) => acc + Math.pow((v - media) / dp, 3), 0) / n : 0;
  const curtose =
    dp > 0 ? valores.reduce((acc, v) => acc + Math.pow((v - media) / dp, 4), 0) / n - 3 : 0;

  const outliers = valores.filter((v) => dp > 0 && Math.abs((v - media) / dp) > 2.5).length;

  return {
    media: media.toFixed(2),
    variancia: variancia.toFixed(2),
    assimetria: assimetria.toFixed(3),
    curtose: curtose.toFixed(3),
    outliers,
  };
}
