<?php
/**
 * ===============
 * ServicioPartida
 * ===============
 * Orquesta una partida completa:
 *   - el Robot decide cómo es cada ronda,
 *   - las ReglasPartida deciden cuánto sube el descontrol,
 *   - el RepositorioJuego guarda todo en la base.
 * Los endpoints (api/*.php) solo reciben datos y llaman a esta clase.
 *
 * El estado de la partida vive en $_SESSION, o sea, en el servidor. El
 * navegador nunca ve la semilla ni puede pedir una ronda que no le toca.
 */
class ServicioPartida
{
    const CLAVE_SESION = 'juego';

    // Diferencia tolerada entre relojes (servidor y navegador) al validar tiempos.
    const TOLERANCIA_MS = 50;

    // Margen extra sobre (retardo + ventana) antes de considerar sospechosa una respuesta que tardó demasiado en llegar.
    // Cubre la red y la pausa que la interfaz hace entre rondas (la pausa de la interfaz debe ser menor a esto).
    const MARGEN_MAXIMO_MS = 5000;

    // Tope GLOBAL de partidas nuevas (sumando a todos los jugadores) dentro de una ventana de minutos.
    // Con el tráfico esperado (2 personas a la vez, unas 30 partidas cada 10 minutos como mucho) nunca se alcanza;
    // frena a un script que intente llenar la base.
    const MAXIMO_PARTIDAS_RECIENTES = 60;
    const VENTANA_PARTIDAS_MINUTOS  = 10;

    // [POO · ENCAPSULAMIENTO]
    // Las piezas que usa el servicio son PRIVADAS, igual que los métodos sortearTipoRobot(), robotDeLaPartida(), entregarRonda() y esTiempoSospechoso().
    // Desde afuera solo se pueden usar iniciar() y registrarRonda(). 
    // Así el navegador no puede pedir la ronda 5 sin haber jugado la 4, ni elegir contra qué robot juega, ni saltarse la validación de tiempos.
    private $repositorio;
    private $robots;    // array( 'adaptativo' => Robot, 'aleatorio' => Robot ), indexado por tipo
    private $reglas;

    // [POO · POLIMORFISMO]
    // Recibe una LISTA de robots y no pregunta cómo es cada uno: solo usa lo que todos saben hacer (tipo() y prepararRonda()). 
    // Agregar un tercer robot es sumarlo a la lista, sin tocar esta clase.
    public function __construct(RepositorioJuego $repositorio, array $robots, ReglasPartida $reglas)
    {
        if (empty($robots)) {
            throw new InvalidArgumentException('Hace falta al menos un robot.');
        }

        $this->repositorio = $repositorio;
        $this->reglas      = $reglas;

        // Se indexan por tipo: así, con el tipo guardado en la sesión, se
        // encuentra el robot de la partida sin recorrer la lista.
        $this->robots = array();
        foreach ($robots as $robot) {
            if (!($robot instanceof Robot)) {
                throw new InvalidArgumentException('Todos los elementos de la lista tienen que ser un Robot.');
            }
            $this->robots[$robot->tipo()] = $robot;
        }
    }

