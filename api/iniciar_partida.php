<?php
// POST: token_jugador ('' si es nuevo), alias, dispositivo ('pc' o 'movil')
// Devuelve: id_jugador, token_jugador, id_partida, rondas_totales, nivel_descontrol y la ronda 1.
require_once dirname(__FILE__) . '/_comun.php';

ejecutarEndpoint(function () {
    $tokenJugador = (string) parametro('token_jugador', '');
    $alias        = (string) parametro('alias', '');
    $dispositivo  = (string) parametro('dispositivo', '');

    return crearServicio()->iniciar($tokenJugador, $alias, $dispositivo);
});