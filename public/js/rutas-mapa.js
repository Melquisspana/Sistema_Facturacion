/*
 * Mapa de ventas por zona (Rutas › Tablero › Mapa). Dibuja con d3 7.9.0 (public/vendor/d3)
 * los límites de public/geo y los datos que la vista deja en #mapa-datos. Todo se calcula
 * en el servidor (App\Services\Rutas\MapaVentas); acá solo se cambia de modo (ventas, dónde
 * falta, rutas) y de nivel (departamentos, distritos) sin volver a pedir la página, y se
 * arma la ficha de la zona elegida. El modo, el nivel y la zona quedan en el #hash de la
 * dirección para que un enlace, recargar o filtrar por mes o ruta no los pierda.
 */
(function () {
    'use strict';

    const raiz = document.getElementById('mapa-ventas');
    const fuente = document.getElementById('mapa-datos');
    if (!raiz || !fuente) {
        return;
    }
    const D = JSON.parse(fuente.textContent);
    const NIVELES = { dep: D.departamentos, dis: D.distritos };
    const nombreRuta = new Map(D.rutas.map((r) => [r.id, r.nombre]));
    const unaRuta = D.ruta ? nombreRuta.get(D.ruta) : null;
    const $ = (id) => document.getElementById(id);
    // Lo que llega del #hash se busca solo entre las claves propias: «constructor» o
    // «__proto__» no son zonas.
    const propia = (objeto, clave) => Object.prototype.hasOwnProperty.call(objeto, clave);

    // ---------- Formatos ----------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const plata = (v) => '$' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const corto = (v) => (v >= 1000 ? '$' + (v / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 }) + 'k' : '$' + Math.round(v));
    const fecha = (iso) => (iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '');
    const hace = (n) => (n === 0 ? 'hoy' : n === 1 ? 'ayer' : 'hace ' + n + ' días');
    const rutasDe = (z) => z.rutas.map((id) => nombreRuta.get(id)).filter(Boolean);
    const cambio = (z) => (z.anterior ? ((z.ventas - z.anterior) / z.anterior) * 100 : null);
    // En un mes pasado los días se cuentan al cierre del mes (D.corte, «31/10»), no a hoy.
    const alCorte = D.corte ? ' al ' + D.corte : '';
    // Una sala de «Dónde falta», por su ritmo: atrasada (pasó su fecha con margen) o le toca.
    const dias = (n) => n + (n === 1 ? ' día' : ' días');
    const estado = (s) => (s.estado === 'atrasada'
        ? ['var(--mal)', 'Atrasada ' + dias(-s.faltan)]
        : ['var(--ojo)', s.faltan > 0 ? 'Le toca en ' + dias(s.faltan) : 'Le toca']);
    const atrasadasTexto = (z) => z.atrasadas + (z.atrasadas === 1 ? ' atrasada' : ' atrasadas') + ', a ' + z.leToca + (z.leToca === 1 ? ' le toca' : ' les toca');

    // ---------- Estado (en el #hash) ----------
    const leerHash = () => Object.fromEntries(new URLSearchParams(location.hash.slice(1)));
    const h = leerHash();
    const st = {
        modo: ['ventas', 'falta', 'rutas'].includes(h.modo) ? h.modo : 'ventas',
        nivel: ['dep', 'dis'].includes(h.nivel) ? h.nivel : 'dep',
        sel: h.zona || null,
    };
    const guardarHash = () => {
        const p = new URLSearchParams({ modo: st.modo, nivel: st.nivel });
        if (st.sel) {
            p.set('zona', st.sel);
        }
        history.replaceState(null, '', '#' + p.toString());
    };

    let rasgos = { dep: [], dis: [] };
    // Hasta que llegan las formas, un distrito sin datos no se puede encontrar: la zona del
    // enlace se respeta y recién después se cambia por la de por defecto si no existe.
    const hayD3 = typeof window.d3 !== 'undefined';
    let cargadas = !hayD3;
    const nombreDep = (c) => (propia(D.departamentos, c) ? D.departamentos[c].nombre : '');
    const vacia = (f) => ({ nombre: f.properties.n, dep: f.properties.d || f.properties.c, ventas: 0, anterior: 0, salas: 0, todas: 0, compraron: 0, faltan: 0, leToca: 0, atrasadas: 0, sinRitmo: 0, entrega: null, entregaDias: null, rutas: [], porVisitar: [] });
    const zona = (id) => {
        const datos = NIVELES[st.nivel];
        const z = propia(datos, id) ? datos[id] : null;
        if (z) {
            return z;
        }
        const f = rasgos[st.nivel].find((x) => x.properties.c === id);
        return f ? vacia(f) : null;
    };
    const porDefecto = () => {
        const lista = Object.entries(NIVELES[st.nivel]).sort((a, b) => b[1].ventas - a[1].ventas || b[1].salas - a[1].salas);
        return lista.length ? lista[0][0] : (rasgos[st.nivel][0] ? rasgos[st.nivel][0].properties.c : null);
    };

    // ---------- Botones de modo y nivel ----------
    const marcar = (atributo, valor) => document.querySelectorAll('[' + atributo + ']').forEach((b) => b.setAttribute('aria-pressed', String(b.getAttribute(atributo) === valor)));
    document.querySelectorAll('[data-modo]').forEach((b) => b.addEventListener('click', () => {
        st.modo = b.dataset.modo;
        actualizar();
    }));
    document.querySelectorAll('[data-nivel]').forEach((b) => b.addEventListener('click', () => {
        if (st.nivel === b.dataset.nivel) {
            return;
        }
        st.nivel = b.dataset.nivel;
        st.sel = porDefecto();
        reiniciarZoom();
        actualizar();
    }));
    // Las filas de la tabla y las listas de «Dónde falta» eligen la zona. Los chips de ruta de
    // la ficha se llevan el #hash del momento del clic.
    raiz.addEventListener('click', (ev) => {
        const chip = ev.target.closest('a[data-con-hash]');
        if (chip) {
            chip.href = chip.href.split('#')[0] + location.hash;
            return;
        }
        const el = ev.target.closest('[data-zona]');
        if (el) {
            elegir(el.dataset.zona);
        }
    });
    raiz.addEventListener('keydown', (ev) => {
        const el = ev.target.closest('tr[data-zona]');
        if (el && (ev.key === 'Enter' || ev.key === ' ')) {
            ev.preventDefault();
            elegir(el.dataset.zona);
        }
    });
    const elegir = (id) => {
        st.sel = id;
        actualizar();
        if (window.matchMedia('(max-width: 1023px)').matches) {
            $('mapa-ficha').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };
    // Filtrar por mes o ruta vuelve a pedir la página: se lleva el #hash para seguir en el
    // mismo modo, nivel y zona.
    const filtros = $('mapa-filtros');
    if (filtros) {
        filtros.addEventListener('submit', () => {
            filtros.action = filtros.action.split('#')[0] + location.hash;
        });
    }

    // ---------- Mapa ----------
    let svg, g, gZ, gD, gL, path, cortes = [], maxFalta = 1, zoom;
    // Los nombres de los departamentos mantienen su tamaño al acercar; en el celular, más chicos.
    const tamanoEtiquetas = (k) => {
        const letra = window.matchMedia('(max-width: 520px)').matches ? 9 : 11;
        gL.selectAll('text').style('font-size', letra / k + 'px').style('stroke-width', 3 / k + 'px');
    };
    const tip = $('mapa-tip');
    const tarjeta = raiz.querySelector('.mv-mapa');
    const mostrarTip = (ev, html) => {
        tip.innerHTML = html;
        tip.hidden = false;
        const r = tarjeta.getBoundingClientRect();
        let x = ev.clientX - r.left + 14;
        const y = ev.clientY - r.top + 14;
        if (x + 250 > r.width) {
            x -= 264;
        }
        tip.style.left = Math.max(4, x) + 'px';
        tip.style.top = y + 'px';
    };
    const ocultarTip = () => {
        tip.hidden = true;
    };
    const reiniciarZoom = () => {
        if (svg && zoom) {
            svg.call(zoom.transform, d3.zoomIdentity);
        }
    };

    if (hayD3) {
        svg = d3.select('#mapa-svg');
        const defs = svg.append('defs');
        const rayas = defs.append('pattern').attr('id', 'mapa-rayado').attr('width', 6).attr('height', 6)
            .attr('patternUnits', 'userSpaceOnUse').attr('patternTransform', 'rotate(45)');
        rayas.append('rect').attr('width', 6).attr('height', 6).style('fill', 'var(--mv-vacia)');
        rayas.append('line').attr('x1', 0).attr('y1', 0).attr('x2', 0).attr('y2', 6).style('stroke', 'var(--mv-raya)').attr('stroke-width', 2);
        g = svg.append('g');
        gZ = g.append('g');
        gD = g.append('g');
        gL = g.append('g');
        zoom = d3.zoom().scaleExtent([1, 12]).on('zoom', (e) => {
            g.attr('transform', e.transform);
            tamanoEtiquetas(e.transform.k);
        });
        svg.call(zoom).on('dblclick.zoom', null);
        const quieto = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const mover = () => svg.transition().duration(quieto ? 0 : 250);
        $('mapa-acercar').addEventListener('click', () => mover().call(zoom.scaleBy, 1.6));
        $('mapa-alejar').addEventListener('click', () => mover().call(zoom.scaleBy, 1 / 1.6));
        $('mapa-todo').addEventListener('click', () => mover().call(zoom.transform, d3.zoomIdentity));
    }

    // Proyección plana con la corrección del coseno de la latitud: El Salvador es chico y
    // así no hace falta una proyección de d3.geo, que lee el sentido de los polígonos.
    const COS = Math.cos((13.7 * Math.PI) / 180);
    const proyectar = () => {
        // El alto sigue al ancho: el país es más ancho que alto y así no quedan franjas vacías.
        // En el celular se deja lugar arriba para los botones de zoom, que van en fila; con el
        // mapa ancho, abajo para la leyenda, que va encima del dibujo.
        const W = svg.node().getBoundingClientRect().width;
        // El mismo corte que el bloque <style> de la vista (botones en fila, leyenda abajo).
        const angosto = window.matchMedia('(max-width: 520px)').matches;
        const arriba = angosto ? 56 : 0;
        const abajo = getComputedStyle($('mapa-leyenda')).position === 'absolute' ? 40 : 0;
        const H = Math.round(angosto ? W * 0.55 + arriba : Math.max(360, Math.min(560, W * 0.62)));
        svg.style('height', H + 'px');
        svg.attr('viewBox', '0 0 ' + W + ' ' + H);
        let x0 = Infinity; let y0 = Infinity; let x1 = -Infinity; let y1 = -Infinity;
        const recorrer = (c) => {
            if (typeof c[0] === 'number') {
                x0 = Math.min(x0, c[0]); x1 = Math.max(x1, c[0]); y0 = Math.min(y0, c[1]); y1 = Math.max(y1, c[1]);
            } else {
                c.forEach(recorrer);
            }
        };
        rasgos.dep.forEach((f) => recorrer(f.geometry.coordinates));
        const pad = angosto ? 14 : 28;
        const s = Math.min((W - 2 * pad) / ((x1 - x0) * COS), (H - 2 * pad - arriba - abajo) / (y1 - y0));
        const ox = (W - (x1 - x0) * COS * s) / 2 - x0 * COS * s;
        const oy = (H - arriba - abajo - (y1 - y0) * s) / 2 + arriba + y1 * s;
        path = d3.geoPath(d3.geoTransform({ point(x, y) { this.stream.point(ox + x * COS * s, oy - y * s); } }));
    };

    const RAMPA = { ventas: ['--q1', '--q2', '--q3', '--q4', '--q5'], falta: ['--f1', '--f2', '--f3', '--f4', '--f5'] };
    // Ventas: por quintiles (pocas zonas venden mucho). Dónde falta: son pocas salas por zona,
    // así que cada tono es un tramo de números enteros hasta el máximo, y la leyenda dice
    // cuáles van en cada uno.
    const calcularCortes = () => {
        const conSalas = Object.values(NIVELES[st.nivel]).filter((z) => z.salas > 0);
        maxFalta = Math.max(1, ...conSalas.map((z) => z.faltan));
        const v = conSalas.map((z) => z.ventas).filter((x) => x > 0).sort(d3.ascending);
        cortes = v.length ? [0.2, 0.4, 0.6, 0.8].map((x) => d3.quantile(v, x)) : [1, 2, 3, 4];
    };
    const tonoFalta = (n) => Math.min(4, Math.max(0, Math.ceil((n / maxFalta) * 5) - 1));
    const color = (z) => {
        if (st.modo === 'rutas') {
            if (!z.rutas.length) {
                return 'url(#mapa-rayado)';
            }
            if (D.ruta) {
                return z.rutas.includes(D.ruta) ? 'var(--r-on)' : 'var(--mv-vacia)';
            }
            return 'var(--r-cover)';
        }
        if (!z.salas) {
            return 'url(#mapa-rayado)';
        }
        if (st.modo === 'falta') {
            return z.faltan === 0 ? 'var(--mv-vacia)' : 'var(' + RAMPA.falta[tonoFalta(z.faltan)] + ')';
        }
        return 'var(' + RAMPA.ventas[Math.min(d3.bisectRight(cortes, z.ventas), 4)] + ')';
    };
    const sinSalasCorto = (z) => (z.todas ? 'Ninguna sala de esta zona; ' + z.todas + ' de otras zonas' : 'Todavía no hay salas aquí');
    const textoTip = (z) => '<b>' + esc(z.nombre) + '</b>' + (st.nivel === 'dis' ? ' · ' + esc(nombreDep(z.dep)) : '')
        + '<br>' + (z.salas ? plata(z.ventas) + ' en el mes<br>' + z.salas + (z.salas === 1 ? ' sala' : ' salas') + ' · ' + atrasadasTexto(z) : sinSalasCorto(z))
        + '<br>Zona: ' + esc(rutasDe(z).join(', ') || 'ninguna');

    const dibujar = () => {
        if (!hayD3 || !rasgos.dep.length) {
            return;
        }
        proyectar();
        calcularCortes();
        const lista = rasgos[st.nivel];
        const enDep = st.nivel === 'dep';
        gZ.selectAll('path').data(lista, (f) => f.properties.c).join('path')
            .attr('class', (f) => 'mv-zona' + (f.properties.c === st.sel ? ' mv-sel' : ''))
            .attr('d', path)
            .style('fill', (f) => color(zona(f.properties.c)))
            .attr('tabindex', enDep ? 0 : null)
            .attr('role', enDep ? 'button' : null)
            .attr('aria-label', (f) => {
                const z = zona(f.properties.c);
                return z.nombre + ': ' + plata(z.ventas) + ', ' + z.salas + ' salas, ' + atrasadasTexto(z);
            })
            .on('mousemove', (ev, f) => mostrarTip(ev, textoTip(zona(f.properties.c))))
            .on('mouseleave', ocultarTip)
            .on('click', (ev, f) => elegir(f.properties.c))
            .on('keydown', (ev, f) => {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    elegir(f.properties.c);
                }
            });
        // La zona elegida, encima de sus vecinas para que se vea su borde entero.
        gZ.selectAll('path.mv-sel').raise();
        gD.selectAll('path').data(enDep ? [] : rasgos.dep).join('path').attr('class', 'mv-limite').attr('d', path);
        gL.selectAll('text').data(enDep ? rasgos.dep : []).join('text').attr('class', 'mv-etiqueta')
            .attr('x', (f) => path.centroid(f)[0]).attr('y', (f) => path.centroid(f)[1] + 4)
            .text((f) => f.properties.n);
        tamanoEtiquetas(d3.zoomTransform(svg.node()).k);
        leyenda();
    };

    // Cada color del dibujo tiene su renglón en la leyenda.
    const muestra = (fondo, texto) => '<div class="mv-muestra"><i style="background:' + fondo + '"></i>' + texto + '</div>';
    const leyenda = () => {
        const L = $('mapa-leyenda');
        const rayado = '<div class="mv-muestra"><i class="mv-rayado"></i>'
            + (st.modo === 'rutas' ? 'Ninguna zona lo cubre' : D.ruta ? 'Sin salas de esta zona' : 'Sin salas todavía') + '</div>';
        if (st.modo === 'rutas') {
            L.innerHTML = '<div class="text-xs font-semibold">' + esc(unaRuta || 'Qué cubre cada zona') + '</div>'
                + (unaRuta ? muestra('var(--r-on)', 'Lo que cubre esta zona') + muestra('var(--mv-vacia)', 'Otras zonas') : muestra('var(--r-cover)', 'Lo cubre alguna zona'))
                + rayado;
            return;
        }
        if (st.modo === 'falta') {
            // Solo los tonos que se usan, cada uno con los números que pinta: «1», «3–4».
            const tramos = [];
            for (let n = 1; n <= maxFalta; n++) {
                const t = tonoFalta(n);
                tramos[t] = tramos[t] ? [tramos[t][0], n] : [n, n];
            }
            L.innerHTML = '<div class="text-xs font-semibold">Salas atrasadas o que les toca' + esc(alCorte) + '</div>'
                + '<div class="mv-clases text-gray-500">' + tramos.map((r, t) => (r ? '<span><i style="background:var(' + RAMPA.falta[t] + ')"></i>' + (r[0] === r[1] ? r[0] : r[0] + '–' + r[1]) + '</span>' : '')).join('') + '</div>'
                + muestra('var(--mv-vacia)', 'Ninguna atrasada ni le toca')
                + rayado;
            return;
        }
        // Ventas: las cifras van justo en el borde entre los tonos que separan (40 % y 80 %).
        L.innerHTML = '<div class="text-xs font-semibold">Vendido en el mes</div>'
            + '<div class="mv-rampa">' + RAMPA.ventas.map((v) => '<span style="background:var(' + v + ')"></span>').join('') + '</div>'
            + '<div class="mv-rampa-txt text-gray-500"><span style="left:0">menos</span><span style="left:40%;transform:translateX(-50%)">' + corto(cortes[1])
            + '</span><span style="left:80%;transform:translateX(-50%)">' + corto(cortes[3]) + '</span><span style="right:0">más</span></div>'
            + rayado;
    };

    const titulos = () => {
        const n = st.nivel === 'dep' ? 'departamento' : 'distrito';
        $('mapa-titulo').textContent = { ventas: 'Ventas del mes por ' + n, falta: 'Dónde falta, por ' + n, rutas: unaRuta ? 'Lo que cubre ' + unaRuta : 'Qué cubre cada zona' }[st.modo];
        $('mapa-sub').textContent = {
            ventas: 'Color más intenso, más vendido. Tocá un lugar para ver su ficha.',
            falta: 'Color más intenso, más salas atrasadas o que les toca visita según cada cuánto compra cada una' + alCorte + '. Las rayadas no tienen salas ' + (D.ruta ? 'de esta zona.' : 'todavía.'),
            rutas: 'Elegí una zona arriba o en la ficha para ver qué cubre.',
        }[st.modo];
    };

    // ---------- Ficha de la zona ----------
    const enlaceRuta = (id) => {
        const p = new URLSearchParams({ mes: D.mes });
        if (D.ruta !== id) {
            p.set('ruta', id);
        }
        return '?' + p.toString();
    };
    const sinSalasLargo = (z) => {
        if (z.todas) {
            return esc(unaRuta || 'Esta zona') + ' no tiene salas en este lugar. Hay ' + z.todas + (z.todas === 1 ? ' sala' : ' salas') + ' de otras zonas.';
        }
        if (z.ventas || z.anterior) {
            return 'Ya no quedan salas activas en este lugar.';
        }
        return 'Todavía no vendemos en este lugar. ' + (z.rutas.length ? 'Una zona ya lo cubre: se podría visitar.' : 'Ninguna zona lo cubre.');
    };
    const fichaHtml = (z) => {
        const pct = cambio(z);
        const delta = pct === null ? ''
            : '<span class="rounded-full px-2 py-0.5 text-xs font-semibold ' + (pct >= 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700') + '">'
                + (pct >= 0 ? '▲ ' : '▼ ') + Math.abs(pct).toFixed(0) + ' %</span><span class="text-xs text-gray-500">' + esc(D.contra) + '</span>';
        const rutas = z.rutas.length
            ? z.rutas.map((id) => '<a href="' + esc(enlaceRuta(id)) + '" data-con-hash class="inline-flex min-h-11 items-center rounded-full px-4 text-sm font-medium '
                + (D.ruta === id ? 'bg-indigo-600 text-white' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50') + '"'
                + (D.ruta === id ? ' aria-current="true" title="Ver todas las zonas"' : '') + '>' + esc(nombreRuta.get(id)) + '</a>').join('')
            : '<span class="text-sm text-gray-500">Ninguna zona lo cubre.</span>';
        const dato = (valorHtml, texto) => '<div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><span class="block text-lg font-semibold tabular-nums text-gray-800">'
            + valorHtml + '</span><span class="block text-xs text-gray-500">' + texto + '</span></div>';
        // En un mes pasado, la fecha de la entrega: «hoy» o «ayer» serían del cierre del mes.
        const entrega = z.entrega ? '<time datetime="' + esc(z.entrega) + '" title="' + fecha(z.entrega) + '">' + (D.corte ? fecha(z.entrega) : hace(z.entregaDias)) + '</time>' : '—';
        const salas = z.porVisitar.length
            ? '<div><h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Salas que toca visitar' + esc(D.corte ? ' (al ' + D.corte + ')' : '') + '</h4><ul class="mt-1 divide-y divide-gray-100">'
                + z.porVisitar.map((s) => {
                    const [c, t] = estado(s);
                    return '<li class="flex items-center justify-between gap-3 py-2 text-sm"><span class="min-w-0 text-gray-800">' + esc(s.nombre) + '</span>'
                        + '<span class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-gray-700"><span class="mv-punto" style="background:' + c + '"></span>' + t + '</span></li>';
                }).join('') + '</ul></div>'
            : '';
        // Con plata del mes o del anterior se muestran los números, aunque ya no queden salas.
        const numeros = z.salas || z.ventas || z.anterior;
        return '<div class="space-y-4">'
            + '<div><p class="text-xs font-semibold uppercase tracking-wide text-gray-500">' + (st.nivel === 'dep' ? 'Departamento' : 'Distrito') + '</p>'
            + '<h3 class="text-xl font-semibold text-gray-800">' + esc(z.nombre) + '</h3>'
            + (st.nivel === 'dis' ? '<p class="text-sm text-gray-500">' + esc(nombreDep(z.dep)) + '</p>' : '') + '</div>'
            + (numeros
                ? '<div class="flex flex-wrap items-baseline gap-x-3 gap-y-1"><span class="text-3xl font-bold tabular-nums text-gray-800">' + plata(z.ventas) + '</span>' + delta + '</div>'
                    + '<div class="grid grid-cols-2 gap-3">'
                    + dato(z.salas, z.salas === 1 ? 'sala' : 'salas')
                    + dato(z.atrasadas, (z.atrasadas === 1 ? 'atrasada' : 'atrasadas') + ', a ' + z.leToca + (z.leToca === 1 ? ' le toca' : ' les toca') + esc(alCorte))
                    + dato(entrega, 'última entrega' + esc(alCorte))
                    + dato(z.compraron ? plata(z.ventas / z.compraron) : '—', 'promedio por sala que compró')
                    + '</div>'
                : '')
            + (z.salas ? '' : '<p class="text-sm text-gray-600">' + sinSalasLargo(z) + '</p>')
            + '<div><h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Zonas</h4><div class="mt-1 flex flex-wrap gap-2">' + rutas + '</div></div>'
            + salas
            + '</div>';
    };
    // La ficha es una región viva: solo se reescribe si cambió, para que el lector de
    // pantalla no la repita al cambiar de modo.
    let fichaActual = '';
    const ficha = () => {
        const z = st.sel ? zona(st.sel) : null;
        const html = z ? fichaHtml(z) : '<p class="text-sm text-gray-500">Tocá un lugar del mapa o de la tabla para ver su ficha.</p>';
        if (html !== fichaActual) {
            $('mapa-ficha').innerHTML = html;
            fichaActual = html;
        }
    };

    // ---------- Tabla y «Dónde falta»: se muestra el nivel elegido ----------
    const listas = () => {
        document.querySelectorAll('[data-nivel-lista]').forEach((el) => {
            el.hidden = el.dataset.nivelLista !== st.nivel;
        });
        document.querySelectorAll('[data-nivel-texto]').forEach((el) => {
            el.hidden = el.dataset.nivelTexto !== st.nivel;
        });
        document.querySelectorAll('tr[data-zona]').forEach((tr) => {
            if (tr.dataset.zona === st.sel && !tr.closest('[hidden]')) {
                tr.setAttribute('aria-current', 'true');
            } else {
                tr.removeAttribute('aria-current');
            }
        });
    };

    function actualizar() {
        if (!st.sel || (cargadas && !zona(st.sel))) {
            st.sel = porDefecto();
        }
        marcar('data-modo', st.modo);
        marcar('data-nivel', st.nivel);
        guardarHash();
        titulos();
        dibujar();
        ficha();
        listas();
    }

    // ---------- Carga de los límites ----------
    const carga = $('mapa-carga');
    const pedir = (url) => fetch(url, { credentials: 'same-origin' }).then((r) => {
        if (!r.ok) {
            throw new Error('HTTP ' + r.status);
        }
        return r.json();
    });
    actualizar();
    if (!hayD3) {
        carga.textContent = 'No se pudo cargar el dibujo del mapa. La tabla de abajo tiene los mismos datos.';
        return;
    }
    Promise.all([pedir(D.geo.departamentos), pedir(D.geo.distritos)]).then(([dep, dis]) => {
        rasgos = { dep: dep.features, dis: dis.features };
        cargadas = true;
        carga.remove();
        actualizar();
        let espera;
        window.addEventListener('resize', () => {
            clearTimeout(espera);
            espera = setTimeout(dibujar, 150);
        });
    }).catch(() => {
        cargadas = true;
        actualizar();
        carga.textContent = 'No se pudo cargar el dibujo del mapa. La tabla de abajo tiene los mismos datos.';
    });
})();
