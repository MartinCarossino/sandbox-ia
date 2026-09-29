'use strict';

/* 
   Protocolo de contención · lógica del juego
   El servidor decide TODO lo importante (rondas, retardos, reglas del descontrol).
   Este archivo solo dibuja, mide el tiempo de reacción y avisa al servidor lo que pasó.
*/

// Pausa entre el resultado de una ronda y la siguiente. Debe ser MENOR que MARGEN_MAXIMO_MS (5000) de ServicioPartida.php: 
// el servidor empieza a contar la ronda siguiente en cuanto la entrega, pausa incluida.
const PAUSA_ENTRE_RONDAS_MS = 800;
const PAUSA_FINAL_MS = 1600;   // tiempo para ver el ojo del robot antes de la pantalla final

const CLAVE_TOKEN_JUGADOR = 'token_jugador';
const CLAVE_ALIAS = 'alias';

// localStorage puede estar bloqueado (modo privado): siempre con try/catch.
const almacen = {
    leer: function (clave) {
        try { return localStorage.getItem(clave); } catch (e) { return null; }
    },
    guardar: function (clave, valor) {
        try { localStorage.setItem(clave, valor); } catch (e) { /* sin persistencia */ }
    }
};

function pausa(milisegundos) {
    return new Promise(function (resolver) { setTimeout(resolver, milisegundos); });
}

// Reintentos al INICIAR la partida, si el hosting no respondió con datos (ver ClienteApi.iniciarPartida).
const INTENTOS_AL_INICIAR = 3;
const ESPERA_ENTRE_INTENTOS_MS = 1500;

/* ---------------------------------------
   ClienteApi: habla con los endpoints PHP
   --------------------------------------- */
class ClienteApi {

    // El método privado (#) esconde el detalle técnico del envío.
    async #enviar(url, datos) {
        const cuerpo = new URLSearchParams();
        Object.keys(datos).forEach(function (clave) {
            if (datos[clave] !== null && datos[clave] !== undefined) {
                cuerpo.append(clave, datos[clave]);
            }
        });

        let respuesta;
        try {
            respuesta = await fetch(url, { method: 'POST', body: cuerpo, credentials: 'same-origin' });
        } catch (error) {
            throw this.#errorReintentable('No hay conexión con el servidor.');
        }

        let json;
        try {
            json = await respuesta.json();
        } catch (error) {
            throw this.#errorReintentable('El servidor devolvió una respuesta inválida.');
        }

        if (!json.ok) {
            throw new Error(json.error || 'Error desconocido.');
        }
        return json;
    }

    // Marca los errores en los que el servidor NO llegó a responder con datos: probar de nuevo tiene sentido.
    // Un error que llega en el JSON ("Error interno.", "Demasiadas partidas...") es una respuesta real: no se reintenta.
    #errorReintentable(texto) {
        const error = new Error(texto);
        error.reintentable = true;
        return error;
    }

    // Solo el INICIO se reintenta. Si el hosting está saturado un instante (por ejemplo, varias personas jugando a la vez),
    // esperar un poco y volver a pedir suele alcanzar. En las rondas no se hace: el servidor rechaza a propósito una ronda
    // repetida (es parte del anti-trampa), así que un reintento cancelaría la partida en lugar de salvarla.
    // alReintentar (opcional) avisa antes de cada nuevo intento, para que la pantalla muestre qué está pasando.
    async iniciarPartida(tokenJugador, alias, dispositivo, alReintentar) {
        const datos = {
            token_jugador: tokenJugador,
            alias: alias,
            dispositivo: dispositivo
        };

        for (let intento = 1; ; intento++) {
            try {
                return await this.#enviar('api/iniciar_partida.php', datos);
            } catch (error) {
                if (!error.reintentable || intento >= INTENTOS_AL_INICIAR) {
                    throw error;
                }
                if (alReintentar) {
                    alReintentar(intento + 1);
                }
                await pausa(ESPERA_ENTRE_INTENTOS_MS * intento);   // 1,5 s y después 3 s
            }
        }
    }

    registrarRonda(numeroRonda, tipoResultado, tiempoMs) {
        return this.#enviar('api/registrar_ronda.php', {
            numero_ronda: numeroRonda,
            tipo_resultado: tipoResultado,
            tiempo_reaccion_ms: tiempoMs
        });
    }
}

/* ---------------------------------------------
   RobotVisual: controla el dibujo SVG del robot
   --------------------------------------------- */