    /**
     * Crea (o reconoce) al jugador, crea la partida y entrega la ronda 1.
     *
     * @param string $tokenJugador token guardado en el navegador ('' si no tiene)
     * @param string $alias        se usa al crear el jugador y, si cambió, para renombrarlo
     * @param string $dispositivo  'pc' o 'movil'
     * @return array
     */
    public function iniciar($tokenJugador, $alias, $dispositivo)
    {
        // Validamos ANTES de escribir en la base, para no dejar jugadores huérfanos.
        if (!in_array($dispositivo, array('pc', 'movil'), true)) {
            throw new InvalidArgumentException('Dispositivo inválido.');
        }

        // Tope global: se controla ANTES de crear el jugador o la partida, así un pedido rechazado no deja nada escrito en la base.
        $partidasRecientes = $this->repositorio->contarPartidasRecientes(self::VENTANA_PARTIDAS_MINUTOS);
        if ($partidasRecientes >= self::MAXIMO_PARTIDAS_RECIENTES) {
            throw new RuntimeException('El robot está saturado. Probá de nuevo en unos minutos.');
        }

        // El navegador se identifica con su token (la llave secreta), nunca con el id: los ids son correlativos y cualquiera podría usar el de otro.
        // Token vacío, inventado o de un jugador que no existe = jugador nuevo.
        $idJugador = $this->repositorio->obtenerIdJugadorPorToken($tokenJugador);

        if ($idJugador === null) {
            $jugadorNuevo = $this->repositorio->crearJugador($alias);
            $idJugador    = $jugadorNuevo['id_jugador'];
            $tokenJugador = $jugadorNuevo['token'];
        } else {
            // El navegador ya tenía un jugador guardado: si escribió otro alias, se actualiza.
            // Sin esto, el alias nuevo se guardaba en el navegador pero la base seguía con el viejo.
            $this->repositorio->actualizarAlias($idJugador, $alias);
        }

        $semilla   = mt_rand(1, 2147483646);
        $tipoRobot = $this->sortearTipoRobot();
        $medias    = $this->repositorio->obtenerMediasPorBoton($idJugador);
        $mediana   = $this->repositorio->obtenerMedianaJugador($idJugador);
        $idPartida = $this->repositorio->crearPartida($idJugador, $dispositivo, $semilla, $tipoRobot);

        // El perfil del jugador se lee UNA vez, al empezar: el robot no cambia de estrategia a mitad de partida.
        // Tampoco cambia de robot: el tipo sorteado queda guardado en la sesión, que vive en el servidor.
        $_SESSION[self::CLAVE_SESION] = array(
            'id_partida'       => $idPartida,
            'semilla'          => $semilla,
            'tipo_robot'       => $tipoRobot,
            'medias'           => $medias,
            'mediana'          => $mediana,
            'nivel_descontrol' => 0,
            'ronda_pendiente'  => null
        );

        return array(
            'id_jugador'       => $idJugador,
            'token_jugador'    => $tokenJugador,
            'id_partida'       => $idPartida,
            'rondas_totales'   => ReglasPartida::RONDAS_POR_PARTIDA,
            'nivel_descontrol' => 0,
            'ronda'            => $this->entregarRonda(1)
        );
    }

