<?php
/**
 * ===============
 * RobotAdaptativo
 * ===============
 * El robot que "aprende" de vos, sin machine learning: usa tu media por botón y tu mediana, que calcula RepositorioJuego con consultas SQL.
 *
 *  - Ataca el botón donde el jugador es MÁS LENTO...
 *  - ...salvo con probabilidad = temperatura, que elige al azar.
 *  - La ventana de tiempo sale de la mediana del jugador: cuanto más rápido reacciona, menos margen le da el robot.
 */
class RobotAdaptativo extends Robot   // [POO · HERENCIA]
{
    const FACTOR_INICIAL    = 2.0;   // ronda 1: ventana = 2.0 x mediana
    const FACTOR_FINAL      = 1.3;   // última ronda: ventana = 1.3 x mediana
    const VENTANA_MAXIMA_MS = 2000;

    const SENUELO_INICIAL = 0.10;   // ronda 1: 10% de probabilidad de señuelo
    const SENUELO_FINAL   = 0.45;   // última ronda: 45%

    public function nombre()
    {
        return 'Robot adaptativo';
    }

    // [POO · POLIMORFISMO]
    // Cada robot responde a su manera al mismo mensaje tipo(). Este valor se guarda en partidas.tipo_robot, así que tiene que coincidir con el ENUM.
    public function tipo()
    {
        return 'adaptativo';
    }

    // [POO · POLIMORFISMO]
    // Mismo método y misma firma que en RobotAleatorio, pero otra estrategia: acá sí usa la temperatura y el perfil del jugador.
    protected function elegirBoton($azar, $botonAlAzar, $temperatura, array $mediasPorBoton)
    {
        // Con probabilidad "temperatura" (o si aún no hay datos del jugador), el robot se comporta al azar.
        if ($azar < $temperatura || empty($mediasPorBoton)) {
            return $botonAlAzar;
        }

        // Si no, elige el botón con mayor media de reacción (el más lento).
        arsort($mediasPorBoton);
        return (int) key($mediasPorBoton);
    }

    protected function calcularVentana($numeroRonda, $medianaMs)
    {
        // Jugador nuevo, sin historial: usamos la ventana fija heredada.
        if ($medianaMs === null) {
            return $this->ventanaFija($numeroRonda);
        }

        // El factor baja de FACTOR_INICIAL a FACTOR_FINAL a lo largo de la partida, sin importar cuántas rondas tenga.
        $factor = self::FACTOR_INICIAL
            - (self::FACTOR_INICIAL - self::FACTOR_FINAL) * $this->progresoPartida($numeroRonda);
        $ventanaMs = (int) round($medianaMs * $factor);

        return max(self::VENTANA_MINIMA_MS, min(self::VENTANA_MAXIMA_MS, $ventanaMs));
    }

    // [POO · POLIMORFISMO]
    // Mismo mensaje que en RobotAleatorio, otra respuesta: acá la probabilidad SUBE con el progreso de la partida,
    // igual que la temperatura (misma curva lineal, con sus propios extremos).
    protected function probabilidadSenuelo($numeroRonda)
    {
        return self::SENUELO_INICIAL
            + (self::SENUELO_FINAL - self::SENUELO_INICIAL) * $this->progresoPartida($numeroRonda);
    }
}