// [POO · ABSTRACCIÓN]
// Quien usa esta clase dice QUÉ le pasa al robot (vigilar, alertar, escapar) sin saber cómo se logra: atributos del SVG, variables CSS, animaciones.
class RobotVisual {

    // [POO · ENCAPSULAMIENTO]
    // El elemento SVG es privado: desde afuera nadie puede dejarlo en un estado inventado ("modo-raro").
    // Solo se llega a los estados válidos a través de los métodos públicos.
    #svg;

    constructor(elementoSvg) {
        this.#svg = elementoSvg;
    }

    vigilar()  { this.#svg.setAttribute('data-estado', 'vigilando'); }
    alertar()  { this.#svg.setAttribute('data-estado', 'alerta'); }
    contener() { this.#svg.setAttribute('data-estado', 'contenida'); }
    escapar()  { this.#svg.setAttribute('data-estado', 'escapada'); }

    // Intensidad del brillo de los ojos: 0 (tranquilo) a 100 (descontrol total).
    ponerDescontrol(nivel) {
        this.#svg.style.setProperty('--descontrol', String(nivel / 100));
    }
}

/* --------------------------------------------
   PanelBotones: los seis botones de contención
   -------------------------------------------- */
class PanelBotones {

    // [POO · ENCAPSULAMIENTO]
    // Los botones y la función de aviso son privados: el Juego no manipula el HTML directamente, solo pide "encender el 2" o "confirmar el 2".
    #botones;
    #alPulsar;
    #sonido;

    constructor(contenedor, alPulsar, sonido) {
        this.#botones = Array.from(contenedor.querySelectorAll('.boton'));
        this.#alPulsar = alPulsar;
        this.#sonido = sonido;

        this.#botones.forEach((boton) => {
            // "pointerdown" reacciona en el instante del toque. "click" espera a soltar el dedo y en algunos celulares suma un retardo que arruinaría la medición.
            boton.addEventListener('pointerdown', (evento) => {
                evento.preventDefault();
                // #alPulsar va PRIMERO: adentro es lo primero que se mide el tiempo con performance.now().
                // El clic se toca después, así no le suma ni un milisegundo a esa medición.
                this.#alPulsar(Number(boton.dataset.numero));
                this.#sonido.clic();
            });
            // Evita el menú contextual del mantener-presionado en el celular.
            boton.addEventListener('contextmenu', (evento) => evento.preventDefault());
        });
    }

    reiniciar() {
        this.#botones.forEach(function (boton) { boton.classList.remove('rojo', 'acierto', 'senuelo'); });
    }

    encender(numero) {
        this.#botones[numero - 1].classList.add('rojo');
    }

    confirmar(numero) {
        const boton = this.#botones[numero - 1];
        boton.classList.remove('rojo');
        boton.classList.add('acierto');
    }

    // El "falso botón rojo": mismo mecanismo que encender()/confirmar(), pero con su propia clase CSS (ámbar, no rojo) para no confundirlo con el real.
    encenderSenuelo(numero) {
        this.#botones[numero - 1].classList.add('senuelo');
    }

    apagarSenuelo(numero) {
        this.#botones[numero - 1].classList.remove('senuelo');
    }
}

/* --------------------------------------------------
   MotorSonido: todos los efectos de sonido del juego
   -------------------------------------------------- */

class MotorSonido {

    #contexto = null;
    #tension = null;
    #ambiente = null;

    // Los navegadores exigen un gesto del usuario (tocar un botón) antes de dejar sonar cualquier cosa.
    // Por eso el AudioContext no se crea en el constructor: se crea (o se reactiva) acá, 
    // y el Juego llama a esto desde el submit de "Iniciar", que ya es ese gesto.
    async iniciar() {
        if (!this.#contexto) {
            const Contexto = window.AudioContext || window.webkitAudioContext;
            if (!Contexto) { return; }   // navegador sin Web Audio: el juego sigue sin sonido
            this.#contexto = new Contexto();
        }
        if (this.#contexto.state === 'suspended') {
            try { await this.#contexto.resume(); } catch (error) { /* seguimos sin sonido */ }
        }
        console.log('[sonido] AudioContext listo, estado:', this.#contexto.state);   // sacar cuando esté confirmado
    }

    // Un tono simple con envolvente ataque-caída: sube de golpe y cae con curva exponencial.
    // "cuandoSeg" lo usan alarma() y las melodías para escalonar varias notas en el tiempo con una sola llamada a currentTime.
    #tono(frecuencia, duracionSeg, tipo, volumen, cuandoSeg) {
        const ctx = this.#contexto;
        if (!ctx) { return; }
        const inicio = ctx.currentTime + (cuandoSeg || 0);

        const oscilador = ctx.createOscillator();
        oscilador.type = tipo;
        oscilador.frequency.setValueAtTime(frecuencia, inicio);

        const ganancia = ctx.createGain();
        ganancia.gain.setValueAtTime(0, inicio);
        ganancia.gain.linearRampToValueAtTime(volumen, inicio + 0.008);
        ganancia.gain.exponentialRampToValueAtTime(0.0001, inicio + duracionSeg);

        oscilador.connect(ganancia).connect(ctx.destination);
        oscilador.start(inicio);
        oscilador.stop(inicio + duracionSeg + 0.02);
    }

    // Clic de botón ---------- No es un tono: es ruido blanco filtrado y recortado en 30 ms, como el "tac" seco de un botón físico.
    clic() {
        const ctx = this.#contexto;
        if (!ctx) { return; }
        const duracion = 0.03;

        const buffer = ctx.createBuffer(1, Math.ceil(ctx.sampleRate * duracion), ctx.sampleRate);
        const datos = buffer.getChannelData(0);
        for (let i = 0; i < datos.length; i++) {
            datos[i] = (Math.random() * 2 - 1) * (1 - i / datos.length);   // ruido que decae
        }

        const fuente = ctx.createBufferSource();
        fuente.buffer = buffer;

        const filtro = ctx.createBiquadFilter();
        filtro.type = 'bandpass';
        filtro.frequency.value = 1800;

        const ganancia = ctx.createGain();
        ganancia.gain.value = 0.5;

        fuente.connect(filtro).connect(ganancia).connect(ctx.destination);
        fuente.start();
    }

    // Tensión mientras se espera la alarma
    tensionIniciar(duracionMs) {
        const ctx = this.#contexto;
        if (!ctx) { return; }
        this.tensionDetener();   // por si quedó algo sonando de una ronda anterior

        const duracionSeg = Math.max(duracionMs / 1000, 0.3);
        const arranque = ctx.currentTime;
        const estado = { cancelado: false, temporizador: null };
        this.#tension = estado;

        // Función que se llama a sí misma: toca un golpe y programa el siguiente con un intervalo cada vez más corto, hasta que se cancele o se acabe la ronda.
        const golpe = () => {
            if (estado.cancelado) { return; }

            const progreso = Math.min((ctx.currentTime - arranque) / duracionSeg, 1);   // 0 a 1

            this.#tono(180, 0.1, 'triangle', 0.22 + progreso * 0.1, 0);

            const intervaloMs = 900 - progreso * 680;   // 900 ms al empezar, 220 ms cerca del final
            estado.temporizador = setTimeout(golpe, intervaloMs);
        };
        golpe();
    }

    // Segura de llamar aunque no haya nada sonando (por ejemplo, si la ronda se resuelve por un falso inicio antes del primer golpe).
    tensionDetener() {
        if (!this.#tension) { return; }
        this.#tension.cancelado = true;
        clearTimeout(this.#tension.temporizador);
        this.#tension = null;
    }

    // Ambiente de la pantalla de inicio
    ambienteIniciar() {
        const ctx = this.#contexto;
        if (!ctx || this.#ambiente) { return; }   // ya está sonando, no se duplica
        console.log('[sonido] arrancando el ambiente de fondo');   // sacar cuando esté confirmado

        const inicio = ctx.currentTime;

        const osciladorA = ctx.createOscillator();
        osciladorA.type = 'sawtooth';
        osciladorA.frequency.value = 110;

        const osciladorB = ctx.createOscillator();
        osciladorB.type = 'sawtooth';
        osciladorB.frequency.value = 110.8;

        const filtro = ctx.createBiquadFilter();
        filtro.type = 'lowpass';
        filtro.frequency.value = 450;
        filtro.Q.value = 1.2;

        const lfo = ctx.createOscillator();
        lfo.type = 'sine';
        lfo.frequency.value = 0.08;   // un vaivén completo cada ~12 segundos

        const gananciaLfo = ctx.createGain();
        gananciaLfo.gain.value = 220;   // cuánto mueve la frecuencia del filtro

        const ganancia = ctx.createGain();
        ganancia.gain.setValueAtTime(0, inicio);
        ganancia.gain.linearRampToValueAtTime(0.13, inicio + 1.5);   // entrada lenta, no un golpe

        lfo.connect(gananciaLfo);
        gananciaLfo.connect(filtro.frequency);

        osciladorA.connect(filtro);
        osciladorB.connect(filtro);
        filtro.connect(ganancia);
        ganancia.connect(ctx.destination);

        osciladorA.start(inicio);
        osciladorB.start(inicio);
        lfo.start(inicio);

        this.#ambiente = { osciladorA, osciladorB, lfo, ganancia };
    }

    // Fade de salida un poco más largo que tensionDetener()
    ambienteDetener() {
        if (!this.#ambiente || !this.#contexto) { this.#ambiente = null; return; }
        const ctx = this.#contexto;
        const { osciladorA, osciladorB, lfo, ganancia } = this.#ambiente;
        const fin = ctx.currentTime + 0.5;

        ganancia.gain.cancelScheduledValues(ctx.currentTime);
        ganancia.gain.setValueAtTime(ganancia.gain.value, ctx.currentTime);
        ganancia.gain.linearRampToValueAtTime(0.0001, fin);
        osciladorA.stop(fin + 0.02);
        osciladorB.stop(fin + 0.02);
        lfo.stop(fin + 0.02);

        this.#ambiente = null;
    }

    // Alarma: el botón se pone rojo
    alarma() {
        this.#tono(880, 0.09, 'sawtooth', 0.14, 0);
        this.#tono(660, 0.09, 'sawtooth', 0.14, 0.09);
        this.#tono(880, 0.12, 'sawtooth', 0.14, 0.18);
    }

    // Resultado de la ronda
    acierto() {
        this.#tono(523.25, 0.09, 'triangle', 0.12, 0);
        this.#tono(659.25, 0.09, 'triangle', 0.12, 0.07);
        this.#tono(783.99, 0.14, 'triangle', 0.12, 0.14);
    }

    // Un solo tono grave, sin melodía: alcanza para distinguirlo del acierto por oído. Sirve para timeout, señuelo y falso inicio por igual.
    error() {
        this.#tono(140, 0.22, 'square', 0.1, 0);
    }

    // Fin de la partida
    victoria() {
        this.#tono(523.25, 0.12, 'triangle', 0.13, 0);
        this.#tono(659.25, 0.12, 'triangle', 0.13, 0.11);
        this.#tono(783.99, 0.12, 'triangle', 0.13, 0.22);
        this.#tono(1046.5, 0.28, 'triangle', 0.14, 0.33);
    }

    // Dos notas graves y descendentes en diente de sierra: el eco "áspero" de la alarma, pero más largo y sin resolución, para la IA escapada.
    derrota() {
        this.#tono(196, 0.35, 'sawtooth', 0.13, 0);
        this.#tono(164.81, 0.5, 'sawtooth', 0.13, 0.22);
    }
}

/* --------------------
   Juego: coordina todo
   -------------------- */
class Juego {

    // [POO · ENCAPSULAMIENTO]
    // Todo el estado de la partida es privado. Desde afuera solo se puede llamar a iniciar(): 
    // nadie puede, por ejemplo, poner la fase en "rojo" a mano y hacer trampa con el cronómetro.
    #api;
    #robot;
    #botones;
    #sonido;
    #el;
    #ocupado = false;         // hay una partida en curso o conectándose
    #activa = false;          // la partida sigue en juego (para cancelar si se oculta la pestaña)
    #fase = 'reposo';         // 'reposo' | 'esperando' | 'rojo' | 'resolviendo'
    #ronda = null;
    #rondasTotales = 0;
    #temporizador = null;
    #temporizadorSenueloMostrar = null;
    #temporizadorSenueloOcultar = null;
    #senueloBoton = null;      // número del botón con el señuelo encendido, null si no hay
    #inicioRojo = 0;
    #resumen = null;

    constructor(elementos, api, sonido) {
        this.#el = elementos;
        this.#api = api;
        this.#robot = new RobotVisual(elementos.robot);
        this.#sonido = sonido;
        this.#botones = new PanelBotones(elementos.botones, (numero) => this.#alPulsar(numero), this.#sonido);

        // Si el jugador cambia de pestaña o minimiza el navegador, el navegador frena los temporizadores y los tiempos medidos dejan de ser confiables.
        // Se cancela la partida: las rondas ya guardadas siguen siendo válidas y la partida queda "en_curso". El análisis usa sus reacciones válidas en
        // las métricas de tiempo, pero la excluye de las de resultado (fuga, duelo), porque no tiene resultado.
        document.addEventListener('visibilitychange', () => {
            if (document.hidden && this.#activa) {
                this.#cancelar('Saliste de la pestaña durante la partida.\nSe canceló para no falsear los tiempos.');
            }
        });
    }

    async iniciar(alias) {
        if (this.#ocupado) { return; }   // evita el doble toque en "Iniciar"
        this.#ocupado = true;

        // El submit de "Iniciar" es un gesto del usuario: es el único momento en que el navegador deja crear/activar el AudioContext.
        // Si ya estaba sonando el ambiente de la pantalla de inicio, se corta acá: a partir de este punto el sonido de la partida toma la posta.
        await this.#sonido.iniciar();
        this.#sonido.ambienteDetener();

        this.#resumen = { tiempos: [], timeouts: 0, falsosInicios: 0, senuelos: 0 };
        this.#el.app.removeAttribute('data-resultado');
        this.#botones.reiniciar();
        this.#robot.vigilar();
        this.#pintarNivel(0);
        this.#pintarRonda('CONECTANDO');
        this.#mensaje('Estableciendo conexión...', '');
        this.#mostrar('juego');

        // El dispositivo principal de entrada: "coarse" = pantalla táctil.
        const dispositivo = window.matchMedia('(pointer: coarse)').matches ? 'movil' : 'pc';

        try {
            const tokenGuardado = almacen.leer(CLAVE_TOKEN_JUGADOR) || '';
            const respuesta = await this.#api.iniciarPartida(tokenGuardado, alias, dispositivo, (intento) => {
                this.#mensaje('Muchos operadores conectados · reintento ' + intento + ' de ' + INTENTOS_AL_INICIAR + '...', '');
            });

            almacen.guardar(CLAVE_TOKEN_JUGADOR, respuesta.token_jugador);
            almacen.guardar(CLAVE_ALIAS, alias);

            this.#rondasTotales = respuesta.rondas_totales;
            this.#activa = true;
            this.#jugarRonda(respuesta.ronda);
        } catch (error) {
            if (error.reintentable) {
                // El servidor gratuito no dio abasto: no es un error del juego, y el mensaje lo dice.
                this.#mostrarFin('cancelada', 'DEMASIADOS OPERADORES',
                    'Hay mucha gente conteniendo a la IA en este momento y el servidor no da abasto. Esperá unos segundos y probá de nuevo.');
            } else {
                this.#mostrarFin('cancelada', 'NO SE PUDO INICIAR', error.message);
            }
        }
    }

    // Flujo de una ronda

    #jugarRonda(ronda) {
        this.#ronda = ronda;
        this.#fase = 'esperando';
        this.#senueloBoton = null;
        this.#botones.reiniciar();
        this.#robot.vigilar();
        this.#pintarRonda('RONDA ' + ronda.numero_ronda + '/' + this.#rondasTotales);
        this.#mensaje('VIGILANDO...', '');

        // Durante el retardo los botones están verdes: pulsar uno es falso inicio.
        this.#temporizador = setTimeout(() => this.#encenderRojo(), ronda.retardo_ms);

        // La tensión dura EXACTAMENTE lo mismo que el retardo real de esta ronda: se acelera del todo justo cuando el botón se pone rojo.
        this.#sonido.tensionIniciar(ronda.retardo_ms);

        // Señuelo: null cuando esta ronda no tiene uno (la mayoría). El servidor ya garantiza que instante + duración cabe dentro del retardo.
        if (ronda.senuelo_boton !== null) {
            this.#temporizadorSenueloMostrar = setTimeout(
                () => this.#mostrarSenuelo(ronda.senuelo_boton),
                ronda.senuelo_instante_ms
            );
        }
    }

    #mostrarSenuelo(numero) {
        // Por si llega tarde (la ronda ya se resolvió, o cambiaste de pestaña): acá ya no corresponde mostrar nada.
        if (this.#fase !== 'esperando') { return; }

        this.#senueloBoton = numero;
        this.#botones.encenderSenuelo(numero);
        this.#temporizadorSenueloOcultar = setTimeout(
            () => this.#ocultarSenuelo(),
            this.#ronda.senuelo_duracion_ms
        );
    }

    #ocultarSenuelo() {
        if (this.#senueloBoton === null) { return; }
        this.#botones.apagarSenuelo(this.#senueloBoton);
        this.#senueloBoton = null;
    }

    // Punto único para cortar todo lo pendiente de la ronda: 
    // los tres setTimeout Y el sonido de tensión, que no es un timer pero también queda "colgado" si no se corta acá (cancelación, error de red, etc.).
    #limpiarTemporizadores() {
        clearTimeout(this.#temporizador);
        clearTimeout(this.#temporizadorSenueloMostrar);
        clearTimeout(this.#temporizadorSenueloOcultar);
        this.#sonido.tensionDetener();
    }

    #encenderRojo() {
        this.#ocultarSenuelo();   // por las dudas: a esta altura ya debería estar apagado
        this.#sonido.tensionDetener();
        this.#sonido.alarma();

        this.#fase = 'rojo';
        this.#botones.encender(this.#ronda.boton_activado);
        this.#robot.alertar();
        this.#mensaje('¡BRECHA! ¡CONTENELA!', 'alerta');

        // performance.now() es un reloj de alta precisión que no salta si el usuario cambia la hora del sistema (Date.now() sí).
        // La marca se tomadespués de cambiar el DOM. El navegador pinta el rojo un cuadro más tarde (~16 ms), un sesgo igual para todos que no afecta las comparaciones.
        this.#inicioRojo = performance.now();

        this.#temporizador = setTimeout(() => this.#resolver('timeout', null), this.#ronda.ventana_ms);
    }

    #alPulsar(numeroBoton) {
        const ahora = performance.now();   // primera línea: lo antes posible

        if (this.#fase === 'esperando') {
            if (numeroBoton === this.#senueloBoton) {
                this.#resolver('senuelo', null);
            } else {
                this.#resolver('falso_inicio', null);
            }
        } else if (this.#fase === 'rojo') {
            if (numeroBoton === this.#ronda.boton_activado) {
                this.#resolver('acierto', Math.round(ahora - this.#inicioRojo));
            } else {
                // Pulsó un botón verde mientras había otro en rojo. La base solo tiene cuatro tipos de resultado, así que también es falso inicio.
                this.#resolver('falso_inicio', null);
            }
        }
        // En cualquier otra fase (resolviendo, reposo) el toque se ignora.
    }

    async #resolver(tipo, tiempoMs) {
        this.#limpiarTemporizadores();
        this.#fase = 'resolviendo';   // bloquea toques duplicados desde este instante

        const botonActivo = this.#ronda.boton_activado;

        try {
            const r = await this.#api.registrarRonda(this.#ronda.numero_ronda, tipo, tiempoMs);
            if (!this.#activa) { return; }   // se canceló mientras esperábamos al servidor

            this.#anotar(r);
            this.#pintarNivel(r.nivel_descontrol);
            this.#mostrarResultadoRonda(r, botonActivo);

            // Ronda imposible: el servidor anuló la partida y no cuenta para nada.
            if (r.estado_partida === 'anulada') {
                this.#mostrarFin(
                    'cancelada',
                    'PARTIDA ANULADA',
                    'Clic demasiado rápido: parece anticipado (o la conexión se demoró de forma extraña).\nEsta partida no cuenta.'
                );
                return;
            }

            if (r.estado_partida === 'en_curso') {
                await pausa(PAUSA_ENTRE_RONDAS_MS);
                if (this.#activa) { this.#jugarRonda(r.siguiente_ronda); }
                return;
            }

            // La partida terminó: momento compartible (ojo del robot + sacudida).
            this.#activa = false;
            this.#el.app.setAttribute('data-resultado', r.estado_partida);

            if (r.estado_partida === 'contenida') {
                this.#robot.contener();
                this.#mensaje('IA CONTENIDA', 'ok');
                this.#sonido.victoria();
            } else {
                this.#robot.escapar();
                this.#mensaje('SANDBOX ESCAPED', 'alerta');
                this.#sonido.derrota();
            }

            await pausa(PAUSA_FINAL_MS);
            this.#mostrarFin(
                r.estado_partida,
                r.estado_partida === 'contenida' ? 'IA CONTENIDA' : 'SANDBOX ESCAPED',
                this.#textoResumen(r.percentil),
                r.percentil
            );
        } catch (error) {
            if (this.#activa) {
                if (error.reintentable) {
                    // Las rondas ya jugadas quedaron guardadas en el servidor: solo se perdió el resto de la partida.
                    this.#mostrarFin('cancelada', 'SE CORTÓ LA SEÑAL',
                        'El servidor se saturó por un momento y la partida no pudo seguir. Tus rondas ya jugadas quedaron registradas. Probá de nuevo en unos segundos.');
                } else {
                    this.#mostrarFin('cancelada', 'ERROR DE CONEXIÓN', error.message);
                }
            }
        }
    }

