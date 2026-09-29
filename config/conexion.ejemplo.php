<?php
// Copiá este archivo como conexion.php (en la misma carpeta) y completá tus datos.
define('BD_SERVIDOR', 'localhost');
define('BD_USUARIO', 'tu_usuario');
define('BD_CLAVE', 'tu_clave');
define('BD_NOMBRE', 'sandbox_ia');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function conectar()
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conexion = new mysqli(BD_SERVIDOR, BD_USUARIO, BD_CLAVE, BD_NOMBRE);
    $conexion->set_charset('utf8mb4');
    $conexion->query('SET SESSION SQL_BIG_SELECTS = 1');

    return $conexion;
}