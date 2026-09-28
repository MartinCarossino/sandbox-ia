<?php
// POST: numero_ronda, tipo_resultado ('acierto', 'timeout', 'falso_inicio' o 'senuelo'),
//       tiempo_reaccion_ms (solo para aciertos)
// Devuelve: tipo_resultado, tiempo_reaccion_ms, sospechosa, nivel_descontrol,
//           estado_partida ('en_curso', 'contenida', 'descontrolada' o 'anulada'),
//           siguiente_ronda (null si la partida terminó) y percentil (solo al terminar).
require_once dirname(__FILE__) . '/_comun.php';

ejecutarEndpoint(function () {
    $numeroRonda    = (int) parametro('numero_ronda', 0);
    $tipoResultado  = (string) parametro('tipo_resultado', '');
    $tiempoReaccion = parametro('tiempo_reaccion_ms', null);

    if ($tiempoReaccion === '') {
        $tiempoReaccion = null;
    }
    if ($tiempoReaccion !== null && !is_numeric($tiempoReaccion)) {
        throw new InvalidArgumentException('tiempo_reaccion_ms debe ser numérico.');
    }
    if ($tiempoReaccion !== null) {
        $tiempoReaccion = (float) $tiempoReaccion;
    }

    return crearServicio()->registrarRonda($numeroRonda, $tipoResultado, $tiempoReaccion);
});