    // Estadísticas de la partida (solo para mostrar)

    #anotar(r) {
        if (r.tipo_resultado === 'acierto') {
            // Solo entran los aciertos que el servidor aceptó como válidos: así el resumen usa las MISMAS reacciones que las estadísticas del servidor
            // (aciertos no sospechosos y con tiempo) y la mediana coincide con la del percentil.
            if (!r.sospechosa) {
                this.#resumen.tiempos.push(r.tiempo_reaccion_ms);
            }
        } else if (r.tipo_resultado === 'timeout') {
            this.#resumen.timeouts++;
        } else if (r.tipo_resultado === 'senuelo') {
            this.#resumen.senuelos++;
        } else {
            this.#resumen.falsosInicios++;
        }
    }

    #textoResumen(percentil) {
        const tiempos = this.#resumen.tiempos.slice().sort(function (a, b) { return a - b; });
        const lineas = [];

        if (tiempos.length > 0) {
            const suma = tiempos.reduce(function (a, b) { return a + b; }, 0);
            // Misma fórmula que compararPartida() en RepositorioJuego.php (el valor del medio, o el promedio de los dos del medio). 
            // Si se cambia una, hay que cambiar la otra.
            const medio = Math.floor(tiempos.length / 2);
            const mediana = (tiempos.length % 2 === 1)
                ? tiempos[medio]
                : (tiempos[medio - 1] + tiempos[medio]) / 2;

            lineas.push('Reacciones válidas: ' + tiempos.length);
            lineas.push('Mejor tiempo: ' + tiempos[0] + ' ms');
            lineas.push('Media: ' + Math.round(suma / tiempos.length) + ' ms');
            lineas.push('Mediana: ' + Math.round(mediana) + ' ms');
        } else {
            lineas.push('Sin reacciones válidas en esta partida.');
        }
        lineas.push(
            'Timeouts: ' + this.#resumen.timeouts +
            ' · Falsos inicios: ' + this.#resumen.falsosInicios +
            ' · Caíste en el señuelo: ' + this.#resumen.senuelos
        );

        // Cuando no hay porcentaje que mostrar, se explica el motivo en lugar de callar.
        if (percentil && percentil.estado === 'pocas_reacciones') {
            lineas.push('Con tan pocas reacciones válidas no se puede calcular tu posición.');
        } else if (percentil && percentil.estado === 'pocas_partidas') {
            lineas.push('Todavía hay pocas partidas de otros operadores para compararte.');
        }

        return lineas.join('\n');
    }

    // Cambios en la pantalla

    #mostrarResultadoRonda(r, botonActivo) {
        if (r.tipo_resultado === 'acierto') {
            this.#botones.confirmar(botonActivo);
            this.#mensaje('BRECHA CONTENIDA · ' + r.tiempo_reaccion_ms + ' ms', 'ok');
            this.#sonido.acierto();
        } else if (r.tipo_resultado === 'timeout') {
            this.#mensaje('DEMASIADO LENTO · LA IA GANA TERRENO', 'mal');
            this.#sonido.error();
        } else if (r.tipo_resultado === 'senuelo') {
            this.#mensaje('ERA UNA TRAMPA · ESE NO ERA EL BOTÓN', 'mal');
            this.#sonido.error();
        } else {
            this.#mensaje('FALSO INICIO · ESE BOTÓN NO ERA', 'mal');
            this.#sonido.error();
        }
    }

    #cancelar(motivo) {
        this.#mostrarFin('cancelada', 'PARTIDA CANCELADA', motivo);
    }

    #mostrarFin(resultado, titulo, detalle, percentil) {
        this.#limpiarTemporizadores();
        this.#activa = false;
        this.#ocupado = false;
        this.#fase = 'reposo';
        this.#el.app.setAttribute('data-resultado', resultado);
        // textContent (nunca innerHTML): el texto se muestra tal cual, sin interpretar HTML, así que nada de lo que escriba un usuario puede inyectar código.
        this.#el.finTitulo.textContent = titulo;
        this.#el.finDetalle.textContent = detalle;
        this.#pintarPercentil(percentil, resultado);
        this.#mostrar('fin');
    }

    // [POO · ENCAPSULAMIENTO]
    // Método privado: solo el Juego decide cuándo se ve el porcentaje.
    // Cuando la partida se cancela o falla la conexión no llega ningún dato (undefined), y el bloque se oculta en lugar de mostrar un valor viejo.
    //
    // El percentil mide la VELOCIDAD (tu mediana contra la de otros), no si ganaste la partida: 
    // se puede reaccionar rápido y aun así perder por timeouts, falsos inicios o el señuelo. 
    // Con "descontrolada" la frase lo aclara, para que el número no parezca contradecir el título de arriba.
    #pintarPercentil(percentil, resultado) {
        if (!percentil || percentil.estado !== 'ok') {
            this.#el.finPercentil.hidden = true;
            return;
        }

        const aclaracion = (resultado === 'descontrolada')
            ? ', aunque esta partida se te escapó'
            : '';

        this.#el.finPercentilNumero.textContent = percentil.porcentaje_superado + ' %';
        this.#el.finPercentilTexto.textContent =
            'de las partidas de otros operadores fueron más lentas que la tuya' + aclaracion + '\n' +
            '(tu mediana: ' + Math.round(percentil.mediana_ms) + ' ms · ' +
            percentil.comparadas + ' partidas comparadas)';
        this.#el.finPercentil.hidden = false;
    }

    #mostrar(estado) {
        this.#el.app.setAttribute('data-estado', estado);
    }

    #mensaje(texto, tono) {
        this.#el.mensaje.textContent = texto;
        this.#el.mensaje.setAttribute('data-tono', tono);
    }

    #pintarRonda(texto) {
        this.#el.rondaTexto.textContent = texto;
    }

    #pintarNivel(nivel) {
        this.#el.nivelTexto.textContent = 'DESCONTROL ' + nivel + '%';
        this.#el.barraCubierta.style.width = (100 - nivel) + '%';
        this.#el.barra.setAttribute('aria-valuenow', String(nivel));
        this.#robot.ponerDescontrol(nivel);
    }
}

