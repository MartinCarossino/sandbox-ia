'use strict';

/* ===============================================
   Acá solo se hace tres cosas:
     1. preguntarle a la API cada pocos segundos,
     2. repartir la respuesta entre las secciones,
     3. dibujar lo que cada sección recibe.
   =============================================== */

const URL_API = 'api/estadisticas.php';
const INTERVALO_MS = 10000;          // cada cuánto se vuelve a preguntar
const ESPERA_MAXIMA_MS = 8000;       // si la API no responde en este tiempo, se da por caída
const SVG_NS = 'http://www.w3.org/2000/svg';

const ETIQUETA_DISPOSITIVO = { pc: 'PC', movil: 'CELULAR' };
const ETIQUETA_ROBOT = { aleatorio: 'ALEATORIO', adaptativo: 'ADAPTATIVO' };
const ETIQUETA_CORTA_DISPOSITIVO = { pc: 'PC', movil: 'CEL' };
const ETIQUETA_CORTA_ROBOT = { aleatorio: 'ALEAT.', adaptativo: 'ADAPT.' };
const ETIQUETA_ESTADO = { contenida: 'CONTENIDA', descontrolada: 'ESCAPÓ', en_curso: 'EN JUEGO', abandonada: 'ABANDONADA' };

const formatoEntero = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 });
const formatoUnDecimal = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const prefiereMenosMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* Formatos de texto */

function entero(valor) {
    return formatoEntero.format(Math.round(valor));
}

function enMs(valor) {
    return valor === null || valor === undefined ? '—' : entero(valor) + ' ms';
}

function conSigno(valor) {
    const redondeado = Math.round(valor);
    return (redondeado > 0 ? '+' : (redondeado < 0 ? '−' : '')) + formatoEntero.format(Math.abs(redondeado));
}

function porcentaje(valor) {
    // Espacio duro entre el número y el %: así nunca quedan en renglones distintos.
    return valor === null || valor === undefined ? '—' : formatoUnDecimal.format(valor).replace(',0', '') + '\u00a0%';
}

function plural(cantidad, singular, pluralTexto) {
    return entero(cantidad) + ' ' + (Math.round(cantidad) === 1 ? singular : pluralTexto);
}

// "45 s", "12 min", "1 h 35 min", "3 días"
function tiempoRelativo(segundos) {
    const s = Math.max(0, Math.floor(segundos));
    if (s < 60) { return s + ' s'; }
    const minutos = Math.floor(s / 60);
    if (minutos < 60) { return minutos + ' min'; }
    const horas = Math.floor(minutos / 60);
    if (horas < 24) { return horas + ' h ' + (minutos % 60) + ' min'; }
    const dias = Math.floor(horas / 24);
    return dias + (dias === 1 ? ' día' : ' días');
}

/* Utilidades de DOM */

// Todo texto que llega de la base entra con textContent, nunca con innerHTML: un alias como "<img onerror=...>" se ve como texto y no se ejecuta.
function elemento(nombre, clase, texto) {
    const e = document.createElement(nombre);
    if (clase) { e.className = clase; }
    if (texto !== undefined) { e.textContent = texto; }
    return e;
}

function svgEl(nombre, atributos, hijos) {
    const e = document.createElementNS(SVG_NS, nombre);
    Object.keys(atributos || {}).forEach(function (k) { e.setAttribute(k, atributos[k]); });
    (hijos || []).forEach(function (h) { e.appendChild(typeof h === 'string' ? document.createTextNode(h) : h); });
    return e;
}

function texto(x, y, contenido, atributos) {
    return svgEl('text', Object.assign({ x: x, y: y }, atributos), [String(contenido)]);
}

function linea(x1, y1, x2, y2, clase) {
    return svgEl('line', { x1: x1, y1: y1, x2: x2, y2: y2, class: clase });
}

function mensajeVacio(mensaje) {
    return elemento('p', 'vacio', mensaje);
}

// Marca un número o dato para resaltarlo dentro de un titular.
function dato(contenido) {
    return { dato: String(contenido) };
}

// Arma un titular con partes de texto y datos resaltados, sin innerHTML.
function frase() {
    const fragmento = document.createDocumentFragment();
    Array.prototype.forEach.call(arguments, function (parte) {
        if (typeof parte === 'string') {
            fragmento.appendChild(document.createTextNode(parte));
        } else {
            fragmento.appendChild(elemento('b', 'dato', parte.dato));
        }
    });
    return fragmento;
}

// Cuenta desde el valor anterior hasta el nuevo. Si no hay valor anterior (la primera vez) o la persona pidió menos movimiento, escribe el número directo.
const animaciones = new WeakMap();

