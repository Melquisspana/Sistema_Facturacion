/*
 * Mapa de ventas (Rutas › Tablero › Mapa). Dibuja con d3 7.9.0 (public/vendor/d3) los límites
 * de public/geo y los datos que la vista deja en #mapa-datos. Todo se calcula en el servidor
 * (App\Services\Rutas\MapaVentas), y las listas y la tabla ya vienen escritas en la página:
 * acá se dibuja el mapa con sus cifras, se cambia de vista (ventas o a dónde ir), se entra a
 * un departamento para ver sus distritos y se arma la ficha del lugar elegido. La vista, el
 * departamento y el distrito quedan en el #hash de la dirección para que un enlace, recargar
 * o filtrar por mes o zona no los pierda.
 *
 * El mapa no se arrastra ni se acerca con el dedo: se acerca solo al entrar a un departamento.
 * Así, en el celular, deslizar sobre el dibujo mueve la página y no el mapa.
 */
(function () {
    'use strict';

    const raiz = document.getElementById('mapa-ventas');
    const fuente = document.getElementById('mapa-datos');
    if (!raiz || !fuente) {
        return;
    }
    const D = JSON.parse(fuente.textContent);
    const nombreRuta = new Map(D.rutas.map((r) => [r.id, r.nombre]));
    const unaRuta = D.ruta ? nombreRuta.get(D.ruta) : null;
    const $ = (id) => document.getElementById(id);

    // ---------- Formatos ----------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // Para el jefe de vendedores el servidor no manda plata (D.medida = 'facturas'): `ventas` y
    // `anterior` son cuántas facturas, y así se escriben.
    const enFacturas = D.medida === 'facturas';
    const facturas = (v) => Math.round(v || 0) + (Math.round(v || 0) === 1 ? ' factura' : ' facturas');
    const plata = (v) => (enFacturas ? facturas(v) : '$' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    const plataCorta = (v) => (enFacturas ? Math.round(v).toLocaleString('en-US') : '$' + Math.round(v).toLocaleString('en-US'));
    const cero = enFacturas ? 'sin facturas' : '$0';
    const fecha = (iso) => (iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '');
    const hace = (n) => (n === 0 ? 'hoy' : n === 1 ? 'ayer' : 'hace ' + n + ' días');
    const plural = (n, uno, varios) => n + ' ' + (n === 1 ? uno : varios);
    const enLista = (a) => (a.length <= 1 ? a.join('') : a.slice(0, -1).join(', ') + ' y ' + a[a.length - 1]);
    const cambio = (z) => (z.anterior ? ((z.ventas - z.anterior) / z.anterior) * 100 : null);
    // En un mes pasado todo se cuenta al cierre del mes (D.corte, «31/10»), no a hoy.
    const alCorte = D.corte ? ' al ' + D.corte : '';
    const zonasDe = (z) => z.rutas.map((id) => nombreRuta.get(id)).filter(Boolean);
    // Las mismas frases que escribe la vista en las listas.
    const pendientes = (z) => {
        const p = [];
        if (z.atrasadas) {
            p.push(plural(z.atrasadas, 'sala atrasada', 'salas atrasadas'));
        }
        if (z.leToca) {
            p.push((z.atrasadas ? z.leToca : plural(z.leToca, 'sala', 'salas')) + ' por visitar');
        }
        return p.join(' y ');
    };
    const compraronTexto = (z) => {
        if (!z.compraron) {
            return D.enCurso ? 'ninguna compró todavía' : 'ninguna compró';
        }
        if (z.compraron === z.salas) {
            return z.salas === 1 ? 'compró' : 'compraron todas';
        }
        return (z.compraron === 1 ? 'compró ' : 'compraron ') + z.compraron;
    };
    const salasTexto = (z) => plural(z.salas, 'sala', 'salas') + ' · ' + compraronTexto(z);
    const sinSalasCorto = (z) => (z.todas ? 'Sin salas de esta zona; ' + z.todas + ' de otras zonas' : 'Sin salas todavía');
    const visitaTexto = (s) => {
        if (s.estado === 'atrasada') {
            return 'Atrasada ' + plural(-s.faltan, 'día', 'días');
        }
        if (s.faltan > 0) {
            return 'Por visitar en ' + plural(s.faltan, 'día', 'días');
        }
        return s.faltan === 0 && !D.corte ? 'Por visitar hoy' : 'Por visitar';
    };
    // Las pastillas de estado, con las clases de x-rutas.estado-badge.
    const PASTILLA = {
        mal: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        ojo: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        bien: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
    };

    // ---------- Lugares: los del servidor y, cuando llegan las formas, los que no traen datos ----------
    // Sin prototipo: lo que llega del #hash («constructor», «__proto__») no es un lugar.
    const L = Object.create(null);
    const poner = (id, nivel, z) => {
        L[id] = Object.assign({ fuera: false }, z, { id, nivel });
    };
    Object.keys(D.departamentos).forEach((id) => poner(id, 'dep', D.departamentos[id]));
    Object.keys(D.distritos).forEach((id) => poner(id, 'dis', D.distritos[id]));
    // Un lugar que no vino del servidor: sin salas, sin ventas y sin ninguna zona.
    const vacio = (f) => ({ nombre: f.properties.n, dep: f.properties.d || f.properties.c, ventas: 0, anterior: 0, salas: 0, todas: 0, compraron: 0, faltan: 0, leToca: 0, atrasadas: 0, sinRitmo: 0, entrega: null, entregaDias: null, rutas: [], porVisitar: [], fuera: !!D.ruta });

    let rasgos = { dep: [], dis: [] };
    const forma = Object.create(null);
    const hayD3 = typeof window.d3 !== 'undefined';
    // Hasta que llegan las formas, un distrito sin datos no se puede encontrar: el del enlace
    // se respeta y recién después se descarta si no existe.
    let cargadas = !hayD3;
    const deps = () => rasgos.dep.map((f) => L[f.properties.c]);
    const distritosDe = (dep) => rasgos.dis.filter((f) => f.properties.d === dep).map((f) => L[f.properties.c]);
    const porVentas = (a, b) => b.ventas - a.ventas || b.salas - a.salas || a.nombre.localeCompare(b.nombre, 'es');
    const porUrgencia = (a, b) => b.atrasadas - a.atrasadas || b.leToca - a.leToca || a.nombre.localeCompare(b.nombre, 'es');

    // ---------- Estado (en el #hash) ----------
    const h = Object.fromEntries(new URLSearchParams(location.hash.slice(1)));
    const st = { vista: h.vista === 'ir' ? 'ir' : 'ventas', dep: h.dep || null, dis: h.dis || null };
    const guardarHash = () => {
        const p = new URLSearchParams({ vista: st.vista });
        if (st.dep) {
            p.set('dep', st.dep);
        }
        if (st.dis) {
            p.set('dis', st.dis);
        }
        history.replaceState(null, '', '#' + p.toString());
    };
    const validar = () => {
        if (st.dep && (!L[st.dep] || L[st.dep].nivel !== 'dep' || L[st.dep].fuera)) {
            st.dep = null;
        }
        if (!st.dep || (st.dis && cargadas && (!L[st.dis] || L[st.dis].dep !== st.dep))) {
            st.dis = null;
        }
    };
    const elegido = () => (st.dis && L[st.dis]) || (st.dep && L[st.dep]) || null;

    // ---------- Acciones ----------
    const elegirDep = (id) => {
        st.dep = st.dep === id && !st.dis ? null : id;
        st.dis = null;
        actualizar();
    };
    const elegirDis = (id) => {
        st.dis = st.dis === id ? null : id;
        actualizar();
    };
    raiz.addEventListener('click', (ev) => {
        // Los chips de zona de la ficha vuelven a pedir la página: se llevan el #hash del momento.
        const chip = ev.target.closest('a[data-con-hash]');
        if (chip) {
            chip.href = chip.href.split('#')[0] + location.hash;
            return;
        }
        const b = ev.target.closest('[data-vista],[data-lugar],[data-dep-volver]');
        if (!b) {
            return;
        }
        if (b.dataset.vista) {
            st.vista = b.dataset.vista;
        } else if (b.dataset.depVolver) {
            st.dep = b.dataset.depVolver;
            st.dis = null;
        } else {
            const z = L[b.dataset.lugar];
            if (!z) {
                return;
            }
            if (z.nivel === 'dep') {
                st.dep = z.id;
                st.dis = null;
            } else {
                st.dep = z.dep;
                st.dis = st.dis === z.id ? null : z.id;
            }
        }
        actualizar();
        // Desde la lista o la tabla, en una sola columna, el mapa y la ficha quedaron arriba.
        if (b.dataset.lugar && window.matchMedia('(max-width: 1023px)').matches) {
            $('mapa-titulo').scrollIntoView({ behavior: quieto ? 'auto' : 'smooth', block: 'start' });
        }
    });
    raiz.addEventListener('keydown', (ev) => {
        const fila = ev.target.closest('tr[data-lugar]');
        if (fila && (ev.key === 'Enter' || ev.key === ' ')) {
            ev.preventDefault();
            fila.click();
        }
    });
    $('mapa-volver').addEventListener('click', () => {
        st.dep = null;
        st.dis = null;
        actualizar();
    });
    // Filtrar por mes o zona vuelve a pedir la página: se lleva el #hash para seguir en la
    // misma vista y en el mismo lugar.
    const filtros = $('mapa-filtros');
    if (filtros) {
        filtros.addEventListener('submit', () => {
            filtros.action = filtros.action.split('#')[0] + location.hash;
        });
    }

    // ---------- Mapa ----------
    const quieto = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const fino = window.matchMedia('(hover: hover)').matches;
    const tip = $('mapa-tip');
    const lienzo = raiz.querySelector('.mv-lienzo');
    let svg, g, gDep, gDis, gMarco, gEt, rayas, path;
    let W = 800, H = 480, angosto = false, enfoque = { k: 1, x: 0, y: 0 }, primera = true;

    if (hayD3) {
        svg = d3.select('#mapa-svg');
        rayas = svg.append('defs').append('pattern').attr('id', 'mapa-rayado').attr('width', 6).attr('height', 6)
            .attr('patternUnits', 'userSpaceOnUse').attr('patternTransform', 'rotate(45)');
        rayas.append('rect').attr('width', 6).attr('height', 6).style('fill', 'var(--raya-fondo)');
        rayas.append('line').attr('x1', 0).attr('y1', 0).attr('x2', 0).attr('y2', 6).style('stroke', 'var(--raya)').attr('stroke-width', 1.6);
        g = svg.append('g');
        gDep = g.append('g');
        gDis = g.append('g');
        gMarco = g.append('g');
        gEt = svg.append('g').attr('aria-hidden', 'true');
    }

    // Proyección plana con la corrección del coseno de la latitud: El Salvador es chico y
    // así no hace falta una proyección de d3.geo, que lee el sentido de los polígonos.
    const COS = Math.cos((13.7 * Math.PI) / 180);
    const proyectar = () => {
        // El alto sigue al ancho: el país es más ancho que alto y así no quedan franjas vacías.
        W = Math.max(280, svg.node().getBoundingClientRect().width);
        angosto = W < 560;
        H = Math.round(angosto ? W * 0.6 : Math.max(380, Math.min(560, W * 0.62)));
        svg.style('height', H + 'px').attr('viewBox', '0 0 ' + W + ' ' + H).classed('mv-angosto', angosto);
        let x0 = Infinity; let y0 = Infinity; let x1 = -Infinity; let y1 = -Infinity;
        const recorrer = (c) => {
            if (typeof c[0] === 'number') {
                x0 = Math.min(x0, c[0]); x1 = Math.max(x1, c[0]); y0 = Math.min(y0, c[1]); y1 = Math.max(y1, c[1]);
            } else {
                c.forEach(recorrer);
            }
        };
        rasgos.dep.forEach((f) => recorrer(f.geometry.coordinates));
        const pad = angosto ? 10 : 22;
        const s = Math.min((W - 2 * pad) / ((x1 - x0) * COS), (H - 2 * pad) / (y1 - y0));
        const ox = (W - (x1 - x0) * COS * s) / 2 - x0 * COS * s;
        const oy = (H - (y1 - y0) * s) / 2 + y1 * s;
        path = d3.geoPath(d3.geoTransform({ point(x, y) { this.stream.point(ox + x * COS * s, oy - y * s); } }));
    };

    // De 0 a 100: del tono bajo al medio y del medio al alto, para que el azul no se apague en el camino.
    const mezcla = (t) => (t < 50
        ? 'color-mix(in oklab, var(--v-lo), var(--v-mid) ' + t * 2 + '%)'
        : 'color-mix(in oklab, var(--v-mid), var(--v-hi) ' + (t - 50) * 2 + '%)');
    // El color de un lugar. Ventas: del tono bajo al alto según cuánto vendió frente al que más
    // vendió de los que se ven; «$0» en gris y «sin salas» rayado. A dónde ir: rojo si hay salas
    // atrasadas, amarillo si solo hay por visitar, gris si está al día.
    const relleno = (z, max) => {
        if (z.fuera) {
            return { clase: 'mv-fuera', base: 'var(--fuera)' };
        }
        if (!z.salas) {
            return { clase: 'mv-rayado', base: 'url(#mapa-rayado)' };
        }
        if (st.vista === 'ir') {
            return z.atrasadas ? { clase: 'mv-mal', base: 'var(--a-mal)' } : z.leToca ? { clase: 'mv-ojo', base: 'var(--a-ojo)' } : { clase: 'mv-cero', base: 'var(--cero)' };
        }
        if (!(z.ventas > 0)) {
            return { clase: 'mv-cero', base: 'var(--cero)' };
        }
        const t = Math.round(Math.min(1, z.ventas / max) * 100);
        return { clase: 'mv-vende', base: 'var(' + (t < 34 ? '--v-lo' : t < 67 ? '--v-mid' : '--v-hi') + ')', mezcla: mezcla(t) };
    };
    const describir = (z) => z.nombre + ': ' + (z.fuera ? 'fuera de la zona ' + (unaRuta || '')
        : !z.salas ? sinSalasCorto(z).toLowerCase()
            : plata(z.ventas) + ', ' + salasTexto(z) + (pendientes(z) ? ', ' + pendientes(z) : ''));
    const pintar = (seleccion, max, extra) => {
        seleccion.each(function (f) {
            const z = L[f.properties.c];
            const r = relleno(z, max);
            this.setAttribute('class', 'mv-lugar ' + r.clase + (extra ? ' ' + extra(f) : ''));
            this.style.fill = r.base;
            if (r.mezcla) {
                // Si el navegador no entiende color-mix, queda el tono base.
                this.style.fill = r.mezcla;
            }
            this.setAttribute('aria-label', describir(z));
        });
    };

    const mostrarTip = (ev, z) => {
        if (!fino) {
            return;
        }
        tip.innerHTML = '<b>' + esc(z.nombre) + '</b><br>' + (z.fuera ? 'Fuera de la zona ' + esc(unaRuta)
            : !z.salas ? esc(sinSalasCorto(z))
                : plata(z.ventas) + ' en el mes<br>' + esc(salasTexto(z)) + (pendientes(z) ? '<br>' + esc(pendientes(z)) : ''));
        tip.hidden = false;
        const r = lienzo.getBoundingClientRect();
        let x = ev.clientX - r.left + 14;
        if (x + 240 > r.width) {
            x -= 250;
        }
        tip.style.left = Math.max(4, x) + 'px';
        tip.style.top = ev.clientY - r.top + 14 + 'px';
    };
    const alTocar = (sel, fn) => sel.attr('tabindex', 0).attr('role', 'button')
        .on('click', (ev, f) => fn(f.properties.c))
        .on('keydown', (ev, f) => {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                fn(f.properties.c);
            }
        })
        .on('mousemove', (ev, f) => mostrarTip(ev, L[f.properties.c]))
        .on('mouseleave', () => {
            tip.hidden = true;
        });

    // ---------- Nombres y cifras sobre el mapa ----------
    // El ancho real de cada texto, con la letra de la página.
    const medidor = document.createElement('canvas').getContext('2d');
    const anchoTexto = (texto, peso, px) => {
        medidor.font = peso + ' ' + px + 'px ' + getComputedStyle(raiz).fontFamily;
        return medidor.measureText(texto).width;
    };
    // Correcciones a mano del lugar de cada nombre (en px con el mapa de 860 de ancho): el
    // centro del país es angosto. En el celular los nombres no se achican con el mapa y van
    // con su propio corrimiento.
    const AJUSTE = { '06': [4, -6], '07': [10, -2], '05': [-8, 16], '08': [6, 14], '09': [6, -6], '10': [0, 4], '04': [0, -6], 13: [0, -4], 14: [8, 0], '03': [-4, 2], '01': [-4, 0], 12: [-2, 8], 11: [0, 6], '02': [0, -4] };
    const AJUSTE_CEL = { '06': [8, -9], '05': [-9, 13], '07': [13, -1], '08': [2, 8], '09': [5, -1], 10: [3, 5], '04': [0, -2], 13: [0, 0], 14: [5, 0], '03': [3, 7], '01': [2, -9], 12: [-2, 6], 11: [0, 6], '02': [-2, 0] };
    const CORTO = { 'San Salvador': 'S. Salvador', 'San Vicente': 'S. Vicente', 'San Miguel': 'S. Miguel' };
    const nombreEn = (z) => (angosto && CORTO[z.nombre] ? CORTO[z.nombre] : z.nombre);
    const conCifra = (z) => !z.fuera && z.salas > 0 && (st.vista === 'ventas' ? z.ventas > 0 : z.atrasadas + z.leToca > 0);
    const textoMenor = (z) => (!z.salas ? 'sin salas' : st.vista === 'ventas' ? cero : 'al día');
    const partesPastilla = (z) => [['mv-p-mal', z.atrasadas], ['mv-p-ojo', z.leToca]].filter((q) => q[1] > 0);
    const anchoPastilla = (z) => partesPastilla(z).reduce((a, q) => a + 19 + anchoTexto(String(q[1]), 700, 12), 7);

    // modo: 'todo' (nombre y cifra), 'cifra' (solo la cifra) o 'nombre' (solo el nombre, tenue).
    const etiqueta = (z, x, y, modo) => {
        const e = gEt.append('g').attr('class', modo === 'nombre' ? 'mv-tenue' : null);
        if (modo !== 'cifra') {
            e.append('text').attr('class', 'mv-et mv-et-n').attr('x', x).attr('y', y - 3).text(nombreEn(z));
        }
        if (modo === 'nombre' || z.fuera) {
            return;
        }
        const y2 = modo === 'cifra' ? y + 4 : y + (angosto ? 9 : 11);
        if (!conCifra(z)) {
            e.append('text').attr('class', 'mv-et mv-et-m').attr('x', x).attr('y', y2 - 1).text(textoMenor(z));
            return;
        }
        if (st.vista === 'ventas') {
            e.append('text').attr('class', 'mv-et mv-et-v').attr('x', x).attr('y', y2).text(plataCorta(z.ventas));
            return;
        }
        // Pastilla: punto rojo y cuántas atrasadas, punto ámbar y cuántas por visitar.
        const ancho = anchoPastilla(z);
        const alto = 19;
        const p = e.append('g').attr('class', 'mv-pastilla').attr('transform', 'translate(' + (x - ancho / 2) + ',' + (modo === 'cifra' ? y - alto / 2 : y + 1) + ')');
        p.append('rect').attr('width', ancho).attr('height', alto).attr('rx', alto / 2);
        let px = 7;
        partesPastilla(z).forEach((q) => {
            p.append('circle').attr('class', q[0]).attr('cx', px + 5).attr('cy', alto / 2).attr('r', 4.5);
            p.append('text').attr('x', px + 12).attr('y', alto / 2 + 4.2).text(q[1]);
            px += 19 + anchoTexto(String(q[1]), 700, 12);
        });
    };

    // Pone las etiquetas sin que se encimen. Va por orden de importancia: a cada lugar le prueba
    // su sitio y unos corrimientos cortos; si el nombre con la cifra no entra, va solo la cifra;
    // si tampoco, ese lugar queda sin etiqueta en el dibujo (está en la lista y en la tabla).
    const etiquetas = () => {
        gEt.selectAll('*').remove();
        const aPantalla = (p) => [p[0] * enfoque.k + enfoque.x, p[1] * enfoque.k + enfoque.y];
        const puestos = [];
        const libre = (b) => !puestos.some((q) => b[0] < q[2] && b[2] > q[0] && b[1] < q[3] && b[3] > q[1]);
        const prueba = [[0, 0], [0, -10], [0, 10], [-14, 0], [14, 0], [-12, -9], [12, -9], [-12, 9], [12, 9], [0, -18], [0, 18]];
        const colocar = (z, c, modos) => {
            if (!c || !isFinite(c[0]) || !isFinite(c[1])) {
                return;
            }
            const pastilla = st.vista === 'ir' && conCifra(z);
            const nom = anchoTexto(nombreEn(z), 600, angosto ? 9.5 : 11) / 2 + 3;
            const val = (!conCifra(z) ? anchoTexto(textoMenor(z), 500, angosto ? 9 : 10.5) : pastilla ? anchoPastilla(z) : anchoTexto(plataCorta(z.ventas), 700, angosto ? 11.5 : 13.5)) / 2 + 3;
            const caja = (m, dx, dy) => {
                const x = c[0] + dx;
                const y = c[1] + dy;
                if (m === 'todo') {
                    return [x - Math.max(nom, val), y - 12, x + Math.max(nom, val), y + (pastilla ? 21 : angosto ? 12 : 14)];
                }
                return m === 'cifra' ? [x - val, y - (pastilla ? 10 : 8), x + val, y + (pastilla ? 10 : 8)] : [x - nom, y - 12, x + nom, y + 1];
            };
            for (const m of modos) {
                const d = prueba.find((q) => {
                    const b = caja(m, q[0], q[1]);
                    return b[0] > 1 && b[2] < W - 1 && b[1] > 1 && b[3] < H - 1 && libre(b);
                });
                if (d) {
                    puestos.push(caja(m, d[0], d[1]));
                    etiqueta(z, c[0] + d[0], c[1] + d[1], m);
                    return;
                }
            }
        };
        const orden = (a, b) => conCifra(b) - conCifra(a) || (st.vista === 'ir' ? porUrgencia(a, b) : 0) || porVentas(a, b);
        if (!st.dep) {
            const escala = angosto ? 1 : W / 860;
            const centro = (z) => {
                const c = path.centroid(forma[z.id]);
                const a = (angosto ? AJUSTE_CEL : AJUSTE)[z.id] || [0, 0];
                return [c[0] + a[0] * escala, c[1] + a[1] * escala];
            };
            deps().sort(orden).forEach((z) => colocar(z, centro(z), conCifra(z) ? ['todo', 'cifra'] : angosto || z.fuera ? ['nombre'] : ['todo', 'nombre']));
            return;
        }
        // Adentro de un departamento: sus distritos con salas y, tenues, los departamentos vecinos.
        distritosDe(st.dep).filter((z) => z.salas > 0 || z.id === st.dis)
            .sort((a, b) => (b.id === st.dis) - (a.id === st.dis) || orden(a, b))
            .forEach((z) => colocar(z, aPantalla(path.centroid(forma[z.id])), conCifra(z) ? ['todo', 'cifra'] : ['todo', 'nombre']));
        rasgos.dep.forEach((f) => {
            if (f.properties.c !== st.dep) {
                colocar(L[f.properties.c], aPantalla(path.centroid(f)), ['nombre']);
            }
        });
    };

    // Cada color del dibujo tiene su renglón en la leyenda, debajo del mapa.
    const leyenda = (lugares) => {
        const m = (clase, fondo, texto) => '<span class="mv-mues"><i class="' + clase + '"' + (fondo ? ' style="background:' + fondo + '"' : '') + '></i>' + texto + '</span>';
        const sinSalas = m('mv-rayas', '', unaRuta ? 'Sin salas de esta zona' : 'Sin salas');
        const fuera = unaRuta ? m('mv-punteado', 'var(--fuera)', 'Fuera de la zona ' + esc(unaRuta)) : '';
        if (st.vista === 'ir') {
            $('mapa-leyenda').innerHTML = m('', 'var(--a-mal)', 'Tiene salas atrasadas') + m('', 'var(--a-ojo)', 'Solo por visitar') + m('', 'var(--cero)', 'Al día') + sinSalas + fuera
                + '<span class="mv-mues"><span class="mv-mini"><i class="mv-punto" style="background:var(--mal)"></i>1 <i class="mv-punto" style="background:var(--ojo)"></i>3</span>1 atrasada y 3 por visitar</span>';
            return;
        }
        const v = lugares.map((z) => z.ventas).filter((x) => x > 0);
        const menor = Math.min(...v);
        const mayor = Math.max(...v);
        const rampa = !v.length ? ''
            : plataCorta(menor) === plataCorta(mayor) ? m('', 'var(--v-hi)', enFacturas ? facturas(mayor) : 'Vendió ' + plataCorta(mayor))
                : '<span class="mv-rampa"><span>menos · ' + plataCorta(menor) + '</span><i></i><span>' + plataCorta(mayor) + ' · más</span></span>';
        $('mapa-leyenda').innerHTML = rampa + m('', 'var(--cero)', cero + (D.enCurso ? ' este mes' : ' en el mes')) + sinSalas + fuera;
    };

    const titulos = () => {
        const z = elegido();
        $('mapa-volver').hidden = !st.dep;
        $('mapa-titulo').textContent = (st.vista === 'ir' ? 'A dónde ir' : 'Ventas') + (st.dep ? ' en ' + L[st.dep].nombre + ', por distrito' : ' por departamento');
        let sub;
        if (z) {
            sub = '<b>' + esc(z.nombre) + ':</b> ' + (z.fuera ? 'fuera de la zona ' + esc(unaRuta) + '.'
                : !z.salas ? esc(sinSalasCorto(z).toLowerCase()) + '.'
                    : st.vista === 'ir' ? esc(pendientes(z) || 'al día, ninguna sala atrasada ni por visitar') + '.'
                        : plata(z.ventas) + ' · ' + esc(salasTexto(z)) + '.');
        } else {
            sub = st.vista === 'ir'
                ? 'Rojo: hay salas atrasadas. Amarillo: hay salas por visitar' + esc(alCorte) + '. Tocá un lugar para ver cuáles.'
                : 'Azul más fuerte, más vendido. Tocá un departamento para ver sus distritos.';
        }
        $('mapa-sub').innerHTML = sub;
    };

    const dibujar = () => {
        titulos();
        if (!hayD3 || !rasgos.dep.length) {
            return;
        }
        proyectar();
        const pais = deps();
        const adentro = st.dep ? distritosDe(st.dep) : [];
        const maxDep = Math.max(0.01, ...pais.map((z) => z.ventas));
        const maxDis = Math.max(0.01, ...adentro.map((z) => z.ventas));

        alTocar(gDep.selectAll('path').data(rasgos.dep, (f) => f.properties.c).join('path').attr('d', path), elegirDep);
        pintar(gDep.selectAll('path'), maxDep, (f) => (st.dep ? (f.properties.c === st.dep ? 'mv-abierto' : 'mv-apagado') : ''));
        alTocar(gDis.selectAll('path').data(st.dep ? rasgos.dis.filter((f) => f.properties.d === st.dep) : [], (f) => f.properties.c).join('path').attr('d', path), elegirDis);
        pintar(gDis.selectAll('path'), maxDis);
        const marcos = [];
        if (st.dep) {
            marcos.push({ f: forma[st.dep], c: 'mv-marco mv-marco-dep' });
        }
        if (st.dis && forma[st.dis]) {
            marcos.push({ f: forma[st.dis], c: 'mv-marco' });
        }
        gMarco.selectAll('path').data(marcos).join('path').attr('class', (m) => m.c).attr('d', (m) => path(m.f));

        // Acercarse al departamento elegido (o volver a todo el país).
        let nuevo = { k: 1, x: 0, y: 0 };
        if (st.dep) {
            const b = path.bounds(forma[st.dep]);
            const k = Math.min(9, 0.88 / Math.max((b[1][0] - b[0][0]) / W, (b[1][1] - b[0][1]) / H));
            nuevo = { k, x: W / 2 - (k * (b[0][0] + b[1][0])) / 2, y: H / 2 - (k * (b[0][1] + b[1][1])) / 2 };
        }
        const cambia = nuevo.k !== enfoque.k || nuevo.x !== enfoque.x || nuevo.y !== enfoque.y;
        enfoque = nuevo;
        rayas.attr('patternTransform', 'rotate(45) scale(' + 1 / enfoque.k + ')');
        const tr = 'translate(' + enfoque.x + ',' + enfoque.y + ') scale(' + enfoque.k + ')';
        if (cambia && !primera && !quieto) {
            gEt.style('opacity', 0);
            g.transition().duration(450).attr('transform', tr).on('end', () => {
                etiquetas();
                gEt.style('opacity', 1);
            });
        } else {
            g.interrupt().attr('transform', tr);
            etiquetas();
            gEt.style('opacity', 1);
        }
        primera = false;
        leyenda(st.dep ? adentro : pais);
    };

    // ---------- Ficha del lugar ----------
    const enlaceZona = (id) => {
        const p = new URLSearchParams({ mes: D.mes });
        if (D.ruta !== id) {
            p.set('ruta', id);
        }
        return '?' + p.toString();
    };
    const sinSalasLargo = (z) => {
        if (z.fuera) {
            return 'La zona ' + esc(unaRuta) + ' no pasa por acá.';
        }
        if (z.todas) {
            return esc(unaRuta || 'Esta zona') + ' no tiene salas acá. Hay ' + plural(z.todas, 'sala', 'salas') + ' de otras zonas.';
        }
        if (z.ventas || z.anterior) {
            return 'Ya no quedan salas activas acá.';
        }
        const zonas = zonasDe(z);
        return 'Todavía no tenemos salas acá. ' + (zonas.length ? (zonas.length === 1 ? 'La zona ' : 'Las zonas ') + esc(enLista(zonas)) + (zonas.length === 1 ? ' ya pasa' : ' ya pasan') + ' cerca: se podría ofrecer.' : 'Ninguna zona pasa por acá.');
    };
    const rotulo = 'text-xs font-semibold uppercase tracking-wide text-gray-500';
    const fichaHtml = (z) => {
        const pct = cambio(z);
        let dinero = '';
        // Con plata del mes o del anterior se muestran los números, aunque ya no queden salas.
        if (z.salas || z.ventas || z.anterior) {
            const frente = z.ventas > 0
                ? (pct === null ? (enFacturas ? 'en el mes' : 'vendido en el mes') + '; sin ventas para comparar ' + esc(D.contra) : (enFacturas ? 'en el mes, ' : 'vendido en el mes, ') + esc(D.contra))
                : (D.enCurso ? 'Este mes todavía no compró nadie.' : 'En el mes no compró nadie.') + (z.anterior ? ' ' + esc(D.antes) + ': ' + plata(z.anterior) + '.' : '');
            dinero = '<div class="flex flex-wrap items-baseline gap-x-3 gap-y-1"><span class="text-3xl font-bold tabular-nums text-gray-800">' + plata(z.ventas) + '</span>'
                + (pct !== null && z.ventas > 0 ? '<span class="rounded-full px-2 py-0.5 text-xs font-semibold ' + (pct >= 0 ? PASTILLA.bien : PASTILLA.mal) + '">' + (pct >= 0 ? '▲ ' : '▼ ') + Math.abs(pct).toFixed(0) + ' %</span>' : '')
                + '<span class="w-full text-sm text-gray-500">' + frente + '</span></div>';
        }
        const zonas = z.rutas.length
            ? z.rutas.map((id) => '<a href="' + esc(enlaceZona(id)) + '" data-con-hash class="inline-flex min-h-11 items-center rounded-full px-4 text-sm font-medium '
                + (D.ruta === id ? 'bg-indigo-600 text-white' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50') + '"'
                + (D.ruta === id ? ' aria-current="true" title="Ver todas las zonas"' : ' title="Ver solo esta zona"') + '>' + esc(nombreRuta.get(id)) + '</a>').join('')
            : '<span class="text-sm font-normal text-gray-500">Ninguna zona pasa por acá.</span>';
        // En un mes pasado, la fecha de la entrega: «hoy» o «ayer» serían del cierre del mes.
        const entrega = z.entrega
            ? '<time datetime="' + esc(z.entrega) + '" title="' + fecha(z.entrega) + '">' + (D.corte ? fecha(z.entrega) : hace(z.entregaDias)) + '</time>'
            : '<span class="font-normal text-gray-500">sin entregas todavía</span>';
        const par = (dt, dd, ancho) => '<div' + (ancho ? ' class="col-span-2"' : '') + '><dt class="text-xs text-gray-500">' + dt + '</dt><dd class="text-sm font-semibold text-gray-800">' + dd + '</dd></div>';
        const pares = z.salas
            ? '<dl class="grid grid-cols-2 gap-x-4 gap-y-3">'
                + par('Salas', z.salas + ' <span class="font-normal text-gray-500">· ' + esc(compraronTexto(z)) + '</span>')
                + par('Última entrega' + esc(alCorte), entrega)
                + par(z.rutas.length === 1 ? 'Zona' : 'Zonas', '<span class="mt-1 flex flex-wrap gap-2">' + zonas + '</span>', true)
                + '</dl>'
            : '<p class="text-sm text-gray-600">' + sinSalasLargo(z) + '</p>';
        let visitas = '';
        if (z.salas) {
            const mas = z.faltan - z.porVisitar.length;
            visitas = '<div><h4 class="' + rotulo + '">Para visitar' + esc(D.corte ? ' (al ' + D.corte + ')' : '') + '</h4>'
                + (z.porVisitar.length
                    ? '<ul class="mt-1 divide-y divide-gray-100">' + z.porVisitar.map((s) => '<li class="flex items-baseline justify-between gap-3 py-2 text-sm"><span class="min-w-0 text-gray-800">' + esc(s.nombre)
                        + (z.nivel === 'dep' && s.lugar ? '<span class="block text-xs text-gray-500">' + esc(s.lugar) + '</span>' : '') + '</span>'
                        + '<span class="shrink-0 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ' + (s.estado === 'atrasada' ? PASTILLA.mal : PASTILLA.ojo) + '">' + visitaTexto(s) + '</span></li>').join('')
                        + (mas > 0 ? '<li class="py-2 text-sm text-gray-500">y ' + mas + ' más</li>' : '') + '</ul>'
                    : '<p class="mt-1"><span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ' + PASTILLA.bien + '">✓ Al día: ninguna atrasada ni por visitar</span></p>')
                + (z.sinRitmo ? '<p class="mt-1 text-xs text-gray-500">' + (z.sinRitmo === 1 ? '1 sala todavía no tiene ritmo: con menos de tres visitas no se sabe cada cuánto compra.' : z.sinRitmo + ' salas todavía no tienen ritmo: con menos de tres visitas no se sabe cada cuánto compran.') + '</p>' : '')
                + '</div>';
        }
        return '<div class="space-y-4">'
            + '<div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="' + rotulo + '">' + (z.nivel === 'dep' ? 'Departamento' : 'Distrito de ' + esc(L[z.dep] ? L[z.dep].nombre : '')) + '</p>'
            + '<h3 class="text-xl font-semibold text-gray-800">' + esc(z.nombre) + '</h3></div>'
            + (z.nivel === 'dis' && L[z.dep] ? '<button type="button" data-dep-volver="' + esc(z.dep) + '" class="inline-flex min-h-11 shrink-0 items-center justify-center whitespace-nowrap rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">‹ Todo ' + esc(L[z.dep].nombre) + '</button>' : '')
            + '</div>'
            + (st.vista === 'ir' ? visitas + dinero + pares : dinero + pares + visitas)
            + '</div>';
    };
    // La ficha es una región viva: solo se reescribe si cambió, para que el lector de
    // pantalla no la repita al redibujar.
    let fichaActual = null;
    const ficha = () => {
        const z = elegido();
        const html = z ? fichaHtml(z) : '';
        if (html !== fichaActual) {
            $('mapa-ficha').innerHTML = html;
            $('mapa-ficha').hidden = !z;
            fichaActual = html;
        }
    };

    // ---------- Listas y tabla: ya vienen escritas; se muestra la de la vista y el lugar ----------
    const listas = () => {
        const ambito = st.dep || 'pais';
        raiz.querySelectorAll('[data-lista]').forEach((el) => {
            el.hidden = el.dataset.lista !== ambito || el.dataset.listaVista !== st.vista;
        });
        raiz.querySelectorAll('[data-tabla]').forEach((el) => {
            el.hidden = el.dataset.tabla !== (st.dep ? 'dis' : 'dep');
        });
        raiz.querySelectorAll('tr[data-de-dep]').forEach((tr) => {
            tr.hidden = tr.dataset.deDep !== st.dep;
        });
        raiz.querySelectorAll('[data-tabla-texto]').forEach((el) => {
            el.hidden = el.dataset.tablaTexto !== (st.dep ? 'dis' : 'dep');
        });
        raiz.querySelectorAll('[data-nombre-dep]').forEach((el) => {
            el.textContent = st.dep ? L[st.dep].nombre : '';
        });
        raiz.querySelectorAll('[data-solo-ventas]').forEach((el) => {
            el.hidden = st.vista !== 'ventas';
        });
        const actual = st.dis || st.dep;
        raiz.querySelectorAll('[data-lugar]').forEach((el) => {
            if (el.dataset.lugar === actual && !el.closest('[hidden]')) {
                el.setAttribute('aria-current', 'true');
            } else {
                el.removeAttribute('aria-current');
            }
        });
    };

    function actualizar() {
        validar();
        if (tip) {
            tip.hidden = true;
        }
        raiz.querySelectorAll('.mv-seg [data-vista]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.vista === st.vista)));
        guardarHash();
        listas();
        ficha();
        dibujar();
    }

    // ---------- Carga de los límites ----------
    const carga = $('mapa-carga');
    const sinDibujo = 'No se pudo cargar el dibujo del mapa. La lista y la tabla tienen los mismos datos.';
    const pedir = (url) => fetch(url, { credentials: 'same-origin' }).then((r) => {
        if (!r.ok) {
            throw new Error('HTTP ' + r.status);
        }
        return r.json();
    });
    actualizar();
    if (!hayD3) {
        carga.textContent = sinDibujo;
        return;
    }
    Promise.all([pedir(D.geo.departamentos), pedir(D.geo.distritos)]).then(([dep, dis]) => {
        rasgos = { dep: dep.features, dis: dis.features };
        rasgos.dep.forEach((f) => {
            forma[f.properties.c] = f;
            if (!L[f.properties.c]) {
                poner(f.properties.c, 'dep', vacio(f));
            }
        });
        rasgos.dis.forEach((f) => {
            forma[f.properties.c] = f;
            if (!L[f.properties.c]) {
                poner(f.properties.c, 'dis', vacio(f));
            }
        });
        cargadas = true;
        carga.remove();
        $('mapa-leyenda').hidden = false;
        actualizar();
        const redibujar = () => {
            primera = true;
            dibujar();
        };
        let espera;
        window.addEventListener('resize', () => {
            clearTimeout(espera);
            espera = setTimeout(redibujar, 150);
        });
        // Los anchos de los nombres se miden con la letra de la página: al llegar, se acomodan de nuevo.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(redibujar);
        }
    }).catch(() => {
        cargadas = true;
        actualizar();
        carga.textContent = sinDibujo;
    });
})();