/* --------
   Arranque
   -------- */
const elementos = {
    app: document.getElementById('app'),
    robot: document.getElementById('robot'),
    mensaje: document.getElementById('mensaje'),
    rondaTexto: document.getElementById('ronda-texto'),
    nivelTexto: document.getElementById('nivel-texto'),
    barra: document.getElementById('barra'),
    barraCubierta: document.getElementById('barra-cubierta'),
    botones: document.getElementById('botones'),
    alias: document.getElementById('alias'),
    finTitulo: document.getElementById('fin-titulo'),
    finDetalle: document.getElementById('fin-detalle'),
    finPercentil: document.getElementById('fin-percentil'),
    finPercentilNumero: document.getElementById('fin-percentil-numero'),
    finPercentilTexto: document.getElementById('fin-percentil-texto')
};

const sonido = new MotorSonido();
const juego = new Juego(elementos, new ClienteApi(), sonido);

elementos.alias.value = almacen.leer(CLAVE_ALIAS) || '';

// Ambiente de fondo en la pantalla de inicio
function activarAudioPrimeraVez() {
    console.log('[sonido] primer gesto detectado');   // sacar cuando esté confirmado
    document.removeEventListener('pointerdown', activarAudioPrimeraVez);
    document.removeEventListener('keydown', activarAudioPrimeraVez);
    sonido.iniciar().then(function () {
        if (elementos.app.dataset.estado === 'inicio') {
            sonido.ambienteIniciar();
        } else {
            console.log('[sonido] no se prendió el ambiente: data-estado ya no es "inicio"');
        }
    });
}
document.addEventListener('pointerdown', activarAudioPrimeraVez, { once: true });
document.addEventListener('keydown', activarAudioPrimeraVez, { once: true });