function animarNumero(nodo, destino, formatear) {
    if (destino === null || destino === undefined) {
        nodo.textContent = '—';
        delete nodo.dataset.valor;
        return;
    }
    const anterior = nodo.dataset.valor === undefined ? null : Number(nodo.dataset.valor);
    nodo.dataset.valor = String(destino);
    cancelAnimationFrame(animaciones.get(nodo));

    if (anterior === null || anterior === destino || prefiereMenosMovimiento) {
        nodo.textContent = formatear(destino);
        return;
    }
    const inicio = performance.now();
    const duracion = 700;
    const paso = function (ahora) {
        const avance = Math.min(1, (ahora - inicio) / duracion);
        const suave = 1 - Math.pow(1 - avance, 3);
        nodo.textContent = formatear(anterior + (destino - anterior) * suave);
        if (avance < 1) { animaciones.set(nodo, requestAnimationFrame(paso)); }
    };
    animaciones.set(nodo, requestAnimationFrame(paso));
}

/* ===================================================
   Gráficos SVG: una clase base y una hija por gráfico
   =================================================== */

// [POO · ABSTRACCIÓN]
// Grafico dice QUÉ puede hacer cualquier gráfico del panel (recibir datos y
// dibujarse) sin decir CÓMO. Las secciones solo conocen actualizar().
class Grafico {
    // [POO · ENCAPSULAMIENTO]
    // El contenedor y los últimos datos son PRIVADOS: nadie de afuera puede
    // dejarlos a medias. Lo único visible es actualizar().
    #contenedor;
    #ultimosDatos = null;

