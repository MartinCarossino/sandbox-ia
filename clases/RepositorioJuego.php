<?php

class RepositorioJuego
{
    // Nadie reacciona a un estímulo visual en menos de 100 ms. Por debajo de ese umbral, la ronda se guarda pero queda "sospechosa".
    const TIEMPO_MINIMO_HUMANO_MS = 100;

    // Con menos datos que estos, las estadísticas del jugador no son confiables y el robot se comporta como con un jugador nuevo.
    const MINIMO_REACCIONES_POR_BOTON = 3;
    const MINIMO_REACCIONES_MEDIANA   = 5;
    const MINIMO_REACCIONES_PARTIDA   = 5;

    const LONGITUD_MAXIMA_ALIAS = 20;

    // [POO · ENCAPSULAMIENTO]
    // La conexión es PRIVADA: ninguna otra parte del sistema puede tocarla ni ejecutar SQL por su cuenta.
    // Todas las reglas de validación (tipos de resultado válidos, marca de ronda sospechosa, NULL en vez de 0) viven acá adentro,
    // así nadie puede guardar un dato inválido saltándose el repositorio.
    private $conexion;

    public function __construct(mysqli $conexion)
    {
        $this->conexion = $conexion;
    }

    // [POO · ABSTRACCIÓN]
    // Los métodos públicos describen QUÉ necesita el juego ("dame las medias por botón de este jugador") sin mostrar CÓMO se obtiene (filtros, funciones de ventana, consultas preparadas). 
    // Si mañana los datos salen de otra tabla o de una caché, el juego y los robots no cambian.

    /**
     * Crea un jugador junto con su token: la llave secreta que su navegador guarda para ser reconocido en las próximas partidas.
     *
     * @param string $alias entre 1 y 20 caracteres
     * @return array array('id_jugador' => int, 'token' => string)
     */
    public function crearJugador($alias)
    {
        $alias = $this->validarAlias($alias);

        // [POO · ENCAPSULAMIENTO]
        // El token se genera ACÁ ADENTRO: no hay forma de crear un jugador sin token, ni de elegirle uno desde afuera.
        $token = $this->generarToken();

        $sentencia = $this->conexion->prepare('INSERT INTO jugadores (alias, token) VALUES (?, ?)');
        $sentencia->bind_param('ss', $alias, $token);
        $sentencia->execute();
        $idJugador = (int) $sentencia->insert_id;
        $sentencia->close();

        return array('id_jugador' => $idJugador, 'token' => $token);
    }

    /**
     * Cambia el alias de un jugador que ya existe. Si el alias es el mismo, MySQL no escribe nada, así que se puede llamar en cada partida sin costo.
     *
     * @param int    $idJugador
     * @param string $alias entre 1 y 20 caracteres
     */
    public function actualizarAlias($idJugador, $alias)
    {
        $alias = $this->validarAlias($alias);

        $sentencia = $this->conexion->prepare('UPDATE jugadores SET alias = ? WHERE id_jugador = ?');
        $sentencia->bind_param('si', $alias, $idJugador);
        $sentencia->execute();
        $sentencia->close();
    }

    // [POO · ENCAPSULAMIENTO]
    // La regla del alias (1 a 20 caracteres, sin espacios de sobra) vive en UN solo lugar privado: crear y renombrar la usan, y ninguna puede saltársela.
    private function validarAlias($alias)
    {
        $alias = trim($alias);
        if ($alias === '' || mb_strlen($alias, 'UTF-8') > self::LONGITUD_MAXIMA_ALIAS) {
            throw new InvalidArgumentException('El alias debe tener entre 1 y 20 caracteres.');
        }
        return $alias;
    }

