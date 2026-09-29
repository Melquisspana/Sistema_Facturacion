@props(['ruta'])
{{-- Buscador instantáneo de salas para agregar a una ruta. Se escribe y aparecen, por
     nombre, código, cliente o pueblo; cada una con su «Agregar». Avisa si ya está en esta
     ruta o en otra (agregarla la mueve). Agregar es el mismo POST de siempre
     (rutas.rutas.salas.store), así que vuelve a la página con su mensaje. --}}
<div class="js-buscador-salas" data-buscar="{{ route('rutas.salas.buscar') }}" data-ruta="{{ $ruta->id }}">
    <input type="search" autocomplete="off" placeholder="Buscar sala por nombre, cliente o pueblo…"
           aria-label="Buscar sala para agregar a {{ $ruta->nombre }}"
           class="js-q w-full rounded-lg border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
    <p class="js-estado mt-1 text-xs text-gray-400 dark:text-paper-500">Escribí al menos 2 letras.</p>
    <div class="js-resultados mt-1 divide-y divide-gray-100 dark:divide-ink-700"></div>

    <form method="POST" action="{{ route('rutas.rutas.salas.store', $ruta) }}" class="js-agregar hidden">
        @csrf
        <input type="hidden" name="sucursales[]" value="">
    </form>
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.js-buscador-salas').forEach((caja) => {
                const q = caja.querySelector('.js-q');
                const estado = caja.querySelector('.js-estado');
                const lista = caja.querySelector('.js-resultados');
                const form = caja.querySelector('.js-agregar');
                const rutaId = Number(caja.dataset.ruta);
                let espera;

                const fila = (s) => {
                    const div = document.createElement('div');
                    div.className = 'flex items-center justify-between gap-3 py-2';
                    const info = document.createElement('div');
                    info.className = 'min-w-0';
                    const nombre = document.createElement('p');
                    nombre.className = 'truncate text-sm text-gray-800 dark:text-paper-100';
                    nombre.textContent = s.nombre;
                    const detalle = document.createElement('p');
                    detalle.className = 'truncate text-xs text-gray-400 dark:text-paper-500';
                    detalle.textContent = s.detalle + (s.ruta && s.ruta_id !== rutaId ? ' · hoy en ' + s.ruta : '');
                    info.append(nombre, detalle);
                    div.append(info);

                    if (s.ruta_id === rutaId) {
                        const ya = document.createElement('span');
                        ya.className = 'shrink-0 text-xs text-green-700 dark:text-green-400';
                        ya.textContent = 'Ya está';
                        div.append(ya);
                    } else {
                        const boton = document.createElement('button');
                        boton.type = 'button';
                        boton.className = 'shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700';
                        boton.textContent = s.ruta ? 'Mover aquí' : 'Agregar';
                        boton.addEventListener('click', () => {
                            form.querySelector('input[name="sucursales[]"]').value = s.id;
                            form.submit();
                        });
                        div.append(boton);
                    }

                    return div;
                };

                q.addEventListener('input', () => {
                    clearTimeout(espera);
                    const texto = q.value.trim();
                    if (texto.length < 2) {
                        lista.replaceChildren();
                        estado.textContent = 'Escribí al menos 2 letras.';
                        return;
                    }
                    espera = setTimeout(async () => {
                        estado.textContent = 'Buscando…';
                        try {
                            const r = await fetch(caja.dataset.buscar + '?q=' + encodeURIComponent(texto), { headers: { Accept: 'application/json' } });
                            const salas = await r.json();
                            lista.replaceChildren(...salas.map(fila));
                            estado.textContent = salas.length ? '' : 'Ninguna sala coincide con «' + texto + '».';
                        } catch (e) {
                            estado.textContent = 'No se pudo buscar. Probá de nuevo.';
                        }
                    }, 250);
                });
            });
        });
    </script>
@endonce