    constructor(contenedor) {
        this.#contenedor = contenedor;
        if (typeof ResizeObserver !== 'undefined') {
            // Un SVG dibujado a un ancho fijo y estirado por CSS agranda también el texto.
            // Por eso se redibuja al ancho real cada vez que la ventana cambia de tamaño.
            let anchoAnterior = 0;
            new ResizeObserver(() => {
                const ancho = Math.round(this.#contenedor.clientWidth);
                if (ancho !== anchoAnterior && this.#ultimosDatos) {
                    anchoAnterior = ancho;
                    this.dibujar(this.#ultimosDatos);
                }
            }).observe(contenedor);
        }
    }

    get ancho() {
        return Math.max(260, Math.floor(this.#contenedor.clientWidth) || 320);
    }

    montar(nodo) {
        this.#contenedor.replaceChildren(nodo);
    }

    actualizar(datos) {
        this.#ultimosDatos = datos;
        this.dibujar(datos);
    }

    dibujar() {
        throw new Error('Cada gráfico tiene que implementar dibujar().');
    }
}

/* Regla de percentiles */

// [POO · HERENCIA]
// GraficoRegla hereda de Grafico todo lo común (montar, ancho, redibujar al
// cambiar el tamaño) y solo escribe lo suyo: cómo se dibuja una distribución
// resumida en una regla con el mejor y el peor tiempo, el rango de 8 de cada 10
// (percentil 10 a 90) y la mediana.
class GraficoRegla extends Grafico {

    // [POO · POLIMORFISMO]
    // El mismo mensaje dibujar() hace algo distinto en cada gráfico hijo.
    dibujar(datos) {
        const filas = datos.filas;
        if (filas.length === 0) {
            this.montar(mensajeVacio('Todavía no hay reacciones para dibujar.'));
            return;
        }

        const ancho = this.ancho;
        const altoFila = 78;
        const m = { izquierda: 6, derecha: 6, arriba: 4, abajo: 26 };
        const W = ancho - m.izquierda - m.derecha;
        const alto = m.arriba + filas.length * altoFila + m.abajo;

        // Un eje compartido por todas las filas: así la comparación es justa.
        const minimo = Math.floor(Math.min.apply(null, filas.map(function (f) { return f.mejor_ms; })) / 50) * 50;
        let maximo = Math.ceil(Math.max.apply(null, filas.map(function (f) { return f.peor_ms; })) / 50) * 50;
        if (maximo - minimo < 200) { maximo = minimo + 200; }
        const xDe = function (ms) { return m.izquierda + (ms - minimo) / (maximo - minimo) * W; };
        const yBase = m.arriba + filas.length * altoFila;

        const svg = svgEl('svg', {
            width: ancho, height: alto, viewBox: '0 0 ' + ancho + ' ' + alto, role: 'img',
            'aria-label': 'Regla de tiempos de reacción: ' + filas.map(function (f) {
                return f.etiqueta + ', mediana ' + enMs(f.mediana_ms) + ', ocho de cada diez entre ' + enMs(f.p10_ms) + ' y ' + enMs(f.p90_ms);
            }).join('; ') + '.'
        });

        // Eje con marcas
        const paso = ancho < 460 ? 100 : 50;
        for (let ms = Math.ceil(minimo / paso) * paso; ms <= maximo; ms += paso) {
            svg.appendChild(linea(xDe(ms), m.arriba, xDe(ms), yBase, 'rejilla-svg'));
            svg.appendChild(texto(xDe(ms), yBase + 18, ms, { 'text-anchor': 'middle', class: 'marca' }));
        }
        svg.appendChild(linea(m.izquierda, yBase, ancho - m.derecha, yBase, 'eje'));

        filas.forEach(function (f, i) {
            const y0 = m.arriba + i * altoFila;
            const yc = y0 + 38;

            svg.appendChild(texto(m.izquierda, y0 + 14, f.etiqueta, { class: 'fila-nombre' }));
            svg.appendChild(texto(ancho - m.derecha, y0 + 14, plural(f.n, 'reacción', 'reacciones'), { 'text-anchor': 'end', class: 'marca' }));

            // Del mejor al peor tiempo, con un tope en cada punta
            svg.appendChild(linea(xDe(f.mejor_ms), yc, xDe(f.peor_ms), yc, 'extremos'));
            svg.appendChild(linea(xDe(f.mejor_ms), yc - 6, xDe(f.mejor_ms), yc + 6, 'extremos'));
            svg.appendChild(linea(xDe(f.peor_ms), yc - 6, xDe(f.peor_ms), yc + 6, 'extremos'));

            // De cada 10 reacciones, 8 caen dentro de esta banda (del percentil 10 al 90)
            svg.appendChild(svgEl('rect', {
                x: xDe(f.p10_ms), y: yc - 9, width: Math.max(2, xDe(f.p90_ms) - xDe(f.p10_ms)), height: 18, class: 'banda'
            }, [svgEl('title', {}, [f.etiqueta + ': 8 de cada 10 entre ' + enMs(f.p10_ms) + ' y ' + enMs(f.p90_ms) +
                                    ' · mejor ' + enMs(f.mejor_ms) + ' · peor ' + enMs(f.peor_ms)])]));

            // La mediana: la mitad reacciona más rápido, la mitad más lento
            const xMediana = xDe(f.mediana_ms);
            svg.appendChild(linea(xMediana, yc - 15, xMediana, yc + 15, 'mediana'));
            const ancla = xMediana > ancho - 70 ? 'end' : (xMediana < 70 ? 'start' : 'middle');
            svg.appendChild(texto(xMediana, yc + 34, enMs(f.mediana_ms), { 'text-anchor': ancla, class: 'valor-mediana' }));
        });

        this.montar(svg);
    }
}

/* Evolución por ronda */

class GraficoEvolucion extends Grafico {

    // [POO · POLIMORFISMO]
    dibujar(datos) {
        const filas = datos.rondas;
        const conMedia = filas.filter(function (f) { return f.media_ms !== null; });
        if (conMedia.length < 2) {
            this.montar(mensajeVacio('Todavía no hay rondas suficientes para ver la evolución.'));
            return;
        }

        const ancho = this.ancho;
        const m = { izquierda: 40, derecha: 8 };
        const A = { arriba: 10, alto: 190 };       // arriba: milisegundos
        const B = { arriba: 244, alto: 44 };       // abajo: cuántas rondas se jugaron
        const alto = B.arriba + B.alto + 26;
        const W = ancho - m.izquierda - m.derecha;
        const numeros = filas.map(function (f) { return f.numero_ronda; });
        const primera = Math.min.apply(null, numeros);
        const ultima = Math.max.apply(null, numeros);
        const cantidad = ultima - primera + 1;
        const xDe = function (ronda) { return m.izquierda + (ronda - primera + 0.5) / cantidad * W; };

        const medias = conMedia.map(function (f) { return f.media_ms; });
        const yMin = Math.floor((Math.min.apply(null, medias) - 20) / 50) * 50;
        const yMax = Math.ceil((Math.max.apply(null, medias) + 20) / 50) * 50;
        const yA = function (ms) { return A.arriba + (1 - (ms - yMin) / (yMax - yMin)) * A.alto; };

        const svg = svgEl('svg', {
            width: ancho, height: alto, viewBox: '0 0 ' + ancho + ' ' + alto, role: 'img',
            'aria-label': 'Tiempo medio de reacción en cada ronda, por robot, y cuántas rondas se jugaron.'
        });

        // Rejilla horizontal del panel de milisegundos
        const pasoY = (yMax - yMin) > 250 ? 100 : 50;
        for (let ms = Math.ceil(yMin / pasoY) * pasoY; ms <= yMax; ms += pasoY) {
            svg.appendChild(linea(m.izquierda, yA(ms), ancho - m.derecha, yA(ms), 'rejilla-svg'));
            svg.appendChild(texto(m.izquierda - 8, yA(ms) + 4, ms, { 'text-anchor': 'end', class: 'marca' }));
        }

        // Una línea por robot: la media de las reacciones válidas de cada ronda
        ['aleatorio', 'adaptativo'].forEach(function (robot) {
            const propias = filas.filter(function (f) { return f.tipo_robot === robot; });
            const trazo = propias.filter(function (f) { return f.media_ms !== null; }).map(function (f, i) {
                return (i === 0 ? 'M' : 'L') + xDe(f.numero_ronda).toFixed(1) + ' ' + yA(f.media_ms).toFixed(1);
            }).join(' ');
            if (trazo !== '') { svg.appendChild(svgEl('path', { d: trazo, class: 'linea linea-' + robot })); }
            propias.forEach(function (f) {
                if (f.media_ms === null) { return; }
                svg.appendChild(svgEl('circle', { cx: xDe(f.numero_ronda), cy: yA(f.media_ms), r: 3.5, class: 'punto-' + robot }, [
                    svgEl('title', {}, ['Ronda ' + f.numero_ronda + ' · ' + ETIQUETA_ROBOT[robot] + ': media ' + enMs(f.media_ms) +
                                        ' · ' + plural(f.validas, 'reacción válida', 'reacciones válidas') +
                                        ' · ' + porcentaje(f.pct_timeout) + ' de timeouts'])
                ]));
            });
        });

        // Panel de abajo: cuántas rondas se jugaron. Cae porque las partidas se cortan antes de llegar al final.
        const mayor = Math.max.apply(null, filas.map(function (f) { return f.rondas; }));
        svg.appendChild(texto(m.izquierda, B.arriba - 14, 'RONDAS JUGADAS', { class: 'marca' }));
        const anchoBarra = Math.min(14, W / cantidad * 0.34);
        filas.forEach(function (f) {
            const h = Math.max(1.5, f.rondas / mayor * B.alto);
            const desplazamiento = f.tipo_robot === 'aleatorio' ? -anchoBarra - 1 : 1;
            svg.appendChild(svgEl('rect', {
                x: xDe(f.numero_ronda) + desplazamiento, y: B.arriba + B.alto - h, width: anchoBarra, height: h,
                class: 'barra-' + f.tipo_robot
            }, [svgEl('title', {}, ['Ronda ' + f.numero_ronda + ' · ' + ETIQUETA_ROBOT[f.tipo_robot] + ': ' + plural(f.rondas, 'ronda jugada', 'rondas jugadas')])]));
        });
        svg.appendChild(linea(m.izquierda, B.arriba + B.alto, ancho - m.derecha, B.arriba + B.alto, 'eje'));

        // Número de ronda debajo de todo
        const cadaCuantas = ancho < 460 ? 2 : 1;
        for (let r = primera; r <= ultima; r++) {
            if ((r - primera) % cadaCuantas === 0 || r === ultima) {
                svg.appendChild(texto(xDe(r), B.arriba + B.alto + 18, r, { 'text-anchor': 'middle', class: 'marca' }));
            }
        }
        svg.appendChild(texto(m.izquierda - 8, B.arriba + B.alto + 18, 'RONDA', { 'text-anchor': 'end', class: 'marca' }));

        this.montar(svg);
    }
}

/* =============================================================
   Secciones: una clase base y una hija por sección de la página
   ============================================================= */

// [POO · ABSTRACCIÓN]
// Seccion define el CONTRATO de cualquier sección: recibe la respuesta de la API (actualizar), la pinta (pintar) y opcionalmente refresca sus relojes (tic).
// El panel solo usa actualizar() y tic(), sin saber qué dibuja cada una: pintar() lo llama la propia sección.
class Seccion {
    // [POO · ENCAPSULAMIENTO]
    // La raíz y el titular son PRIVADOS. Las hijas piden lo que necesitan con buscar() y solo pueden cambiar el titular a través de decir().
    #raiz;
    #titular;

    constructor(idRaiz) {
        this.#raiz = document.getElementById(idRaiz);
        this.#titular = this.#raiz.querySelector('.titular');
    }

    buscar(selector) {
        return this.#raiz.querySelector(selector);
    }

    buscarTodos(selector) {
        return this.#raiz.querySelectorAll(selector);
    }

    // Método plantilla: el orden siempre es el mismo (pintar, luego decir el titular), y cada hija solo escribe su propio pintar().
    actualizar(datos) {
        const resultado = this.pintar(datos);
        if (this.#titular && resultado) {
            this.decir(resultado.frase, resultado.veredicto);
        }
    }

    // veredicto: 'senal', 'plano' o 'insuficiente' (lo decide la base); sin veredicto, el titular va sin etiqueta.
    decir(contenido, veredicto) {
        this.#titular.replaceChildren(contenido);
        if (veredicto) {
            this.#titular.setAttribute('data-veredicto', veredicto);
        } else {
            this.#titular.removeAttribute('data-veredicto');
        }
    }

    pintar() {
        throw new Error('Cada sección tiene que implementar pintar().');
    }

    // Se llama cada segundo con los segundos pasados desde que llegaron los datos.
    tic() {}
}

/* Cabecera: cifras clave, descontrol y ojos del robot */

// [POO · HERENCIA]
class Cabecera extends Seccion {
    #segundosDesdeUltima = null;

    // [POO · POLIMORFISMO]
    pintar(datos) {
        const p = datos.pulso;
        const kpi = (nombre) => this.buscar('[data-kpi="' + nombre + '"]');

        animarNumero(kpi('partidas'), p ? p.partidas_terminadas : null, entero);
        animarNumero(kpi('operadores'), p ? p.jugadores : null, entero);
        animarNumero(kpi('reacciones'), p ? p.reacciones : null, entero);
        animarNumero(kpi('mejor'), p ? p.mejor_ms : null, enMs);
        animarNumero(kpi('mediana'), p ? p.mediana_ms : null, enMs);

        const fuga = p ? p.pct_fuga : null;
        animarNumero(kpi('fuga'), fuga, porcentaje);
        kpi('fuga-cubierta').style.width = (fuga === null ? 100 : 100 - fuga) + '%';
        this.buscar('.robot').style.setProperty('--descontrol', String(fuga === null ? 0 : Math.min(1, fuga / 100)));

        this.#segundosDesdeUltima = p ? p.segundos_desde_ultima : null;
        this.tic(0);
        return null;
    }

    tic(segundos) {
        const nodo = this.buscar('[data-kpi="ultima"]');
        nodo.textContent = this.#segundosDesdeUltima === null ? '—' : tiempoRelativo(this.#segundosDesdeUltima + segundos);
    }
}

/* 01 · Ahora: las últimas partidas */

class SeccionAhora extends Seccion {
    #lista;
    #estadosVistos = null;      // id de partida -> último estado que se vio (null hasta la primera respuesta)
    #relojes = [];              // para actualizar el "hace X" cada segundo

    constructor(idRaiz) {
        super(idRaiz);
        this.#lista = this.buscar('.feed');
    }

    // [POO · POLIMORFISMO]
    pintar(datos) {
        const partidas = datos.ultimas;
        const primeraVez = this.#estadosVistos === null;
        if (primeraVez) { this.#estadosVistos = new Map(); }

        this.#lista.replaceChildren();
        this.#relojes = [];
        partidas.forEach((p) => {
            // Se destaca lo que apareció o cambió de estado desde la respuesta anterior (no en la primera carga).
            const cambio = !primeraVez && this.#estadosVistos.get(p.id_partida) !== p.estado;
            this.#estadosVistos.set(p.id_partida, p.estado);
            this.#lista.appendChild(this.#crearFila(p, cambio));
        });
        this.tic(0);

        const enJuego = partidas.filter(function (p) { return p.estado === 'en_curso'; }).length;
        if (partidas.length === 0) {
            return { frase: frase('Todavía no se jugó ninguna partida.') };
        }
        if (enJuego > 0) {
            return { frase: frase(dato(plural(enJuego, 'partida', 'partidas')), ' en juego ahora mismo.') };
        }
        return { frase: frase('Nadie está jugando en este momento.') };
    }

    #crearFila(p, resaltar) {
        const fila = elemento('li', 'partida estado-' + p.estado + (resaltar ? ' nueva' : ''));
        fila.appendChild(elemento('span', 'partida-alias', p.alias));
        fila.appendChild(elemento('span', 'partida-estado', ETIQUETA_ESTADO[p.estado] || p.estado));
        fila.appendChild(elemento('span', 'partida-detalle',
            (ETIQUETA_CORTA_DISPOSITIVO[p.dispositivo] || p.dispositivo) + ' · ' + (ETIQUETA_CORTA_ROBOT[p.tipo_robot] || p.tipo_robot)));

        const cola = elemento('span', 'partida-cola');
        cola.appendChild(elemento('span', 'partida-mejor', p.mejor_ms === null ? '—' : enMs(p.mejor_ms)));
        const reloj = elemento('span', 'partida-tiempo');
        cola.appendChild(reloj);
        fila.appendChild(cola);

        this.#relojes.push({ nodo: reloj, base: p.segundos_atras });
        return fila;
    }

    tic(segundos) {
        this.#relojes.forEach(function (r) { r.nodo.textContent = 'hace ' + tiempoRelativo(r.base + segundos); });
    }
}

/* 02 · El podio: la mejor partida de cada operador */

class SeccionPodio extends Seccion {
    #lista;
    #nota;
    #vistos = null;     // "alias|mediana" de cada puesto en la respuesta anterior (null hasta la primera)

    constructor(idRaiz) {
        super(idRaiz);
        this.#lista = this.buscar('.podio');
        this.#nota = this.buscar('.nota');
    }

    // [POO · POLIMORFISMO]
    pintar(datos) {
        const puestos = datos.podio || [];
        const minimo = datos.podio_minimo;
        this.#nota.textContent = 'La mejor partida de cada operador, según su mediana de reacción. '
            + 'Cuentan las partidas terminadas con al menos ' + minimo + ' reacciones válidas.';

        // Se destaca quien entró al podio o mejoró su marca desde la respuesta anterior (no en la primera carga).
        const primeraVez = this.#vistos === null;
        const anteriores = this.#vistos || new Set();
        this.#vistos = new Set();

        this.#lista.replaceChildren();
        puestos.forEach((p) => {
            const clave = p.alias + '|' + p.mediana_ms;
            this.#vistos.add(clave);
            this.#lista.appendChild(this.#crearFila(p, !primeraVez && !anteriores.has(clave)));
        });

        if (puestos.length === 0) {
            return { frase: frase('Todavía nadie subió al podio: hace falta terminar una partida con al menos ', dato(minimo + ' reacciones válidas'), '.') };
        }
        return { frase: frase(dato(puestos[0].alias), ' lidera con una mediana de ', dato(enMs(puestos[0].mediana_ms)), ' en su mejor partida.') };
    }

    #crearFila(p, resaltar) {
        const fila = elemento('li', 'puesto puesto-' + p.puesto + (resaltar ? ' nueva' : ''));
        fila.appendChild(elemento('span', 'puesto-numero', p.puesto + '°'));
        fila.appendChild(elemento('span', 'puesto-alias', p.alias));
        fila.appendChild(elemento('span', 'puesto-detalle',
            (ETIQUETA_CORTA_DISPOSITIVO[p.dispositivo] || p.dispositivo) + ' · ' + plural(p.reacciones, 'reacción', 'reacciones')));
        fila.appendChild(elemento('span', 'puesto-valor', enMs(p.mediana_ms)));
        return fila;
    }
}

/* 03 · Distribución de los tiempos */

class SeccionDistribucion extends Seccion {
    #grafico;

    constructor(idRaiz) {
        super(idRaiz);
        this.#grafico = new GraficoRegla(this.buscar('.grafico'));
    }

    // [POO · POLIMORFISMO]
    pintar(datos) {
        const t = datos.percentiles.todos;
        if (!t) {
            this.#grafico.actualizar({ filas: [] });
            return { frase: frase('Todavía no hay reacciones registradas.') };
        }
        this.#grafico.actualizar({ filas: [Object.assign({ etiqueta: 'TODAS LAS REACCIONES' }, t)] });

        if (!t.suficiente) {
            return { veredicto: 'insuficiente', frase: frase('Solo hay ', dato(plural(t.n, 'reacción', 'reacciones')), ': todavía es poco para hablar de cómo se reparten los tiempos.') };
        }
        return { frase: frase('La mitad reacciona en menos de ', dato(enMs(t.mediana_ms)), '. Ocho de cada diez, entre ', dato(entero(t.p10_ms)), ' y ', dato(enMs(t.p90_ms)), '.') };
    }
}

/* 04 · PC contra celular */

class SeccionDispositivos extends Seccion {
    #grafico;

    constructor(idRaiz) {
        super(idRaiz);
        this.#grafico = new GraficoRegla(this.buscar('.grafico'));
    }

    // [POO · POLIMORFISMO]
    pintar(datos) {
        const filas = [];
        if (datos.percentiles.pc) { filas.push(Object.assign({ etiqueta: 'PC' }, datos.percentiles.pc)); }
        if (datos.percentiles.movil) { filas.push(Object.assign({ etiqueta: 'CELULAR' }, datos.percentiles.movil)); }
        this.#grafico.actualizar({ filas: filas });

        const d = datos.dispositivos;
        if (!d) {
            return { frase: frase('Todavía falta que se juegue desde los dos dispositivos.') };
        }
        if (d.hay_diferencia) {
            const rapido = d.diferencia_mediana_ms < 0 ? 'celular' : 'PC';
            const lento = d.diferencia_mediana_ms < 0 ? 'PC' : 'celular';
            return { veredicto: 'senal', frase: frase('En ', rapido, ' se reacciona ', dato(entero(Math.abs(d.diferencia_mediana_ms)) + ' ms'), ' más rápido que en ', lento, ' (mediana).') };
        }
        if (d.suficiente) {
            return { veredicto: 'plano', frase: frase('PC y celular reaccionan parecido: mediana de ', dato(enMs(d.mediana_pc_ms)), ' contra ', dato(enMs(d.mediana_movil_ms)), '.') };
        }
        return {
            veredicto: 'insuficiente',
            frase: frase('Todavía no se puede decir cuál es más rápido: hay ', dato(plural(d.n_pc, 'reacción', 'reacciones')), ' de ',
                         dato(plural(d.jugadores_pc, 'persona', 'personas')), ' en PC y ', dato(plural(d.n_movil, 'reacción', 'reacciones')), ' de ',
                         dato(plural(d.jugadores_movil, 'persona', 'personas')), ' en celular.')
        };
    }
}

/* 05 · El tablero: mapa de calor con los botones del juego */

function filtroDeTono(t) {
    const angulo = t <= 0.5 ? -82 * (t / 0.5) : -82 - 36 * ((t - 0.5) / 0.5);
    return 'hue-rotate(' + angulo.toFixed(0) + 'deg) saturate(1.3)';
}

function opacidadDelRojo(t) {
    const x = Math.min(1, Math.max(0, (t - 0.75) / 0.25));
    return x * x * (3 - 2 * x);
}

class SeccionTablero extends Seccion {
    #celdas = [];

    constructor(idRaiz) {
        super(idRaiz);
        const tablero = this.buscar('.tablero');
        // Las piezas se crean UNA vez; en cada respuesta solo se les cambian estilos y textos.
        for (let numero = 1; numero <= 6; numero++) {
            const celda = elemento('div', 'celda');
            const boton = elemento('span', 'celda-boton');
            const verde = elemento('img', 'celda-verde');
            verde.src = 'https://cdn.jsdelivr.net/gh/MartinCarossino/sandbox-ia@main/img/boton_verde.webp';
            verde.alt = '';
            verde.draggable = false;
            const rojo = elemento('img', 'celda-rojo');
            rojo.src = 'https://cdn.jsdelivr.net/gh/MartinCarossino/sandbox-ia@main/img/boton_rojo.webp';
            rojo.alt = '';
            rojo.draggable = false;
            boton.appendChild(verde);
            boton.appendChild(rojo);

            const valor = elemento('span', 'celda-valor');
            const cantidad = elemento('span', 'celda-cantidad');
            celda.appendChild(elemento('span', 'celda-numero', 'BOTÓN ' + numero));
            celda.appendChild(boton);
            celda.appendChild(valor);
            celda.appendChild(cantidad);
            tablero.appendChild(celda);
            this.#celdas.push({ celda: celda, verde: verde, rojo: rojo, valor: valor, cantidad: cantidad });
        }
    }

    // [POO · POLIMORFISMO]
    pintar(datos) {
        const porBoton = {};
        datos.botones.forEach(function (b) { porBoton[b.boton] = b; });

        let conDatos = 0;
        let destacado = null;
        this.#celdas.forEach((pieza, i) => {
            const b = porBoton[i + 1] || null;
            const coloreada = b !== null && b.suficiente && b.z !== null;
            if (coloreada) { conDatos++; }
            if (b !== null && b.destaca) { destacado = b; }

            // La escala de color va de z = -3 (verde, más rápido) a z = +3 (rojo, más lento).
            const tono = coloreada ? Math.min(1, Math.max(0, 0.5 + b.z / 6)) : null;
            pieza.celda.classList.toggle('apagada', !coloreada);
            pieza.celda.classList.toggle('destaca', b !== null && b.destaca);
            pieza.verde.style.filter = coloreada ? filtroDeTono(tono) : '';
            pieza.rojo.style.opacity = coloreada ? String(opacidadDelRojo(tono)) : '0';
            pieza.valor.textContent = coloreada ? conSigno(b.diferencia_ms) + ' ms' : (b === null ? 'sin datos' : 'pocos datos');
            pieza.cantidad.textContent = plural(b === null ? 0 : b.n, 'reacción', 'reacciones');
            pieza.celda.setAttribute('aria-label', 'Botón ' + (i + 1) + ': ' + pieza.valor.textContent + ', ' + pieza.cantidad.textContent);
        });

        if (destacado !== null) {
            const mas = destacado.diferencia_ms > 0;
            return { veredicto: 'senal', frase: frase('El botón ', dato(destacado.boton), ' es el más ', mas ? 'lento' : 'rápido', ': ', dato(entero(Math.abs(destacado.diferencia_ms)) + ' ms'), mas ? ' por encima del resto.' : ' por debajo del resto.') };
        }
        if (conDatos === 6) {
            return { veredicto: 'plano', frase: frase('Ningún botón se aparta del resto.') };
        }
        return {
            veredicto: 'insuficiente',
            frase: conDatos === 0
                ? frase('Ningún botón tiene todavía reacciones suficientes.')
                : frase('Solo ', dato(conDatos + ' de 6'), ' botones tienen reacciones suficientes.')
        };
    }
}

/* 06 · A lo largo de la partida */

class SeccionRondas extends Seccion {
    #grafico;