    // 16 bytes aleatorios = 32 caracteres hexadecimales (2^128 combinaciones: imposible de adivinar).
    private function generarToken()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }

        $bytes = openssl_random_pseudo_bytes(16, $esSeguro);
        if ($bytes === false || !$esSeguro) {
            throw new Exception('No se pudo generar un token seguro.');
        }
        return bin2hex($bytes);
    }

    /**
     * Busca al jugador dueño de un token. El token llega del navegador, así
     * que puede ser cualquier cosa: vacío, inventado o malformado.
     *
     * @param string $token
     * @return int|null id del jugador, o null si el token no existe
     */
    public function obtenerIdJugadorPorToken($token)
    {
        // Si ni siquiera tiene la forma de un token (32 caracteres hexadecimales), no hace falta consultar la base.
        // \A y \z marcan el inicio y el final exactos del texto: $ aceptaría un salto de línea al final.
        if (!is_string($token) || !preg_match('/\A[0-9a-f]{32}\z/', $token)) {
            return null;
        }

        $sentencia = $this->conexion->prepare('SELECT id_jugador FROM jugadores WHERE token = ?');
        $sentencia->bind_param('s', $token);
        $sentencia->execute();
        $sentencia->bind_result($idJugador);

        $idEncontrado = $sentencia->fetch() ? (int) $idJugador : null;
        $sentencia->close();

        return $idEncontrado;
    }

    /**
     * Cuenta las partidas iniciadas en los últimos minutos, sumando a TODOS los jugadores. ServicioPartida lo usa para el tope global de partidas.
     *
     * @param int $minutos
     * @return int
     */
    public function contarPartidasRecientes($minutos)
    {
        $sentencia = $this->conexion->prepare(
            'SELECT COUNT(*)
             FROM partidas
             WHERE fecha_inicio >= NOW() - INTERVAL ? MINUTE'
        );
        $sentencia->bind_param('i', $minutos);
        $sentencia->execute();
        $sentencia->bind_result($cantidad);
        $sentencia->fetch();
        $sentencia->close();

        return (int) $cantidad;
    }

    /**
     * @param int    $idJugador
     * @param string $dispositivo 'pc' o 'movil'
     * @param int    $semilla     semilla de la partida (solo vive en el servidor)
     * @param string $tipoRobot   'adaptativo' o 'aleatorio': el grupo al que le tocó jugar
     * @return int id de la partida creada
     */
    public function crearPartida($idJugador, $dispositivo, $semilla, $tipoRobot)
    {
        if (!in_array($dispositivo, array('pc', 'movil'), true)) {
            throw new InvalidArgumentException('Dispositivo inválido: ' . $dispositivo);
        }
        if (!in_array($tipoRobot, array('adaptativo', 'aleatorio'), true)) {
            throw new InvalidArgumentException('Tipo de robot inválido: ' . $tipoRobot);
        }

        $sentencia = $this->conexion->prepare(
            'INSERT INTO partidas (id_jugador, dispositivo, seed, tipo_robot) VALUES (?, ?, ?, ?)'
        );
        $sentencia->bind_param('isis', $idJugador, $dispositivo, $semilla, $tipoRobot);
        $sentencia->execute();
        $idPartida = (int) $sentencia->insert_id;
        $sentencia->close();

        return $idPartida;
    }

    /**
     * Calcula la media de reacción por botón del jugador, directo desde rondas y partidas.
     * Devuelve array(1 => media_ms, ..., 6 => media_ms), o un array vacío si el jugador no tiene datos suficientes en TODOS los botones
     * (un perfil incompleto haría que el robot ataque siempre el mismo botón).
     *
     * @return array
     */
    public function obtenerMediasPorBoton($idJugador)
    {
        // bind_param recibe variables (por referencia), no constantes.
        $minimoReacciones = self::MINIMO_REACCIONES_POR_BOTON;

        $sentencia = $this->conexion->prepare(
            "SELECT r.boton_activado, ROUND(AVG(r.tiempo_reaccion_ms), 1) AS media_ms
             FROM rondas r
             INNER JOIN partidas p ON p.id_partida = r.id_partida
             WHERE p.id_jugador = ?
               AND r.tipo_resultado = 'acierto'
               AND r.sospechosa = 0
               AND r.tiempo_reaccion_ms IS NOT NULL
             GROUP BY r.boton_activado
             HAVING COUNT(*) >= ?"
        );
        $sentencia->bind_param('ii', $idJugador, $minimoReacciones);
        $sentencia->execute();
        $sentencia->bind_result($boton, $mediaMs);

        $mediasPorBoton = array();
        while ($sentencia->fetch()) {
            $mediasPorBoton[(int) $boton] = (float) $mediaMs;
        }
        $sentencia->close();

        if (count($mediasPorBoton) < ReglasPartida::CANTIDAD_BOTONES) {
            return array();
        }

        return $mediasPorBoton;
    }

    /**
     * Calcula la mediana del jugador directo desde rondas y partidas. Devuelve null si tiene menos de 5 reacciones válidas.
     *
     * @return float|null
     */
    public function obtenerMedianaJugador($idJugador)
    {
        $minimoReacciones = self::MINIMO_REACCIONES_MEDIANA;

        $sentencia = $this->conexion->prepare(
            "WITH ordenadas AS (
                SELECT r.tiempo_reaccion_ms AS ms,
                       ROW_NUMBER() OVER (ORDER BY r.tiempo_reaccion_ms) AS posicion,
                       COUNT(*) OVER ()                                  AS total
                FROM rondas r
                INNER JOIN partidas p ON p.id_partida = r.id_partida
                WHERE p.id_jugador = ?
                  AND r.tipo_resultado = 'acierto'
                  AND r.sospechosa = 0
                  AND r.tiempo_reaccion_ms IS NOT NULL
             )
             SELECT ROUND(AVG(ms), 1) AS mediana_ms
             FROM ordenadas
             WHERE total >= ?
               AND posicion IN (FLOOR((total + 1) / 2), CEIL((total + 1) / 2))
             GROUP BY total"
        );
        $sentencia->bind_param('ii', $idJugador, $minimoReacciones);
        $sentencia->execute();
        $sentencia->bind_result($medianaMs);

        $mediana = null;
        if ($sentencia->fetch()) {
            $mediana = (float) $medianaMs;
        }
        $sentencia->close();

        return $mediana;
    }

    public function guardarRonda($idPartida, array $datosRonda, $tipoResultado, $tiempoReaccionMs, $marcarSospechosa = false)
    {
        if (!in_array($tipoResultado, array('acierto', 'timeout', 'falso_inicio', 'senuelo'), true)) {
            throw new InvalidArgumentException('Tipo de resultado inválido: ' . $tipoResultado);
        }

        if ($tipoResultado !== 'acierto') {
            $tiempoReaccionMs = null;
        } elseif ($tiempoReaccionMs === null) {
            throw new InvalidArgumentException('Un acierto necesita tiempo de reacción.');
        } else {
            $tiempoReaccionMs = (int) round($tiempoReaccionMs);
        }

        $esDemasiadoRapida = ($tiempoReaccionMs !== null && $tiempoReaccionMs < self::TIEMPO_MINIMO_HUMANO_MS);
        $sospechosa = ($marcarSospechosa || $esDemasiadoRapida) ? 1 : 0;

        $numeroRonda = (int) $datosRonda['numero_ronda'];
        $boton       = (int) $datosRonda['boton_activado'];
        $ventanaMs   = (int) $datosRonda['ventana_ms'];
        $retardoMs   = (int) $datosRonda['retardo_ms'];
        $temperatura = (float) $datosRonda['temperatura'];
        $senueloBoton      = isset($datosRonda['senuelo_boton']) ? (int) $datosRonda['senuelo_boton'] : null;
        $senueloInstanteMs = isset($datosRonda['senuelo_instante_ms']) ? (int) $datosRonda['senuelo_instante_ms'] : null;

        $sentencia = $this->conexion->prepare(
            'INSERT INTO rondas
                (id_partida, numero_ronda, boton_activado, senuelo_boton, ventana_ms, retardo_ms,
                 senuelo_instante_ms, temperatura, tiempo_reaccion_ms, tipo_resultado, sospechosa)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        // mysqli acepta NULL para un parámetro 'i' sin problema: no hace falta un tipo especial para senuelo_boton ni senuelo_instante_ms.
        $sentencia->bind_param(
            'iiiiiiidisi',
            $idPartida, $numeroRonda, $boton, $senueloBoton, $ventanaMs, $retardoMs,
            $senueloInstanteMs, $temperatura, $tiempoReaccionMs, $tipoResultado, $sospechosa
        );
        $sentencia->execute();
        $sentencia->close();

        // La regla de los 100 ms vive solo acá: quien llama no puede saber si la ronda quedó sospechosa si no se lo decimos.
        return ($sospechosa === 1);
    }

    /**
     * Compara la mediana de UNA partida contra la de las partidas de OTROS jugadores, calculando las medianas al vuelo.
     *
     * [POO · ABSTRACCIÓN]
     * El servicio pide "comparame esta partida" sin saber que detrás hay una consulta con dos bloques WITH y un auto-join.
     * Solo recibe los tres números que necesita.
     *
     * @param int $idPartida
     * @return array|null array('mediana_ms' => float, 'comparadas' => int, 'mas_lentas' => int),
     *                    o null si la partida no tiene reacciones válidas suficientes
     */
    public function compararPartida($idPartida)
    {
        $minimoReacciones = self::MINIMO_REACCIONES_PARTIDA;

        $sentencia = $this->conexion->prepare(
            "WITH ordenadas AS (
                SELECT r.id_partida,
                       p.id_jugador,
                       r.tiempo_reaccion_ms AS ms,
                       ROW_NUMBER() OVER (PARTITION BY r.id_partida ORDER BY r.tiempo_reaccion_ms) AS posicion,
                       COUNT(*)     OVER (PARTITION BY r.id_partida)                               AS total
                FROM rondas r
                INNER JOIN partidas p ON p.id_partida = r.id_partida
                WHERE r.tipo_resultado = 'acierto'
                  AND r.sospechosa = 0
                  AND r.tiempo_reaccion_ms IS NOT NULL
             ),
             medianas AS (
                SELECT id_partida, id_jugador, total AS reacciones, ROUND(AVG(ms), 1) AS mediana_ms
                FROM ordenadas
                WHERE posicion IN (FLOOR((total + 1) / 2), CEIL((total + 1) / 2))
                GROUP BY id_partida, id_jugador, total
             )
             SELECT
                mio.mediana_ms,
                COUNT(otras.id_partida),
                COALESCE(SUM(otras.mediana_ms > mio.mediana_ms), 0)
             FROM medianas mio
             LEFT JOIN medianas otras
                    ON otras.id_jugador <> mio.id_jugador
                   AND otras.reacciones >= ?
             WHERE mio.id_partida = ?
               AND mio.reacciones >= ?
             GROUP BY mio.id_partida, mio.mediana_ms"
        );
        $sentencia->bind_param('iii', $minimoReacciones, $idPartida, $minimoReacciones);
        $sentencia->execute();
        $sentencia->bind_result($medianaMs, $comparadas, $masLentas);

        $comparacion = null;
        if ($sentencia->fetch()) {
            $comparacion = array(
                'mediana_ms' => (float) $medianaMs,
                'comparadas' => (int) $comparadas,
                'mas_lentas' => (int) $masLentas
            );
        }
        $sentencia->close();

        return $comparacion;
    }

    /**
     * @param int    $idPartida
     * @param string $resultado 'contenida' o 'descontrolada'
     */
    public function finalizarPartida($idPartida, $resultado)
    {
        if (!in_array($resultado, array('contenida', 'descontrolada'), true)) {
            throw new InvalidArgumentException('Resultado inválido: ' . $resultado);
        }

        $sentencia = $this->conexion->prepare(
            'UPDATE partidas SET resultado = ?, fecha_fin = NOW() WHERE id_partida = ?'
        );
        $sentencia->bind_param('si', $resultado, $idPartida);
        $sentencia->execute();
        $sentencia->close();
    }


}