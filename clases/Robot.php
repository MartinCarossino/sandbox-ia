<?php
/**
 * =======================
 * Robot (clase abstracta)
 * =======================
 * Define QUÉ hace cualquier robot del juego, sin decidir CÓMO.
 *
 * El flujo de una ronda vive acá (prepararRonda) y es igual para todos.
 * Lo que cambia entre robots (qué botón elegir, cuánto tiempo darte) lo resuelve cada clase hija.
 */
abstract class Robot
{
    const SORTEOS_POR_RONDA = 6;   // retardo, azar, botón al azar, azar de señuelo, botón de señuelo e instante
    const RETARDO_MINIMO_MS = 600;
    const RETARDO_MEDIA_MS  = 900;
    const RETARDO_TOPE_MS   = 3000;
    const VENTANA_MINIMA_MS = 300;

    // [POO · ABSTRACCIÓN]
    // Estos métodos son "contratos": el Robot declara QUÉ debe poder hacer cualquier robot
    // (decir su nombre y su tipo, elegir botón, calcular ventana) pero no dice CÓMO. 
    // Por ser abstracta, esta clase no se puede instanciar con "new Robot()": "un robot en general" no existe, solo existen robots concretos.
    abstract public function nombre();

    // Identificador corto que se guarda en partidas.tipo_robot. Tiene que coincidir con los valores del ENUM de esa columna.
    abstract public function tipo();

    abstract protected function elegirBoton($azar, $botonAlAzar, $temperatura, array $mediasPorBoton);

    abstract protected function calcularVentana($numeroRonda, $medianaMs);

    // Probabilidad (0 a 1) de que esta ronda tenga señuelo.
    // Cada robot decide la suya: el aleatorio con un valor fijo, el adaptativo escalando con el progreso de la partida, igual que hace con la temperatura.
    abstract protected function probabilidadSenuelo($numeroRonda);

    /**
     * Arma todos los datos de una ronda a partir de la semilla de la partida.
     *
     * Se hacen SIEMPRE 6 sorteos por ronda, los use o no cada robot: 
     * los tres de siempre (retardo, azar, botón al azar) más los tres del señuelo (si aparece, en qué botón y en qué instante).
     * Se sortean los seis SIEMPRE, aunque el señuelo termine sin aparecer,
     * para que la ronda N caiga en la misma posición de la secuencia sin importar qué robot juegue ni qué tan seguido decida mostrar señuelo. 
     * Así cualquier ronda se reconstruye a partir de la semilla.
     *
     * Las claves del array coinciden con las columnas de la tabla `rondas`.
     *
     * @param int        $semilla        semilla de la partida
     * @param int        $numeroRonda    número de ronda (empieza en 1)
     * @param array      $mediasPorBoton array(1 => media_ms, ..., 6 => media_ms), o vacío si el jugador es nuevo
     * @param float|null $medianaMs      mediana del jugador (null si es nuevo)
     * @return array
     */
    public function prepararRonda($semilla, $numeroRonda, array $mediasPorBoton, $medianaMs = null)
    {
        $generador = new GeneradorAleatorio($semilla);

        // Saltamos los sorteos de las rondas anteriores.
        $generador->avanzar(self::SORTEOS_POR_RONDA * ($numeroRonda - 1));

        $retardoMs = $generador->retardoExponencial(
            self::RETARDO_MINIMO_MS,
            self::RETARDO_MEDIA_MS,
            self::RETARDO_TOPE_MS
        );
        $azar        = $generador->siguienteUniforme();
        $botonAlAzar = $generador->enteroEntre(1, ReglasPartida::CANTIDAD_BOTONES);

        $temperatura   = $this->temperaturaDeRonda($numeroRonda);
        $botonActivado = $this->elegirBoton($azar, $botonAlAzar, $temperatura, $mediasPorBoton);
        $senuelo       = $this->sortearSenuelo($generador, $numeroRonda, $botonActivado, $retardoMs);

        // [POO · POLIMORFISMO]
        // Acá no sabemos qué robot está ejecutando este código. 
        // Según el objeto real ($this), PHP llamará a la versión de RobotAleatorio o de RobotAdaptativo. Un solo código, comportamientos distintos.
        return array(
            'numero_ronda'        => $numeroRonda,
            'boton_activado'      => $botonActivado,
            'retardo_ms'          => $retardoMs,
            'ventana_ms'          => $this->calcularVentana($numeroRonda, $medianaMs),
            'temperatura'         => $temperatura,
            'senuelo_boton'       => $senuelo[0],
            'senuelo_instante_ms' => $senuelo[1]
        );
    }

