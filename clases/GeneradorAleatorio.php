<?php
/**
 * Generador pseudoaleatorio propio y REPRODUCIBLE.
 *
 * La semilla vive SOLO en el servidor. Si el navegador la conociera, un jugador podría predecir el próximo rojo y los datos quedarían contaminados.
 * 
 */
class GeneradorAleatorio
{
    const MODULO        = 2147483647; // 2^31 - 1, un número primo
    const MULTIPLICADOR = 48271;
    const CALENTAMIENTO = 5;          // sorteos descartados al iniciar

    // [POO · ENCAPSULAMIENTO]
    // El estado interno es PRIVADO. Si desde afuera alguien lo dejara en 0, el generador quedaría clavado en 0 para siempre (0 * k % m = 0).
    private $semilla;
    private $estado;

    /**
     * @param int $semilla entero entre 1 y 2147483646
     */
    public function __construct($semilla)
    {
        $this->semilla = $semilla;
        $this->reiniciar();
    }

    private function reiniciar()
    {
        $estadoInicial = abs($this->semilla) % self::MODULO;

        // El estado nunca puede ser 0 (ver nota de encapsulamiento).
        $this->estado = ($estadoInicial === 0) ? 1 : $estadoInicial;

        // Con semillas chicas, los primeros valores salen muy bajos (semilla 1 daría 0.00002). Descartamos unos cuantos para "mezclar".
        $this->avanzar(self::CALENTAMIENTO);
    }

    // Número uniforme estrictamente entre 0 y 1 (nunca 0, nunca 1). Que nunca sea 0 es lo que permite usar log() sin riesgo de error.
    public function siguienteUniforme()
    {
        // fmod() en lugar de %: el operador % convierte a entero, y en un PHP de 32 bits el producto (hasta ~10^14) se desbordaría.
        // fmod() opera con decimales de doble precisión, que representan ese número de forma exacta: mismo resultado en cualquier servidor.
        $this->estado = fmod($this->estado * self::MULTIPLICADOR, self::MODULO);
        return $this->estado / self::MODULO;
    }

    // Descarta N sorteos. Sirve para "saltar" hasta la ronda que interesa sin guardar el estado del generador entre peticiones HTTP.
    public function avanzar($cantidad)
    {
        for ($i = 0; $i < $cantidad; $i++) {
            $this->siguienteUniforme();
        }
    }

    /**
     * Retardo con distribución exponencial (método de la transformada inversa):
     *   retardo = mínimo + (-LN(U) * media)
     * Es una distribución "sin memoria": esperar más no da pistas de cuándo llegará el rojo, así que es imposible anticiparse.
     */
    public function retardoExponencial($minimoMs, $mediaMs, $topeMs)
    {
        $uniforme = $this->siguienteUniforme();
        $retardoMs = $minimoMs + (int) round(-log($uniforme) * $mediaMs);

        return min($topeMs, $retardoMs);
    }

    // Entero uniforme entre $minimo y $maximo, ambos incluidos.
    public function enteroEntre($minimo, $maximo)
    {
        $rango = $maximo - $minimo + 1;
        return $minimo + (int) floor($this->siguienteUniforme() * $rango);
    }
}