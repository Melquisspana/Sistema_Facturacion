/*
 * Mapa de ventas por zona (Rutas › Tablero › Mapa). Dibuja con d3 7.9.0 (public/vendor/d3)
 * los límites de public/geo y los datos que la vista deja en #mapa-datos. Todo se calcula
 * en el servidor (App\Services\Rutas\MapaVentas); acá solo se cambia de modo (ventas, dónde
 * falta, rutas) y de nivel (departamentos, distritos) sin volver a pedir la página, y se
 * arma la ficha de la zona elegida. El modo, el nivel y la zona quedan en el #hash de la
 * dirección para que un enlace o recargar no los pierda.
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
    const $ = (id) => document.getElementById(id);

    // ---------- Formatos ----------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const plata = (v) => '$' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const corto = (v) => (v >= 1000 ? '$' + (v / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 }) + 'k' : '$' + Math.round(v));
    const fecha = (iso) => (iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '');
    const hace = (n) => (n === 0 ? 'hoy' : n === 1 ? 'ayer' : 'hace ' + n + ' días');
    const rutasDe = (z) => z.rutas.map((id) => nombreRuta.get(id)).filter(Boolean);
    const cambio = (z) => (z.anterior ? ((z.ventas - z.anterior) / z.anterior) * 100 : null);
    const estado = (dias) => {
        if (dias === null) {
            return ['var(--mal)', 'Sin compras'];
        }
        if (dias >= 30) {
            return ['var(--mal)', 'Sin compra ' + dias + ' días'];
        }
        return [dias > 15 ? 'var(--ojo)' : 'var(--bien)', dias === 0 ? 'Compró hoy' : 'Hace ' + dias + (dias === 1 ? ' día' : ' días')];
    };

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
    const nombreDep = (c) => (D.departamentos[c] ? D.departamentos[c].nombre : '');
    const vacia = (f) => ({ nombre: f.properties.n, dep: f.properties.d || f.properties.c, ventas: 0, anterior: 0, salas: 0, compraron: 0, sinCompra: 0, entrega: null, entregaDias: null, rutas: [], peores: [] });
    const zona = (id) => {
        const z = NIVELES[st.nivel][id];
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
    // Las filas de la tabla y la lista «Dónde falta» eligen la zona.
    raiz.addEventListener('click', (ev) => {
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

    // ---------- Mapa ----------
    const hayD3 = typeof window.d3 !== 'undefined';
    let svg, g, gZ, gD, gL, path, cortes = [], zoom;
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
    const valor = (z) => (st.modo === 'ventas' ? z.ventas : z.sinCompra);
    // Ventas: por quintiles (pocas zonas venden mucho). Dónde falta: son pocas salas por zona,
    // así que los cortes van parejos hasta el máximo y 1 y 2 no caen en el mismo tono.
    const calcularCortes = () => {
        const v = Object.values(NIVELES[st.nivel]).filter((z) => z.salas > 0).map(valor).filter((x) => x > 0).sort(d3.ascending);
        const q = [0.2, 0.4, 0.6, 0.8];
        if (!v.length) {
            cortes = [1, 2, 3, 4];
        } else if (st.modo === 'falta') {
            cortes = q.map((x) => x * v[v.length - 1]);
        } else {
            cortes = q.map((x) => d3.quantile(v, x));
        }
    };
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
        if (st.modo === 'falta' && z.sinCompra === 0) {
            return 'var(--mv-vacia)';
        }
        const i = Math.min(d3.bisectRight(cortes, valor(z)), 4);
        return 'var(' + RAMPA[st.modo][i] + ')';
    };
    const textoTip = (z) => '<b>' + esc(z.nombre) + '</b>' + (st.nivel === 'dis' ? ' · ' + esc(nombreDep(z.dep)) : '')
        + '<br>' + (z.salas ? plata(z.ventas) + ' en el mes<br>' + z.salas + (z.salas === 1 ? ' sala' : ' salas') + ' · ' + z.sinCompra + ' sin compra 30+ días' : 'Todavía no hay salas aquí')
        + '<br>Ruta: ' + esc(rutasDe(z).join(', ') || 'ninguna');

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
                return z.nombre + ': ' + plata(z.ventas) + ', ' + z.salas + ' salas, ' + z.sinCompra + ' sin compra 30 días o más';
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

    const leyenda = () => {
        const L = $('mapa-leyenda');
        const rayado = '<div class="mv-muestra"><i class="mv-rayado"></i>' + (st.modo === 'rutas' ? 'Ninguna ruta pasa' : 'Sin salas todavía') + '</div>';
        if (st.modo === 'rutas') {
            const una = D.ruta ? nombreRuta.get(D.ruta) : null;
            L.innerHTML = '<div class="text-xs font-semibold">' + esc(una || 'Por dónde pasan las rutas') + '</div>'
                + '<div class="mv-muestra"><i style="background:' + (una ? 'var(--r-on)' : 'var(--r-cover)') + '"></i>' + (una ? 'Zonas de esta ruta' : 'Alguna ruta pasa') + '</div>' + rayado;
            return;
        }
        const f = st.modo === 'ventas' ? corto : (v) => String(Math.round(v));
        L.innerHTML = '<div class="text-xs font-semibold">' + (st.modo === 'ventas' ? 'Vendido en el mes' : 'Salas sin compra 30+ días') + '</div>'
            + '<div class="mv-rampa">' + RAMPA[st.modo].map((v) => '<span style="background:var(' + v + ')"></span>').join('') + '</div>'
            + '<div class="mv-rampa-txt text-gray-500"><span>menos</span><span>' + f(cortes[1]) + '</span><span>' + f(cortes[3]) + '</span><span>más</span></div>'
            + rayado;
    };

    const titulos = () => {
        const n = st.nivel === 'dep' ? 'departamento' : 'distrito';
        const una = D.ruta ? nombreRuta.get(D.ruta) : null;
        $('mapa-titulo').textContent = { ventas: 'Ventas del mes por ' + n, falta: 'Dónde falta, por ' + n, rutas: una ? 'Zonas de ' + una : 'Por dónde pasan las rutas' }[st.modo];
        $('mapa-sub').textContent = {
            ventas: 'Color más intenso, más vendido. Tocá una zona para ver su ficha.',
            falta: 'Color más intenso, más salas sin compra hace 30 días o más. Las rayadas no tienen salas todavía.',
            rutas: 'Elegí una ruta arriba o en la ficha para ver sus zonas.',
        }[st.modo];
    };

    // ---------- Ficha de la zona ----------
    const enlaceRuta = (id) => {
        const p = new URLSearchParams({ mes: D.mes });
        if (D.ruta !== id) {
            p.set('ruta', id);
        }
        return '?' + p.toString() + location.hash;
    };
    const ficha = () => {
        const el = $('mapa-ficha');
        const z = st.sel ? zona(st.sel) : null;
        if (!z) {
            el.innerHTML = '<p class="text-sm text-gray-500">Tocá una zona del mapa o de la tabla para ver su ficha.</p>';
            return;
        }
        const pct = cambio(z);
        const delta = pct === null ? ''
            : '<span class="rounded-full px-2 py-0.5 text-xs font-semibold ' + (pct >= 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700') + '">'
                + (pct >= 0 ? '▲ ' : '▼ ') + Math.abs(pct).toFixed(0) + ' %</span>';
        const rutas = z.rutas.length
            ? z.rutas.map((id) => '<a href="' + esc(enlaceRuta(id)) + '" class="inline-flex min-h-11 items-center rounded-full px-4 text-sm font-medium '
                + (D.ruta === id ? 'bg-indigo-600 text-white' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50') + '"'
                + (D.ruta === id ? ' aria-current="true" title="Ver todas las rutas"' : '') + '>' + esc(nombreRuta.get(id)) + '</a>').join('')
            : '<span class="text-sm text-gray-500">Ninguna ruta pasa por aquí.</span>';
        const dato = (valorHtml, texto) => '<div class="rounded-lg border border-gray-200 bg-gray-50 p-3"><span class="block text-lg font-semibold tabular-nums text-gray-800">'
            + valorHtml + '</span><span class="block text-xs text-gray-500">' + texto + '</span></div>';
        const entrega = z.entrega ? '<time datetime="' + esc(z.entrega) + '" title="' + fecha(z.entrega) + '">' + hace(z.entregaDias) + '</time>' : '—';
        const salas = z.peores.length
            ? '<div><h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Salas que más llevan sin comprar</h4><ul class="mt-1 divide-y divide-gray-100">'
                + z.peores.map((s) => {
                    const [c, t] = estado(s.dias);
                    return '<li class="flex items-center justify-between gap-3 py-2 text-sm"><span class="min-w-0 text-gray-800">' + esc(s.nombre) + '</span>'
                        + '<span class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-gray-700"><span class="mv-punto" style="background:' + c + '"></span>' + t + '</span></li>';
                }).join('') + '</ul></div>'
            : '';
        el.innerHTML = '<div class="space-y-4">'
            + '<div><p class="text-xs font-semibold uppercase tracking-wide text-gray-500">' + (st.nivel === 'dep' ? 'Departamento' : 'Distrito') + '</p>'
            + '<h3 class="text-xl font-semibold text-gray-800">' + esc(z.nombre) + '</h3>'
            + (st.nivel === 'dis' ? '<p class="text-sm text-gray-500">' + esc(nombreDep(z.dep)) + '</p>' : '') + '</div>'
            + (z.salas
                ? '<div class="flex flex-wrap items-baseline gap-3"><span class="text-3xl font-bold tabular-nums text-gray-800">' + plata(z.ventas) + '</span>' + delta + '</div>'
                    + '<div class="grid grid-cols-2 gap-3">'
                    + dato(z.salas, z.salas === 1 ? 'sala' : 'salas')
                    + dato(z.sinCompra, 'sin compra 30+ días')
                    + dato(entrega, 'última entrega')
                    + dato(z.compraron ? plata(z.ventas / z.compraron) : '—', 'promedio por sala que compró')
                    + '</div>'
                : '<p class="text-sm text-gray-600">Todavía no vendemos en esta zona. ' + (z.rutas.length ? 'Una ruta ya pasa por aquí: se podría visitar.' : 'Ninguna ruta pasa por aquí.') + '</p>')
            + '<div><h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Rutas</h4><div class="mt-1 flex flex-wrap gap-2">' + rutas + '</div></div>'
            + salas
            + '</div>';
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
        if (!st.sel || !zona(st.sel)) {
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
        carga.remove();
        actualizar();
        let espera;
        window.addEventListener('resize', () => {
            clearTimeout(espera);
            espera = setTimeout(dibujar, 150);
        });
    }).catch(() => {
        carga.textContent = 'No se pudo cargar el dibujo del mapa. La tabla de abajo tiene los mismos datos.';
    });
})();