    /**
     * Decide si esta ronda tiene señuelo y, si aparece, dónde y cuándo.
     *
     * Se sortean SIEMPRE los tres valores (azar, botón, instante), aparezca o no el señuelo: lo que cambia es si el resultado se usa o se descarta.
     * Así la cantidad de sorteos no depende de la probabilidad de cada robot, y una partida sigue siendo reproducible aunque el día de mañana cambie esa probabilidad.
     *
     * [POO · ENCAPSULAMIENTO]
     * Método privado: nadie fuera de Robot arma un señuelo por su cuenta. Los robots hijos solo dicen QUÉ TAN PROBABLE es (probabilidadSenuelo);
     * dónde y cuándo aparece es un detalle interno, igual para todos.
     *
     * @return array array($senueloBoton, $senueloInstanteMs), ambos null si no aparece
     */
    private function sortearSenuelo(GeneradorAleatorio $generador, $numeroRonda, $botonActivado, $retardoMs)
    {
        $senueloAzar       = $generador->siguienteUniforme();
        $senueloBotonBruto = $generador->enteroEntre(1, ReglasPartida::CANTIDAD_BOTONES - 1);
        $senueloInstanteMs = $generador->enteroEntre(0, $retardoMs - ReglasPartida::DURACION_SENUELO_MS);

        if ($senueloAzar >= $this->probabilidadSenuelo($numeroRonda)) {
            return array(null, null);
        }

        // Nunca en el mismo botón donde después sale el rojo: el señuelo es una distracción hacia OTRO lugar, no un adelanto de dónde viene.
        // enteroEntre ya sorteó entre 1 y N-1; acá se "salta" el número del rojo para cubrir los N botones restantes sin volver a sortear.
        $senueloBoton = ($senueloBotonBruto < $botonActivado) ? $senueloBotonBruto : $senueloBotonBruto + 1;

        return array($senueloBoton, $senueloInstanteMs);
    }

    // [POO · HERENCIA]
    // Estos tres métodos concretos se escriben UNA vez acá y los heredan todos los robots. 
    // Si mañana cambia cómo sube la temperatura, se modifica en un solo lugar y todos los robots quedan actualizados.

    /**
     * Avance de la partida: 0 en la primera ronda y 1 en la última.
     * Toda la dificultad se expresa según este valor, no según "la ronda 8": así se puede cambiar la cantidad de rondas sin reescribir las fórmulas.
     * (El total sale de ReglasPartida, que es quien manda en las reglas.)
     *
     * @return float entre 0 y 1
     */
    protected function progresoPartida($numeroRonda)
    {
        $pasos = max(1, ReglasPartida::RONDAS_POR_PARTIDA - 1);
        return min(1.0, max(0.0, ($numeroRonda - 1) / $pasos));
    }

    /**
     * La temperatura es la probabilidad de que el robot ignore su estrategia y elija un botón al azar.
     * Sube con el progreso: cuanto más avanza la partida, más impredecible se vuelve (empieza en 0.20 y llega a 0.90 en la última ronda).
     * Es un valor de ajuste, no una regla fija.
     *
     * @return float
     */
    protected function temperaturaDeRonda($numeroRonda)
    {
        return round(0.20 + 0.70 * $this->progresoPartida($numeroRonda), 2);
    }

    /**
     * Ventana fija que baja de 1000 ms en la primera ronda a 440 ms en la última, con piso de 300.
     *
     * @return int
     */
    protected function ventanaFija($numeroRonda)
    {
        $ventanaMs = (int) round(1000 - 560 * $this->progresoPartida($numeroRonda));
        return max(self::VENTANA_MINIMA_MS, $ventanaMs);
    }
}