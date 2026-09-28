<?php
// GET, sin parámetros. Devuelve TODO lo que dibuja el panel en una sola respuesta.
// Endpoint PÚBLICO y de solo lectura: no usa sesión ni recibe datos del visitante.
require_once dirname(__FILE__) . '/../config/conexion.php';
require_once dirname(__FILE__) . '/../clases/RepositorioEstadisticas.php';

// Cuántas partidas recientes mostrar en la lista "en vivo".
define('CANTIDAD_ULTIMAS_PARTIDAS', 10);

// true: se muestra el alias que escribió cada jugador. false: todos figuran como "Anónimo".
define('MOSTRAR_ALIAS', true);

// Cada consulta recorre las reacciones completas. Con 0 se consulta la base en CADA visita.
// Con 10, igual al intervalo con que el panel vuelve a preguntar (INTERVALO_MS en estadisticas.js),
// la base responde una vez cada 10 segundos aunque haya muchas pestañas abiertas, y todas reciben esa misma respuesta. 
// El dato sigue saliendo de la base; solo se comparte entre visitas. Si la carpeta cache/ no se puede escribir, no hay caché y todo sigue funcionando.
define('SEGUNDOS_DE_CACHE', 10);
$archivoCache = dirname(__FILE__) . '/../cache/estadisticas.json';

function enviarJson($texto, $codigoHttp)
{
    http_response_code($codigoHttp);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');   // el navegador siempre pregunta; la caché, si existe, vive en el servidor
    echo $texto;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    enviarJson(json_encode(array('ok' => false, 'error' => 'Usá el método GET.')), 405);
}

if (SEGUNDOS_DE_CACHE > 0 && is_file($archivoCache) && (time() - filemtime($archivoCache)) < SEGUNDOS_DE_CACHE) {
    enviarJson(file_get_contents($archivoCache), 200);
}

try {
    $repositorio = new RepositorioEstadisticas(conectar());

    $ultimas = $repositorio->ultimasPartidas(CANTIDAD_ULTIMAS_PARTIDAS);
    if (!MOSTRAR_ALIAS) {
        foreach ($ultimas as $i => $partida) {
            $ultimas[$i]['alias'] = 'Anónimo';
        }
    }

    $json = json_encode(array(
        'ok'           => true,
        'generado'     => time(),
        'pulso'        => $repositorio->pulso(),
        'percentiles'  => (object) $repositorio->percentiles(),   // objeto: {} y no [] si está vacío
        'dispositivos' => $repositorio->dispositivos(),
        'botones'      => $repositorio->botones(),
        'rondas'       => $repositorio->porRonda(),
        'robots'       => $repositorio->robots(),
        'duelo'        => $repositorio->duelo(),
        'ultimas'      => $ultimas
    ), JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new Exception('No se pudo armar el JSON: ' . json_last_error_msg());
    }

    if (SEGUNDOS_DE_CACHE > 0) {
        // Se escribe en un archivo aparte y se renombra: así nadie lee una respuesta a medio escribir.
        @mkdir(dirname($archivoCache));
        $temporal = $archivoCache . '.' . getmypid();
        if (@file_put_contents($temporal, $json) === false || !@rename($temporal, $archivoCache)) {
            @unlink($temporal);
        }
    }

    enviarJson($json, 200);

} catch (Exception $e) {
    error_log('Sandbox IA - error en estadisticas: ' . $e->getMessage());
    enviarJson(json_encode(array(
        'ok'    => false,
        'error' => 'Error interno.'
    )), 500);
}