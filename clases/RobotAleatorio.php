<?php
/**
 * ==============
 * RobotAleatorio
 * ==============
 * El robot de control: elige el botón al azar y da una ventana fija.
 * Sirve de grupo de control para comparar contra el adaptativo.
 */
class RobotAleatorio extends Robot   // [POO · HERENCIA] recibe todo lo de Robot
{
    // Probabilidad de señuelo BAJA y FIJA: este robot no se pone más traicionero con el progreso de la partida
    // (esa progresión es la marca del adaptativo). Mismo espíritu que su ventana fija y su botón al azar.
    const PROBABILIDAD_SENUELO = 0.15;

    public function nombre()
    {
        return 'Robot aleatorio';
    }

    // [POO · POLIMORFISMO]
    // Cada robot responde a su manera al mismo mensaje tipo(). Este valor se guarda en partidas.tipo_robot, así que tiene que coincidir con el ENUM.
    public function tipo()
    {
        return 'aleatorio';
    }

    // [POO · POLIMORFISMO]
    // Implementa el contrato de Robot a su manera: ignora la temperatura y el perfil del jugador, y devuelve siempre el botón sorteado al azar.
    protected function elegirBoton($azar, $botonAlAzar, $temperatura, array $mediasPorBoton)
    {
        return $botonAlAzar;
    }

    protected function calcularVentana($numeroRonda, $medianaMs)
    {
        // Reutiliza el método heredado de Robot (herencia en acción).
        return $this->ventanaFija($numeroRonda);
    }

    // [POO · POLIMORFISMO]
    // Mismo mensaje que en RobotAdaptativo, otra respuesta: acá la probabilidad no depende de la ronda.
    protected function probabilidadSenuelo($numeroRonda)
    {
        return self::PROBABILIDAD_SENUELO;
    }
}