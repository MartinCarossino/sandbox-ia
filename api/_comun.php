<?php
/**
 * ========================================
 * Utilidades compartidas por los endpoints
 * ========================================
 */

require_once dirname(__FILE__) . '/../config/conexion.php';
require_once dirname(__FILE__) . '/../clases/GeneradorAleatorio.php';
require_once dirname(__FILE__) . '/../clases/Robot.php';
require_once dirname(__FILE__) . '/../clases/RobotAleatorio.php';
require_once dirname(__FILE__) . '/../clases/RobotAdaptativo.php';
require_once dirname(__FILE__) . '/../clases/RepositorioJuego.php';
require_once dirname(__FILE__) . '/../clases/ReglasPartida.php';
require_once dirname(__FILE__) . '/../clases/ServicioPartida.php';

/**
 * Envía una respuesta JSON y termina el script.
 */
function responderJson($datos, $codigoHttp = 200)
{
    http_response_code($codigoHttp);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos);
    exit;
}

/**
 * Lee un dato enviado por POST, o devuelve el valor por defecto.
 */
function parametro($nombre, $porDefecto = null)
{
    return isset($_POST[$nombre]) ? $_POST[$nombre] : $porDefecto;
}

/**
 * Arma el servicio con todas sus piezas.
 */
function crearServicio()
{
    $conexion = conectar();

    // [POO · POLIMORFISMO]
    // Único lugar donde se decide QUÉ robots existen. ServicioPartida recibe la lista y sortea entre ellos sin saber cómo es cada uno:
    // agregar un tercer robot es sumarlo a este array (y a la columna tipo_robot).
    return new ServicioPartida(
        new RepositorioJuego($conexion),
        array(new RobotAdaptativo(), new RobotAleatorio()),
        new ReglasPartida()
    );
}

/**
 * Ejecuta la lógica de un endpoint con manejo de errores común:
 * solo POST, sesión iniciada y respuestas JSON con el código HTTP correcto.
 * Recibe una función anónima (closure) con lo específico de cada endpoint.
 */
function ejecutarEndpoint($accion)
{
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responderJson(array('ok' => false, 'error' => 'Usá el método POST.'), 405);
        }

        session_start();

        $respuesta       = $accion();
        $respuesta['ok'] = true;
        responderJson($respuesta);

    } catch (InvalidArgumentException $e) {
        // Datos mal enviados por el navegador.
        responderJson(array('ok' => false, 'error' => $e->getMessage()), 400);

    } catch (mysqli_sql_exception $e) {
        // Va ANTES que RuntimeException porque mysqli_sql_exception hereda de ella.
        error_log('Sandbox IA - error de base de datos: ' . $e->getMessage());
        responderJson(
            array('ok' => false, 'error' => 'Error interno.'),
            500
        );

    } catch (RuntimeException $e) {
        // Estado de partida inválido (ronda duplicada, sin partida iniciada...).
        responderJson(array('ok' => false, 'error' => $e->getMessage()), 409);

    } catch (Exception $e) {
        error_log('Sandbox IA - error inesperado: ' . $e->getMessage());
        responderJson(
            array('ok' => false, 'error' => 'Error interno.'),
            500
        );
    }
}