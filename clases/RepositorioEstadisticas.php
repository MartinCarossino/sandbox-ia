<?php
/**
 * =======================
 * RepositorioEstadisticas
 * =======================
 * Consultas para el panel de estadísticas.
 * Solo LEE datos del juego: no escribe nada.
 */
class RepositorioEstadisticas
{
    // [POO · ENCAPSULAMIENTO]
    // La conexión es PRIVADA y las únicas consultas que se ejecutan son las que están escritas acá adentro.
    private $conexion;

    // Columnas que son texto aunque parezcan número (un alias puede ser "2024").
    private $columnasTexto = array('grupo', 'alias', 'dispositivo', 'tipo_robot', 'estado');

    // Columnas que MySQL entrega como 0/1 y que en JSON tienen que ser true/false.
    private $columnasVerdaderoFalso = array('hay_diferencia', 'destaca', 'suficiente');

    public function __construct(mysqli $conexion)
    {
        $this->conexion = $conexion;
    }

    // [POO · ABSTRACCIÓN]
    // Cada método público responde UNA pregunta del panel ("¿cómo viene el pulso?", "¿qué botón destaca?") sin mostrar de dónde sale la respuesta.

    /** @return array|null la franja en vivo: una sola fila */
    public function pulso()
    {
        // Una partida cuenta como terminada si su resultado no es 'en_curso'; pct_fuga es el porcentaje de esas que
        // quedaron 'descontrolada' (la comparación da 1 si es verdadera y 0 si no, y AVG de esos 0/1 es la proporción).
        $sql = "WITH validas AS (
                    SELECT r.tiempo_reaccion_ms AS ms
                    FROM rondas r
                    WHERE r.tipo_resultado = 'acierto'
                      AND r.sospechosa = 0
                      AND r.tiempo_reaccion_ms IS NOT NULL
                ),
                ordenadas AS (
                    SELECT ms,
                           ROW_NUMBER() OVER (ORDER BY ms) AS posicion,
                           COUNT(*)     OVER ()            AS total
                    FROM validas
                ),
                resumen_tiempos AS (
                    SELECT MAX(total) AS reacciones,
                           MIN(ms)    AS mejor_ms,
                           ROUND(AVG(CASE WHEN posicion IN (FLOOR((total + 1) / 2), CEIL((total + 1) / 2)) THEN ms END), 1) AS mediana_ms
                    FROM ordenadas
                )
                SELECT
                    (SELECT COUNT(*) FROM partidas WHERE resultado <> 'en_curso')                     AS partidas_terminadas,
                    (SELECT COUNT(DISTINCT id_jugador) FROM partidas WHERE resultado <> 'en_curso')   AS jugadores,
                    t.reacciones,
                    t.mejor_ms,
                    t.mediana_ms,
                    (SELECT ROUND(100 * AVG(resultado = 'descontrolada'), 1)
                       FROM partidas WHERE resultado <> 'en_curso')                                   AS pct_fuga,
                    (SELECT TIMESTAMPDIFF(SECOND, MAX(fecha_inicio), NOW()) FROM partidas)            AS segundos_desde_ultima
                FROM resumen_tiempos t";