    constructor(idRaiz) {
        super(idRaiz);
        this.#grafico = new GraficoEvolucion(this.buscar('.grafico'));
    }

    // [POO · POLIMORFISMO]
    pintar(datos) {
        const filas = datos.rondas;
        this.#grafico.actualizar({ rondas: filas });
        if (filas.length === 0) {
            return { frase: frase('Todavía no hay rondas registradas.') };
        }

        const ultima = Math.max.apply(null, filas.map(function (f) { return f.numero_ronda; }));
        const jugadasEn = function (ronda) {
            return filas.filter(function (f) { return f.numero_ronda === ronda; })
                        .reduce(function (suma, f) { return suma + f.rondas; }, 0);
        };
        const empezaron = jugadasEn(1);
        const llegaron = jugadasEn(ultima);

        if (ultima === 1 || llegaron >= empezaron) {
            return { frase: frase('Todas las partidas llegan hasta la ronda ', dato(ultima), '.') };
        }
        // Las partidas se cortan antes (la IA escapa o la persona se va), así que las rondas altas las juegan cada vez menos partidas: se muestra ese número junto a la curva.
        return { frase: frase('De ', dato(plural(empezaron, 'partida', 'partidas')), ' que empiezan, ', dato(llegaron), ' llegan a la ronda ', dato(ultima), '.') };
    }
}

/* 07 · El robot que aprende */

class SeccionDuelo extends Seccion {