    /**
     * Valida y guarda el resultado de la ronda pendiente.
     * Si la partida sigue, devuelve también la ronda siguiente (un solo viaje de red por ronda).
     *
     * @param int        $numeroRonda      la ronda que el navegador dice estar respondiendo
     * @param string     $tipoResultado    'acierto', 'timeout', 'falso_inicio' o 'senuelo'
     * @param float|null $tiempoReaccionMs solo para aciertos
     * @return array
     */
    public function registrarRonda($numeroRonda, $tipoResultado, $tiempoReaccionMs)
    {
        // Lo PRIMERO: anotar cuándo llegó la respuesta, antes de cualquier otro trabajo.
        $recibidaEn = microtime(true);

        if (!isset($_SESSION[self::CLAVE_SESION]) || $_SESSION[self::CLAVE_SESION]['ronda_pendiente'] === null) {
            throw new RuntimeException('No hay una ronda pendiente. Iniciá una partida.');
        }

        $estado    = $_SESSION[self::CLAVE_SESION];
        $pendiente = $estado['ronda_pendiente'];
        $datos     = $pendiente['datos'];

        // Evita respuestas duplicadas o desfasadas (doble toque, pestañas repetidas).
        if ((int) $numeroRonda !== (int) $datos['numero_ronda']) {
            throw new RuntimeException('La ronda enviada no coincide con la ronda pendiente.');
        }
        if (!in_array($tipoResultado, array('acierto', 'timeout', 'falso_inicio', 'senuelo'), true)) {
            throw new InvalidArgumentException('Tipo de resultado inválido.');
        }

        $sospechosa = false;

        if ($tipoResultado === 'acierto') {
            if ($tiempoReaccionMs === null || $tiempoReaccionMs < 0) {
                throw new InvalidArgumentException('Un acierto necesita un tiempo de reacción válido.');
            }
            $tiempoReaccionMs = (int) round($tiempoReaccionMs);

            if ($tiempoReaccionMs > $datos['ventana_ms']) {
                // Llegó fuera de la ventana: para el juego (y los datos) es un timeout.
                $tipoResultado    = 'timeout';
                $tiempoReaccionMs = null;
            } else {
                $sospechosa = $this->esTiempoSospechoso(
                    $tiempoReaccionMs, $datos, $pendiente['entregada_en'], $recibidaEn
                );
            }
        } elseif ($tipoResultado === 'senuelo') {
            // El navegador dice haber tocado el señuelo: solo es posible si esta ronda realmente tenía uno.
            // Si no, la sesión y lo que reporta el navegador no coinciden, igual que con una ronda desfasada (mismo tipo de excepción, más abajo).
            if ($datos['senuelo_boton'] === null) {
                throw new RuntimeException('Esta ronda no tenía señuelo.');
            }
            $tiempoReaccionMs = null;
            $sospechosa       = $this->esClicSenueloSospechoso($datos, $pendiente['entregada_en'], $recibidaEn);
        } else {
            // Sin acierto no hay tiempo de reacción (se guardará NULL).
            $tiempoReaccionMs = null;
        }

        $quedoSospechosa = $this->repositorio->guardarRonda(
            $estado['id_partida'], $datos, $tipoResultado, $tiempoReaccionMs, $sospechosa
        );

        // Una ronda imposible ANULA la partida: la ronda queda guardada con su marca, pero no se calcula descontrol ni resultado.
        // La partida queda 'en_curso' para siempre, así que las métricas de resultado (fuga, duelo) ya la excluyen solas (ver el criterio en tablas_juego.sql).
        if ($quedoSospechosa) {
            unset($_SESSION[self::CLAVE_SESION]);
            return array(
                'tipo_resultado'     => $tipoResultado,
                'tiempo_reaccion_ms' => $tiempoReaccionMs,
                'sospechosa'         => true,
                'nivel_descontrol'   => $estado['nivel_descontrol'],
                'estado_partida'     => 'anulada',
                'siguiente_ronda'    => null,
                'percentil'          => null
            );
        }

        $nuevoNivel = $this->reglas->nuevoNivelDescontrol(
            $estado['nivel_descontrol'], $tipoResultado, $tiempoReaccionMs, $datos['ventana_ms']
        );
        $estadoPartida = $this->reglas->estadoPartida($nuevoNivel, $datos['numero_ronda']);

        // La ronda ya está guardada: recién ahora se cierra. Si guardar hubiera fallado, el navegador podría reintentar la misma ronda.
        $_SESSION[self::CLAVE_SESION]['nivel_descontrol'] = $nuevoNivel;
        $_SESSION[self::CLAVE_SESION]['ronda_pendiente']  = null;

        $siguienteRonda = null;
        $percentil      = null;
        if ($estadoPartida === 'en_curso') {
            $siguienteRonda = $this->entregarRonda($datos['numero_ronda'] + 1);
        } else {
            $this->repositorio->finalizarPartida($estado['id_partida'], $estadoPartida);
            unset($_SESSION[self::CLAVE_SESION]);
            // Recién después de cerrar la partida y limpiar la sesión: si el cálculo falla, el juego termina bien igual y solo falta el porcentaje.
            $percentil = $this->calcularPercentil($estado['id_partida']);
        }

        return array(
            'tipo_resultado'     => $tipoResultado,
            'tiempo_reaccion_ms' => $tiempoReaccionMs,
            'sospechosa'         => $quedoSospechosa,
            'nivel_descontrol'   => $nuevoNivel,
            'estado_partida'     => $estadoPartida,
            'siguiente_ronda'    => $siguienteRonda,
            'percentil'          => $percentil
        );
    }

    /**
     * Sortea el grupo de la partida: cada robot tiene la MISMA probabilidad.
     * El sorteo se hace en el servidor y el navegador nunca se entera de cuál le tocó:
     * si supiera que juega contra el robot "de control", podría cambiar su forma de jugar y los grupos dejarían de ser comparables.
     *
     * [POO · ENCAPSULAMIENTO]
     * Método privado: nadie de afuera puede elegir el grupo.
     *
     * @return string tipo de robot ('adaptativo' o 'aleatorio')
     */
    private function sortearTipoRobot()
    {
        $tipos = array_keys($this->robots);
        return $tipos[mt_rand(0, count($tipos) - 1)];
    }

    /**
     * Devuelve el robot de la partida en curso, según el tipo guardado en la sesión al iniciar. 
     * Cada petición HTTP crea un servicio nuevo, así que el robot se vuelve a buscar cada vez.
     *
     * [POO · POLIMORFISMO]
     * Devuelve un Robot sin importar cuál: quien lo usa solo le pide rondas.
     *
     * @return Robot
     */
    private function robotDeLaPartida()
    {
        $tipo = $_SESSION[self::CLAVE_SESION]['tipo_robot'];

        if (!isset($this->robots[$tipo])) {
            throw new RuntimeException('Tipo de robot desconocido: ' . $tipo);
        }
        return $this->robots[$tipo];
    }