// Precalentamiento: el navegador decodifica las imágenes de los botones ahora, no en el momento en que uno se pone rojo (ese instante se está midiendo).
document.querySelectorAll('.boton-imagen').forEach(function (imagen) {
    if (imagen.decode) {
        imagen.decode().catch(function () { /* si falla, se decodifica al mostrarla */ });
    }
});

function aliasIngresado() {
    return elementos.alias.value.trim() || 'Anónimo';
}

document.getElementById('form-inicio').addEventListener('submit', function (evento) {
    evento.preventDefault();
    juego.iniciar(aliasIngresado());
});

document.getElementById('btn-reintentar').addEventListener('click', function () {
    juego.iniciar(aliasIngresado());
});

// Modal de reglas ("Aprender")
const modalReglas = document.getElementById('modal-reglas');

document.getElementById('btn-aprender').addEventListener('click', function () {
    modalReglas.showModal();
});

document.getElementById('btn-cerrar-reglas').addEventListener('click', function () {
    modalReglas.close();
});

// Cerrar tocando el fondo oscuro (el ::backdrop no dispara click del dialog, así que se mide si el toque cayó fuera del rectángulo del modal).
modalReglas.addEventListener('click', function (evento) {
    const caja = modalReglas.getBoundingClientRect();
    const dentro = evento.clientX >= caja.left && evento.clientX <= caja.right &&
                   evento.clientY >= caja.top && evento.clientY <= caja.bottom;
    if (!dentro) { modalReglas.close(); }
});