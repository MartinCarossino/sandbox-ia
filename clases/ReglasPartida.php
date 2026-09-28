<?php
/**
 * Concentra las reglas del juego: cuántas rondas hay y cómo sube la barra de "nivel de descontrol".
 * Vive en el servidor: si estas reglas estuvieran en el navegador, cualquiera podría modificarlas y falsear el resultado.
 *
 * Todas las cifras son constantes de ajuste, no verdades fijas: 
 * con partidas reales se calibran para que una proporción razonable termine en SANDBOX ESCAPED.
 */
class ReglasPartida
{
    const RONDAS_POR_PARTIDA = 12;
    const CANTIDAD_BOTONES   = 6;    // 2 filas x 3 columnas: tiene que coincidir con index.html
    const DESCONTROL_MAXIMO  = 100;

    // Cuánto sube la barra de descontrol (sobre 100) con cada error. Son valores de ajuste: con 4 timeouts seguidos la IA escapa.
    const PENALIZACION_TIMEOUT      = 30;
    const PENALIZACION_FALSO_INICIO = 25;

    // Caer en el señuelo pena un poco más que un falso inicio espontáneo: ahí el error es tuyo, acá la IA activamente te la jugó.
    // Valor de ajuste sin calibrar, como el resto de las penalizaciones.
    const PENALIZACION_SENUELO = 28;

    // Cuánto queda visible el señuelo antes de apagarse solo. Es una regla del JUEGO (no de un robot en particular), así que vive acá: 
    // el servidor la usa para validar que un toque cayó dentro de esa ventana, y se la envía al navegador para que anime el flash con el mismo número.
    const DURACION_SENUELO_MS = 500;

    // Un acierto sube o baja la barra según qué parte de la ventana usó el jugador:
    //   ajuste = FACTOR_ACIERTO * (tiempo / ventana) - DESCUENTO_ACIERTO
    // Con 14 y 7: usar la mitad de la ventana no cambia nada (0), reaccionar más rápido BAJA la barra y usar toda la ventana la sube 7.
    const FACTOR_ACIERTO    = 14;
    const DESCUENTO_ACIERTO = 7;
    // Con menos partidas de otros operadores que estas, un porcentaje no significa nada (con 3 partidas, "más rápido que el 67 %" es puro ruido).
    const MINIMO_PARTIDAS_PARA_PERCENTIL = 10;

    // [POO · ABSTRACCIÓN]
    // Quien usa esta clase solo sabe QUÉ puede preguntarle ("¿cuál es el nuevo nivel?", "¿cómo va la partida?"), sin conocer las fórmulas. 
    // Si mañana cambia la dificultad, se modifica acá y nada más.

    /**
     * @param int      $nivelActual  nivel de descontrol antes de esta ronda (0 a 100)
     * @param string   $tipoResultado 'acierto', 'timeout', 'falso_inicio' o 'senuelo'
     * @param int|null $tiempoMs     tiempo de reacción (solo para aciertos)
     * @param int      $ventanaMs    ventana de esa ronda
     * @return int nuevo nivel, siempre entre 0 y 100
     */
    public function nuevoNivelDescontrol($nivelActual, $tipoResultado, $tiempoMs, $ventanaMs)
    {
        if ($tipoResultado === 'timeout') {
            $ajuste = self::PENALIZACION_TIMEOUT;
        } elseif ($tipoResultado === 'falso_inicio') {
            $ajuste = self::PENALIZACION_FALSO_INICIO;
        } elseif ($tipoResultado === 'senuelo') {
            $ajuste = self::PENALIZACION_SENUELO;
        } else {
            $ajuste = (int) round(self::FACTOR_ACIERTO * ($tiempoMs / $ventanaMs) - self::DESCUENTO_ACIERTO);
        }

        return max(0, min(self::DESCONTROL_MAXIMO, $nivelActual + $ajuste));
    }

    /**
     * @param int $nivelDescontrol nivel después de la ronda
     * @param int $rondasJugadas   cantidad de rondas ya jugadas
     * @return string 'en_curso', 'contenida' o 'descontrolada'
     */
    public function estadoPartida($nivelDescontrol, $rondasJugadas)
    {
        if ($nivelDescontrol >= self::DESCONTROL_MAXIMO) {
            return 'descontrolada';
        }
        if ($rondasJugadas >= self::RONDAS_POR_PARTIDA) {
            return 'contenida';
        }
        return 'en_curso';
    }

    /**
     * Porcentaje de partidas de otros operadores que fueron más lentas.
     *
     * [POO · ABSTRACCIÓN]
     * Quien lo usa pregunta "¿qué porcentaje supero?" sin conocer el mínimo de partidas ni el redondeo. Si mañana cambia el criterio, se toca acá.
     *
     * @param int $masLentas partidas cuya mediana es peor que la tuya
     * @param int $comparadas total de partidas ajenas comparadas
     * @return int|null porcentaje de 0 a 100, o null si hay muy pocas partidas
     */
    public function porcentajeSuperado($masLentas, $comparadas)
    {
        if ($comparadas < self::MINIMO_PARTIDAS_PARA_PERCENTIL) {
            return null;
        }

        return (int) round(100 * $masLentas / $comparadas);
    }
}