    /**
     * Pide al robot la ronda N, la deja anotada como pendiente junto con la hora exacta de entrega,
     * y devuelve al navegador SOLO lo que necesita para mostrarla (nunca la semilla ni la temperatura).
     */
    private function entregarRonda($numeroRonda)
    {
        $estado = $_SESSION[self::CLAVE_SESION];

        $datos = $this->robotDeLaPartida()->prepararRonda(
            $estado['semilla'], $numeroRonda, $estado['medias'], $estado['mediana']
        );

        $_SESSION[self::CLAVE_SESION]['ronda_pendiente'] = array(
            'datos'        => $datos,
            'entregada_en' => microtime(true)
        );

        return array(
            'numero_ronda'        => $datos['numero_ronda'],
            'boton_activado'      => $datos['boton_activado'],
            'retardo_ms'          => $datos['retardo_ms'],
            'ventana_ms'          => $datos['ventana_ms'],
            // null cuando esta ronda no tiene señuelo: el navegador solo
            // programa el destello si 'senuelo_boton' no es null.
            'senuelo_boton'       => $datos['senuelo_boton'],
            'senuelo_instante_ms' => $datos['senuelo_instante_ms'],
            'senuelo_duracion_ms' => ReglasPartida::DURACION_SENUELO_MS
        );
    }

    /**
     * Arma la posición de la partida recién terminada respecto de las demás.
     *
     * [POO · ENCAPSULAMIENTO]
     * Es un método PRIVADO: el navegador no puede pedir el percentil de una partida cualquiera.
     * Solo se calcula acá adentro, para la partida que este mismo servicio acaba de cerrar.
     *
     * @return array|null null si el cálculo falló (no se muestra nada)
     */
    private function calcularPercentil($idPartida)
    {
        try {
            $comparacion = $this->repositorio->compararPartida($idPartida);
        } catch (Exception $e) {
            error_log('Sandbox IA - error al calcular el percentil: ' . $e->getMessage());
            return null;
        }

        if ($comparacion === null) {
            return array(
                'estado'              => 'pocas_reacciones',
                'mediana_ms'          => null,
                'comparadas'          => 0,
                'porcentaje_superado' => null
            );
        }

        $porcentaje = $this->reglas->porcentajeSuperado($comparacion['mas_lentas'], $comparacion['comparadas']);

        return array(
            'estado'              => ($porcentaje === null) ? 'pocas_partidas' : 'ok',
            'mediana_ms'          => $comparacion['mediana_ms'],
            'comparadas'          => $comparacion['comparadas'],
            'porcentaje_superado' => $porcentaje
        );
    }

    /**
     * Comprueba si el tiempo declarado por el navegador es compatible con el tiempo que el servidor midió por su cuenta.
     *
     * Si el navegador dice que reaccionó X ms después del rojo, entre la entrega y la recepción tienen que haber pasado AL MENOS retardo + X ms
     * (la latencia de red solo suma). Un X mayor que eso es imposible.
     */
    private function esTiempoSospechoso($tiempoMs, array $datosRonda, $entregadaEn, $recibidaEn)
    {
        $transcurridoMs = ($recibidaEn - $entregadaEn) * 1000;

        // 1) El tiempo declarado no cabe en el tiempo realmente transcurrido.
        $maximoPosibleMs = $transcurridoMs - $datosRonda['retardo_ms'] + self::TOLERANCIA_MS;
        if ($tiempoMs > $maximoPosibleMs) {
            return true;
        }

        // 2) La respuesta llegó muchísimo después de lo esperable para un acierto
        //    (pestaña dormida, o alguien esperando para responder).
        $limiteMs = $datosRonda['retardo_ms'] + $datosRonda['ventana_ms'] + self::MARGEN_MAXIMO_MS;
        if ($transcurridoMs > $limiteMs) {
            return true;
        }

        return false;
    }

    /**
     * Comprueba si un clic reportado como 'senuelo' es compatible con la
     * ventana real en que estuvo visible.
     *
     * Acá no hay un tiempo declarado por el navegador (a diferencia de un acierto): 
     * alcanza con mirar CUÁNDO llegó la respuesta y compararlo contra el instante en que el señuelo apareció y cuánto duró.
     */
    private function esClicSenueloSospechoso(array $datosRonda, $entregadaEn, $recibidaEn)
    {
        $transcurridoMs = ($recibidaEn - $entregadaEn) * 1000;

        $desdeMs = $datosRonda['senuelo_instante_ms'] - self::TOLERANCIA_MS;
        $hastaMs = $datosRonda['senuelo_instante_ms'] + ReglasPartida::DURACION_SENUELO_MS
                 + self::TOLERANCIA_MS + self::MARGEN_MAXIMO_MS;

        return ($transcurridoMs < $desdeMs || $transcurridoMs > $hastaMs);
    }
}