    // [POO · POLIMORFISMO]
    pintar(datos) {
        const porRobot = {};
        datos.robots.forEach(function (r) { porRobot[r.tipo_robot] = r; });

        ['aleatorio', 'adaptativo'].forEach((robot) => {
            const fila = this.buscar('[data-robot="' + robot + '"]');
            const r = porRobot[robot] || null;
            fila.querySelector('[data-campo="valor"]').textContent = r ? porcentaje(r.pct_fuga) + ' DE FUGA' : '—';
            fila.querySelector('[data-campo="cubierta"]').style.width = (r ? 100 - r.pct_fuga : 100) + '%';
            fila.querySelector('[data-campo="meta"]').textContent = r
                ? plural(r.partidas_terminadas, 'partida', 'partidas') + ' · mediana ' + enMs(r.mediana_ms)
                : 'sin partidas todavía';
        });

        const d = datos.duelo;
        if (!d) {
            return { frase: frase('Todavía falta que se jueguen partidas con los dos robots.') };
        }
        if (d.hay_diferencia) {
            const masFuga = d.diferencia_pp > 0 ? 'adaptativo' : 'aleatorio';
            return { veredicto: 'senal', frase: frase('Con el robot ', masFuga, ' la IA se escapa ', dato(entero(Math.abs(d.diferencia_pp)) + ' puntos'), ' más seguido.') };
        }
        if (d.suficiente) {
            return { veredicto: 'plano', frase: frase('La IA se escapa igual de seguido con los dos robots.') };
        }
        return {
            veredicto: 'insuficiente',
            frase: frase('Todavía no se puede decir con cuál se escapa más: hay ', dato(plural(d.partidas_aleatorio, 'partida', 'partidas')), ' con el aleatorio y ',
                         dato(plural(d.partidas_adaptativo, 'partida', 'partidas')), ' con el adaptativo.')
        };
    }
}


// Panel: pregunta a la API, reparte y controla la señal
class Panel {
    // [POO · ENCAPSULAMIENTO]
    // El estado de la conexión (cuándo llegó lo último, cuántos fallos seguidos, el temporizador) es PRIVADO: solo iniciar() lo pone en marcha.
    #secciones;
    #nodoSenal;
    #nodoTextoSenal;
    #temporizador = null;
    #recibidoEn = null;
    #fallos = 0;