        $filas = $this->consultar($sql);
        return count($filas) > 0 ? $filas[0] : null;
    }

    /**
     * Mejor, peor, media, desvío, p10, mediana y p90 de cada grupo de reacciones.
     * @return array indexado por grupo: todos, pc, movil, aleatorio, adaptativo
     */
    public function percentiles()
    {
        $porGrupo = array();

        $sql = "WITH reacciones AS (
                    SELECT r.tiempo_reaccion_ms AS ms,
                           CAST(p.dispositivo AS CHAR) AS dispositivo,
                           CAST(p.tipo_robot AS CHAR)  AS tipo_robot
                    FROM rondas r
                    INNER JOIN partidas p ON p.id_partida = r.id_partida
                    WHERE r.tipo_resultado = 'acierto'
                      AND r.sospechosa = 0
                      AND r.tiempo_reaccion_ms IS NOT NULL
                ),
                grupos AS (
                    SELECT 'todos' AS grupo, ms FROM reacciones
                    UNION ALL
                    SELECT dispositivo, ms FROM reacciones
                    UNION ALL
                    SELECT tipo_robot, ms FROM reacciones
                ),
                ordenadas AS (
                    SELECT grupo,
                           ms,
                           ROW_NUMBER() OVER (PARTITION BY grupo ORDER BY ms) AS posicion,
                           COUNT(*)     OVER (PARTITION BY grupo)             AS total
                    FROM grupos
                )
                SELECT grupo,
                       MAX(total)                                                        AS n,
                       MIN(ms)                                                           AS mejor_ms,
                       MAX(ms)                                                           AS peor_ms,
                       ROUND(AVG(ms), 2)                                                 AS media_ms,
                       ROUND(STDDEV_SAMP(ms), 2)                                         AS desvio_ms,
                       MAX(CASE WHEN posicion = CEIL(0.10 * total) THEN ms END)          AS p10_ms,
                       ROUND((MAX(CASE WHEN posicion = FLOOR((total + 1) / 2) THEN ms END)
                            + MAX(CASE WHEN posicion = CEIL((total + 1) / 2)  THEN ms END)) / 2, 1) AS mediana_ms,
                       MAX(CASE WHEN posicion = CEIL(0.90 * total) THEN ms END)          AS p90_ms,
                       (MAX(total) >= 30)                                                AS suficiente
                FROM ordenadas
                GROUP BY grupo
                ORDER BY grupo";

        foreach ($this->consultar($sql) as $fila) {
            $porGrupo[$fila['grupo']] = $fila;
        }
        return $porGrupo;
    }

    /** @return array|null PC contra celular; null si todavía falta alguno de los dos */
    public function dispositivos()
    {

        $sql = "WITH reacciones AS (
                    SELECT r.tiempo_reaccion_ms AS ms,
                           p.id_jugador,
                           CAST(p.dispositivo AS CHAR) AS dispositivo
                    FROM rondas r
                    INNER JOIN partidas p ON p.id_partida = r.id_partida
                    WHERE r.tipo_resultado = 'acierto'
                      AND r.sospechosa = 0
                      AND r.tiempo_reaccion_ms IS NOT NULL
                ),
                ordenadas AS (
                    SELECT dispositivo,
                           id_jugador,
                           ms,
                           ROW_NUMBER() OVER (PARTITION BY dispositivo ORDER BY ms) AS posicion,
                           COUNT(*)     OVER (PARTITION BY dispositivo)             AS total
                    FROM reacciones
                ),
                por_dispositivo AS (
                    SELECT dispositivo,
                           MAX(total)                 AS n,
                           COUNT(DISTINCT id_jugador) AS jugadores,
                           ROUND(AVG(ms), 2)          AS media_ms,
                           ROUND(STDDEV_SAMP(ms), 2)  AS desvio_ms,
                           ROUND((MAX(CASE WHEN posicion = FLOOR((total + 1) / 2) THEN ms END)
                                + MAX(CASE WHEN posicion = CEIL((total + 1) / 2)  THEN ms END)) / 2, 1) AS mediana_ms
                    FROM ordenadas
                    GROUP BY dispositivo
                ),
                comparacion AS (
                    SELECT pc.n                                     AS n_pc,
                           mv.n                                     AS n_movil,
                           pc.jugadores                             AS jugadores_pc,
                           mv.jugadores                             AS jugadores_movil,
                           pc.mediana_ms                            AS mediana_pc_ms,
                           mv.mediana_ms                            AS mediana_movil_ms,
                           ROUND(mv.media_ms - pc.media_ms, 1)      AS diferencia_media_ms,
                           ROUND(mv.mediana_ms - pc.mediana_ms, 1)  AS diferencia_mediana_ms,
                           (mv.media_ms - pc.media_ms)
                               / SQRT(POW(mv.desvio_ms, 2) / mv.n + POW(pc.desvio_ms, 2) / pc.n) AS z,
                           (pc.n >= 30 AND mv.n >= 30 AND pc.jugadores >= 5 AND mv.jugadores >= 5) AS suficiente
                    FROM por_dispositivo pc
                    INNER JOIN por_dispositivo mv ON pc.dispositivo = 'pc' AND mv.dispositivo = 'movil'
                )
                SELECT n_pc, n_movil, jugadores_pc, jugadores_movil,
                       mediana_pc_ms, mediana_movil_ms,
                       diferencia_media_ms, diferencia_mediana_ms,
                       ROUND(z, 2)                  AS z,
                       suficiente,
                       (suficiente AND ABS(z) >= 3) AS hay_diferencia
                FROM comparacion";

        $filas = $this->consultar($sql);
        return count($filas) > 0 ? $filas[0] : null;
    }

    /** @return array los seis botones del mapa de calor (solo robot aleatorio) */
    public function botones()
    {

        $sql = "WITH aleatorias AS (
                    SELECT r.boton_activado AS boton,
                           r.tiempo_reaccion_ms AS ms
                    FROM rondas r
                    INNER JOIN partidas p ON p.id_partida = r.id_partida
                    WHERE p.tipo_robot = 'aleatorio'
                      AND r.tipo_resultado = 'acierto'
                      AND r.sospechosa = 0
                      AND r.tiempo_reaccion_ms IS NOT NULL
                ),
                por_boton AS (
                    SELECT boton,
                           COUNT(*)      AS n,
                           SUM(ms)       AS suma,
                           SUM(ms * ms)  AS suma_cuadrados
                    FROM aleatorias
                    GROUP BY boton
                ),
                contra_el_resto AS (
                    SELECT boton, n, suma, suma_cuadrados,
                           SUM(n)              OVER () - n              AS n_resto,
                           SUM(suma)           OVER () - suma           AS suma_resto,
                           SUM(suma_cuadrados) OVER () - suma_cuadrados AS suma_cuadrados_resto
                    FROM por_boton
                ),
                botones_z AS (
                    SELECT boton,
                           n,
                           ROUND(suma / n, 1)                                        AS media_ms,
                           ROUND(suma / n - suma_resto / n_resto, 1)                 AS diferencia_ms,
                           (suma / n - suma_resto / n_resto) / SQRT(
                               ((suma_cuadrados - suma * suma / n) / (n - 1)) / n
                             + ((suma_cuadrados_resto - suma_resto * suma_resto / n_resto) / (n_resto - 1)) / n_resto
                           )                                                         AS z
                    FROM contra_el_resto
                    WHERE n > 1 AND n_resto > 1
                )
                SELECT boton,
                       n,
                       media_ms,
                       diferencia_ms,
                       ROUND(z, 2)   AS z,
                       (n >= 30)     AS suficiente,
                       (n >= 30 AND ABS(z) >= 3
                            AND ABS(z) = MAX(CASE WHEN n >= 30 THEN ABS(z) END) OVER ()) AS destaca
                FROM botones_z
                ORDER BY boton";

        return $this->consultar($sql);
    }

    /** @return array una fila por robot y número de ronda */
    public function porRonda()
    {

        $sql = "SELECT
                    p.tipo_robot,
                    r.numero_ronda,
                    COUNT(*)                                                             AS rondas,
                    SUM(r.tipo_resultado = 'timeout')                                    AS timeouts,
                    ROUND(100 * AVG(r.tipo_resultado = 'timeout'), 1)                    AS pct_timeout,
                    SUM(r.tipo_resultado = 'acierto' AND r.sospechosa = 0)               AS validas,
                    ROUND(AVG(CASE WHEN r.tipo_resultado = 'acierto' AND r.sospechosa = 0
                                   THEN r.tiempo_reaccion_ms END), 1)                    AS media_ms,
                    ROUND(AVG(r.ventana_ms))                                             AS ventana_ms
                FROM rondas r
                INNER JOIN partidas p ON p.id_partida = r.id_partida
                GROUP BY p.tipo_robot, r.numero_ronda
                ORDER BY p.tipo_robot, r.numero_ronda";

        return $this->consultar($sql);
    }

    /** @return array una fila por robot */
    public function robots()
    {

        $sql = "WITH reacciones AS (
                    SELECT r.tiempo_reaccion_ms AS ms,
                           CAST(p.tipo_robot AS CHAR) AS tipo_robot
                    FROM rondas r
                    INNER JOIN partidas p ON p.id_partida = r.id_partida
                    WHERE r.tipo_resultado = 'acierto'
                      AND r.sospechosa = 0
                      AND r.tiempo_reaccion_ms IS NOT NULL
                ),
                ordenadas AS (
                    SELECT tipo_robot,
                           ms,
                           ROW_NUMBER() OVER (PARTITION BY tipo_robot ORDER BY ms) AS posicion,
                           COUNT(*)     OVER (PARTITION BY tipo_robot)             AS total
                    FROM reacciones
                ),
                tiempos_por_robot AS (
                    SELECT tipo_robot,
                           MAX(total)         AS reacciones,
                           ROUND(AVG(ms), 2)  AS media_ms,
                           ROUND((MAX(CASE WHEN posicion = FLOOR((total + 1) / 2) THEN ms END)
                                + MAX(CASE WHEN posicion = CEIL((total + 1) / 2)  THEN ms END)) / 2, 1) AS mediana_ms
                    FROM ordenadas
                    GROUP BY tipo_robot
                ),
                partidas_por_robot AS (
                    SELECT CAST(tipo_robot AS CHAR)                       AS tipo_robot,
                           COUNT(*)                                       AS partidas_terminadas,
                           SUM(resultado = 'descontrolada')               AS descontroladas,
                           ROUND(100 * AVG(resultado = 'descontrolada'), 1) AS pct_fuga
                    FROM partidas
                    WHERE resultado <> 'en_curso'
                    GROUP BY tipo_robot
                )
                SELECT pp.tipo_robot,
                       pp.partidas_terminadas,
                       pp.descontroladas,
                       pp.pct_fuga,
                       t.reacciones,
                       t.mediana_ms,
                       t.media_ms
                FROM partidas_por_robot pp
                INNER JOIN tiempos_por_robot t ON t.tipo_robot = pp.tipo_robot
                ORDER BY pp.tipo_robot";

        return $this->consultar($sql);
    }

    /** @return array|null aleatorio contra adaptativo en tasa de fuga */
    public function duelo()
    {

        $sql = "WITH conteos AS (
                    SELECT SUM(tipo_robot = 'aleatorio')                                        AS n_aleatorio,
                           SUM(tipo_robot = 'adaptativo')                                       AS n_adaptativo,
                           SUM(tipo_robot = 'aleatorio'  AND resultado = 'descontrolada')       AS fugas_aleatorio,
                           SUM(tipo_robot = 'adaptativo' AND resultado = 'descontrolada')       AS fugas_adaptativo
                    FROM partidas
                    WHERE resultado <> 'en_curso'
                )
                SELECT
                    n_aleatorio                                                          AS partidas_aleatorio,
                    n_adaptativo                                                         AS partidas_adaptativo,
                    ROUND(100 * fugas_aleatorio  / n_aleatorio, 1)                       AS pct_fuga_aleatorio,
                    ROUND(100 * fugas_adaptativo / n_adaptativo, 1)                      AS pct_fuga_adaptativo,
                    ROUND(100 * (fugas_adaptativo / n_adaptativo - fugas_aleatorio / n_aleatorio), 1) AS diferencia_pp,
                    ROUND((fugas_adaptativo / n_adaptativo - fugas_aleatorio / n_aleatorio) / SQRT(
                        ((fugas_aleatorio + fugas_adaptativo) / (n_aleatorio + n_adaptativo))
                      * (1 - (fugas_aleatorio + fugas_adaptativo) / (n_aleatorio + n_adaptativo))
                      * (1 / n_aleatorio + 1 / n_adaptativo)), 2)                        AS z,
                    (n_aleatorio >= 30 AND n_adaptativo >= 30)                           AS suficiente,
                    (n_aleatorio >= 30 AND n_adaptativo >= 30 AND ABS(
                        (fugas_adaptativo / n_adaptativo - fugas_aleatorio / n_aleatorio) / SQRT(
                        ((fugas_aleatorio + fugas_adaptativo) / (n_aleatorio + n_adaptativo))
                      * (1 - (fugas_aleatorio + fugas_adaptativo) / (n_aleatorio + n_adaptativo))
                      * (1 / n_aleatorio + 1 / n_adaptativo))) >= 3)                     AS hay_diferencia
                FROM conteos
                WHERE n_aleatorio > 0 AND n_adaptativo > 0";

        $filas = $this->consultar($sql);
        return count($filas) > 0 ? $filas[0] : null;
    }

    /**
     * @param int $cantidad cuántas partidas devolver (se fuerza a entero)
     * @return array las más recientes primero
     */
    public function ultimasPartidas($cantidad)
    {

        $sql = "SELECT
                    p.id_partida,
                    j.alias,
                    p.dispositivo,
                    p.tipo_robot,
                    CASE WHEN p.resultado = 'en_curso' AND TIMESTAMPDIFF(SECOND, p.fecha_inicio, NOW()) >= 120
                         THEN 'abandonada'
                         ELSE p.resultado
                    END AS estado,
                    TIMESTAMPDIFF(SECOND, p.fecha_inicio, NOW()) AS segundos_atras,
                    (SELECT MIN(r.tiempo_reaccion_ms)
                       FROM rondas r
                      WHERE r.id_partida = p.id_partida
                        AND r.tipo_resultado = 'acierto'
                        AND r.sospechosa = 0
                        AND r.tiempo_reaccion_ms IS NOT NULL) AS mejor_ms
                FROM partidas p
                INNER JOIN jugadores j ON j.id_jugador = p.id_jugador
                ORDER BY p.id_partida DESC
                LIMIT " . (int) $cantidad;

        return $this->consultar($sql);
    }

    /**
     * Ejecuta una consulta fija y devuelve las filas con los tipos ya corregidos: mysqli entrega todo como texto ("398" en vez de 398).
     */
    private function consultar($sql)
    {
        $resultado = $this->conexion->query($sql);
        $filas = array();
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $this->convertirTipos($fila);
        }
        $resultado->free();
        return $filas;
    }

    private function convertirTipos(array $fila)
    {
        foreach ($fila as $columna => $valor) {
            if ($valor === null || in_array($columna, $this->columnasTexto, true)) {
                continue;   // NULL se queda NULL: en pantalla significa "sin dato"
            }
            if (in_array($columna, $this->columnasVerdaderoFalso, true)) {
                $fila[$columna] = ((int) $valor === 1);
            } elseif (is_numeric($valor)) {
                $fila[$columna] = $valor + 0;   // int o float, según corresponda
            }
        }
        return $fila;
    }
}