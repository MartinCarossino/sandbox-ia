-- ============================================================
-- TABLAS DEL JUEGO (MariaDB 10.2 o superior)
-- ============================================================
-- ÚNICO script de la instalación. Crea las tres tablas donde el juego guarda
-- jugadores, partidas y rondas.
--
-- No hay vistas, procedimientos ni tablas de resumen: todas las estadísticas
-- se calculan con consultas SELECT que ejecuta PHP (RepositorioJuego.php y
-- RepositorioEstadisticas.php), así la instalación funciona también en un
-- hosting gratuito, que no permite crearlos. Esas consultas usan WITH y
-- funciones de ventana, por eso se pide MariaDB 10.2 o superior.
--
-- Es una copia fiel de la base en funcionamiento (SHOW CREATE TABLE), con
-- dos diferencias a propósito:
--   * sin CREATE DATABASE ni USE: en un hosting la base ya viene creada y
--     con un nombre asignado (en phpMyAdmin se la elige antes de importar);
--     en local, elegí sandbox_ia antes de ejecutar (USE sandbox_ia;)
--   * sin los valores de AUTO_INCREMENT: una instalación nueva arranca en 1
--
-- Se puede volver a correr sin riesgo: IF NOT EXISTS no toca tablas que ya
-- existen (NO borra datos).
-- ============================================================

-- ------------------------------------------------------------
-- 1) jugadores: una fila por persona que jugó.
--    token: la llave secreta que guarda su navegador para reconocerla.
--    ascii_bin: son 32 caracteres hexadecimales; comparación exacta,
--    distinguiendo mayúsculas, y 1 byte por carácter.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jugadores (
    id_jugador      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    alias           VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL,
    token           CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    fecha_creacion  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_jugador),
    UNIQUE KEY uq_jugadores_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2) partidas: una fila por partida.
--    resultado queda en 'en_curso' hasta que termina; si la persona
--    abandona, queda así para siempre. Criterio de las estadísticas:
--      * métricas de TIEMPO de reacción: usan todas las reacciones válidas,
--        termine o no la partida (una reacción medida en una partida
--        abandonada es un dato real);
--      * métricas de RESULTADO (partidas, % de fuga, duelo entre robots):
--        solo partidas terminadas (resultado <> 'en_curso'), porque una
--        partida sin terminar no tiene resultado.
--    seed: semilla del generador aleatorio, para poder reproducir la partida.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partidas (
    id_partida    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    id_jugador    INT(10) UNSIGNED NOT NULL,
    dispositivo   ENUM('pc','movil') COLLATE utf8mb4_unicode_ci NOT NULL,
    tipo_robot    ENUM('adaptativo','aleatorio') COLLATE utf8mb4_unicode_ci NOT NULL,
    seed          INT(10) UNSIGNED NOT NULL,
    fecha_inicio  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_fin     DATETIME DEFAULT NULL,
    resultado     ENUM('en_curso','contenida','descontrolada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_curso',
    PRIMARY KEY (id_partida),
    KEY fk_partidas_jugador (id_jugador),
    CONSTRAINT fk_partidas_jugador FOREIGN KEY (id_jugador) REFERENCES jugadores (id_jugador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3) rondas: una fila por ronda jugada (hasta 12 por partida).
--    tiempo_reaccion_ms es NULL cuando no hubo acierto (nunca 0).
--    sospechosa = 1 si el tiempo fue menor a 100 ms o el servidor detectó
--    algo raro: el dato se conserva, pero las estadísticas lo excluyen.
--    senuelo_boton / senuelo_instante_ms: NULL si la ronda no tuvo señuelo.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rondas (
    id_ronda             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    id_partida           INT(10) UNSIGNED NOT NULL,
    numero_ronda         TINYINT(3) UNSIGNED NOT NULL,
    boton_activado       TINYINT(3) UNSIGNED NOT NULL,
    senuelo_boton        TINYINT(3) UNSIGNED DEFAULT NULL,
    ventana_ms           SMALLINT(5) UNSIGNED NOT NULL,
    retardo_ms           SMALLINT(5) UNSIGNED NOT NULL,
    senuelo_instante_ms  SMALLINT(5) UNSIGNED DEFAULT NULL,
    temperatura          DECIMAL(3,2) NOT NULL,
    tiempo_reaccion_ms   SMALLINT(5) UNSIGNED DEFAULT NULL,
    tipo_resultado       ENUM('acierto','timeout','falso_inicio','senuelo') COLLATE utf8mb4_unicode_ci NOT NULL,
    sospechosa           TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id_ronda),
    KEY fk_rondas_partida (id_partida),
    CONSTRAINT fk_rondas_partida FOREIGN KEY (id_partida) REFERENCES partidas (id_partida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