    constructor(secciones) {
        this.#secciones = secciones;
        this.#nodoSenal = document.getElementById('senal');
        this.#nodoTextoSenal = document.getElementById('senal-texto');
    }

    iniciar() {
        // Con la pestaña oculta no se consulta: no tiene sentido gastar la base para nadie.
        // Al volver se pregunta enseguida, así se ve lo último sin esperar.
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) { this.#consultar(); }
        });
        setInterval(() => this.#tic(), 1000);
        this.#consultar();
    }

    async #consultar() {
        clearTimeout(this.#temporizador);
        const control = new AbortController();
        const corte = setTimeout(function () { control.abort(); }, ESPERA_MAXIMA_MS);
        try {
            const respuesta = await fetch(URL_API, { cache: 'no-store', signal: control.signal });
            const datos = await respuesta.json();
            if (!respuesta.ok || !datos.ok) { throw new Error(datos.error || ('HTTP ' + respuesta.status)); }
            this.#recibir(datos);
        } catch (error) {
            this.#fallos++;
            this.#mostrarSenal();
        } finally {
            clearTimeout(corte);
            this.#programar();
        }
    }

    #programar() {
        if (document.hidden) { return; }
        // Si la API falla, se espera cada vez más (10 s, 20 s, 40 s, hasta 60 s) para no insistir.
        const espera = Math.min(60000, INTERVALO_MS * Math.pow(2, this.#fallos));
        this.#temporizador = setTimeout(() => this.#consultar(), espera);
    }

    #recibir(datos) {
        this.#fallos = 0;
        this.#recibidoEn = Date.now();
        this.#secciones.forEach(function (seccion) {
            // Si una sección falla, las demás siguen dibujándose.
            try { seccion.actualizar(datos); } catch (error) { console.error(error); }
        });
        this.#mostrarSenal();
    }

    #tic() {
        if (this.#recibidoEn === null) { return; }
        const segundos = (Date.now() - this.#recibidoEn) / 1000;
        this.#secciones.forEach(function (seccion) { seccion.tic(segundos); });
        this.#mostrarSenal();
    }

    #mostrarSenal() {
        if (this.#fallos > 0) {
            this.#nodoSenal.setAttribute('data-estado', 'caido');
            this.#nodoTextoSenal.textContent = 'SIN SEÑAL';
        } else if (this.#recibidoEn !== null) {
            const edad = Math.floor((Date.now() - this.#recibidoEn) / 1000);
            this.#nodoSenal.setAttribute('data-estado', 'vivo');
            this.#nodoTextoSenal.textContent = 'EN VIVO · ' + tiempoRelativo(edad).toUpperCase();
        }
    }
}

new Panel([
    new Cabecera('cabecera'),
    new SeccionAhora('s-ahora'),
    new SeccionPodio('s-podio'),
    new SeccionDistribucion('s-distribucion'),
    new SeccionDispositivos('s-dispositivos'),
    new SeccionTablero('s-tablero'),
    new SeccionRondas('s-rondas'),
    new SeccionDuelo('s-duelo')
]).iniciar();