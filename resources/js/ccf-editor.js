// Editor rápido de borrador (CCF / Factura / Exportación) tipo "carrito": agregar,
// actualizar, quitar y escanear productos SIN recargar la página. Mejora progresiva
// sobre las rutas existentes: si el JS falla, los formularios hacen POST normal
// (fallback). NO cambia lógica fiscal ni validaciones (el servidor re-valida siempre).
//
// Se activa solo en la pantalla de edición (cuando existe #resumen-panel); en el resto
// de las páginas es un no-op.

function initCcfEditor() {
    const panel = document.getElementById('resumen-panel');
    if (!panel) return; // documento no editable / otra página -> sin AJAX

    const flash = document.getElementById('ccf-flash');
    const scanner = document.getElementById('escanear-barra');
    let flashTimer = null;

    // Secuencia de acciones para descartar respuestas que llegan FUERA DE ORDEN: si una
    // respuesta vieja llega después de aplicar una más nueva, no debe repintar el carrito
    // ni los totales (evita que reaparezca un estado viejo).
    let secuencia = 0;
    let ultimaAplicada = 0;

    // Clases de estado como constantes (evita literales sueltos y facilita el mantenimiento).
    const RING = ['ring-1', 'ring-indigo-300', 'bg-indigo-50/50'];
    const BTN_ON = ['bg-indigo-600', 'hover:bg-indigo-700'];
    const BTN_OFF = ['bg-gray-600', 'hover:bg-gray-700'];
    const GEN_OFF = ['bg-gray-300', 'cursor-not-allowed'];
    const GEN_ON = ['bg-green-600', 'hover:bg-green-700'];

    function showFlash(msg, ok) {
        if (!flash || !msg) return;
        flash.textContent = msg;
        flash.className = 'rounded-md border p-3 text-sm ' + (ok
            ? 'bg-green-50 border-green-200 text-green-700'
            : 'bg-red-50 border-red-200 text-red-700');
        clearTimeout(flashTimer);
        if (ok) flashTimer = setTimeout(() => { flash.className = 'hidden rounded-md border p-3 text-sm'; }, 3500);
    }

    function setBusy(botones, busy, label) {
        botones.forEach((b) => {
            if (busy) {
                if (b.dataset.txt === undefined) b.dataset.txt = b.textContent;
                if (label) b.textContent = label;
                b.disabled = true;
            } else {
                if (b.dataset.txt !== undefined) { b.textContent = b.dataset.txt; delete b.dataset.txt; }
                b.disabled = false;
            }
        });
    }

    // Sincroniza los inputs de cantidad del catálogo (panel izquierdo) con el estado real,
    // sin pisar el input que el usuario está editando.
    function syncCatalogo(cantidades) {
        document.querySelectorAll('form[data-ajax="cantidad"]').forEach((form) => {
            const pid = form.dataset.producto;
            const input = form.querySelector('input[name="cantidad"]');
            const btn = form.querySelector('button');
            const qty = cantidades && cantidades[pid] ? cantidades[pid] : null;
            if (input && document.activeElement !== input) {
                input.value = qty !== null ? qty : '';
                RING.forEach((c) => input.classList.toggle(c, !!qty));
            }
            if (btn) {
                btn.textContent = qty ? 'Actualizar' : 'Agregar';
                BTN_OFF.forEach((c) => btn.classList.toggle(c, !!qty));
                BTN_ON.forEach((c) => btn.classList.toggle(c, !qty));
            }
        });
    }

    // Siguiente input de cantidad VISIBLE (no oculto por el filtro) después del form dado.
    // Devuelve null si no hay ninguno más abajo (no se vuelve arriba ni al buscador).
    function siguienteCantidadVisible(form) {
        const inputs = Array.from(document.querySelectorAll('form[data-ajax="cantidad"] input[name="cantidad"]'));
        const actual = form.querySelector('input[name="cantidad"]');
        for (let i = inputs.indexOf(actual) + 1; i < inputs.length; i++) {
            // offsetParent === null cuando el <tr> está oculto por el filtro (display:none).
            if (inputs[i].offsetParent !== null && !inputs[i].disabled) return inputs[i];
        }
        return null;
    }

    function syncGenerar(sinLineas) {
        document.querySelectorAll('[data-generar-btn]').forEach((b) => {
            b.disabled = !!sinLineas;
            GEN_OFF.forEach((c) => b.classList.toggle(c, !!sinLineas));
            GEN_ON.forEach((c) => b.classList.toggle(c, !sinLineas));
        });
    }

    // --- Carrito: auto-guardar la cantidad al cambiarla (ya no hace falta "Actualizar") ---
    const DEBOUNCE_MS = 500;

    function esInputCarrito(el) {
        return el instanceof HTMLInputElement
            && el.matches('form[data-ajax="update"] input[name="cantidad"]');
    }

    // Programa el guardado con debounce. NO envía si la cantidad es 0/vacía o no cambió
    // (para quitar está el botón Eliminar, separado). Reusa el submit -> fetch de siempre.
    function programarGuardadoCarrito(input) {
        clearTimeout(input._t);
        const v = input.value.trim();
        if (v === '' || Number(v) < 1 || v === input.defaultValue) return;
        input._t = setTimeout(() => {
            if (input.isConnected && input.form) input.form.requestSubmit();
        }, DEBOUNCE_MS);
    }

    // Escribir o usar las flechitas del input number: guardar con debounce. El debounce
    // coalesce varios clics/teclas en una sola petición y también dispara aunque el usuario
    // salga del input (no depende del blur). Enter hace submit nativo (guardado inmediato).
    document.addEventListener('input', function (e) {
        if (esInputCarrito(e.target)) programarGuardadoCarrito(e.target);
    });

    // Al salir del input, si quedó vacío o en 0 se revierte a lo último guardado (no se
    // auto-elimina: para quitar está el botón Eliminar, separado).
    document.addEventListener('change', function (e) {
        if (!esInputCarrito(e.target)) return;
        const input = e.target;
        const v = input.value.trim();
        if (v === '' || Number(v) < 1) { clearTimeout(input._t); input.value = input.defaultValue; }
    });

    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const tipo = form.dataset.ajax;
        if (!tipo) return;              // Generar u otros: submit normal (navegación completa)
        if (e.defaultPrevented) return; // p.ej. el confirm de "Eliminar" fue cancelado

        e.preventDefault();

        // Si el guardado del carrito ya venía con debounce pendiente, lo cancelamos (este
        // submit ya lo cubre): evita una petición duplicada.
        if (tipo === 'update') {
            const inp = form.querySelector('input[name="cantidad"]');
            if (inp) clearTimeout(inp._t);
        }
        // Al ELIMINAR una línea, cancelar cualquier debounce pendiente de esa misma línea
        // (su input está en el mismo <li>): así una actualización tardía no la "revive".
        if (tipo === 'destroy') {
            const li = form.closest('li');
            const inp = li && li.querySelector('input[name="cantidad"]');
            if (inp) clearTimeout(inp._t);
        }

        const miSeq = ++secuencia; // número de esta acción (para descartar respuestas viejas)
        const submitter = e.submitter || null;
        const botones = submitter ? [submitter]
            : Array.from(form.querySelectorAll('button[type="submit"], button:not([type])'));
        const esActualizar = (submitter && /actualizar/i.test(submitter.textContent));
        const label = { scanner: 'Escaneando…', cantidad: esActualizar ? 'Actualizando…' : 'Agregando…',
            update: 'Actualizando…', destroy: 'Eliminando…' }[tipo] || 'Procesando…';
        setBusy(botones, true, label);

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(async (resp) => {
            // Foco al llegar la respuesta: si el usuario se movió a otro campo durante la
            // petición, NO se lo robamos (se respeta lo que está haciendo ahora).
            const focoPrevio = document.activeElement;
            // ¿Seguía el usuario en el input de ESTA línea del carrito? Solo entonces le
            // devolvemos el foco tras re-renderizar (si se movió a otra línea/campo, no).
            const inputEditado = (tipo === 'update') ? form.querySelector('input[name="cantidad"]') : null;
            const editabaEstaLinea = inputEditado && focoPrevio === inputEditado;
            const lineaInputId = inputEditado ? inputEditado.id : null;
            let data = {};
            try { data = await resp.json(); } catch (_) { /* respuesta no-JSON */ }
            // Descartar respuestas fuera de orden: si ya aplicamos una acción más nueva, esta
            // vieja no repinta nada (ni carrito, ni totales, ni catálogo, ni mensajes).
            if (miSeq < ultimaAplicada) return;
            ultimaAplicada = miSeq;
            if (resp.ok && data.ok) {
                // El servidor manda el carrito/totales ya calculados y el mapa producto_id =>
                // cantidad. Se aplica TODO de una: no queda nada "una acción atrás".
                if (typeof data.resumen_html === 'string') panel.innerHTML = data.resumen_html;
                syncCatalogo(data.cantidades || {});
                syncGenerar(data.sin_lineas);
                showFlash(data.message, true);
                // Foco por origen:
                if (tipo === 'scanner' && scanner) {
                    // Escáner: limpiar y volver al escáner, listo para el siguiente código.
                    scanner.value = '';
                    scanner.focus();
                } else if (tipo === 'cantidad') {
                    // Catálogo (opción preferida): el input del producto recién agregado ya
                    // muestra su cantidad real (lo pintó syncCatalogo), sin desfase. Movemos el
                    // foco al SIGUIENTE input visible SOLO si el usuario no se movió él mismo a
                    // otro campo mientras se procesaba. Nunca volvemos al input anterior ni al
                    // buscador; nunca se escribe por accidente en el producto recién agregado.
                    const usado = form.querySelector('input[name="cantidad"]');
                    const usuarioSeMovio = focoPrevio && focoPrevio !== usado && focoPrevio !== document.body
                        && ['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(focoPrevio.tagName);
                    if (!usuarioSeMovio) {
                        const siguiente = siguienteCantidadVisible(form);
                        if (siguiente) { siguiente.focus(); siguiente.select(); }
                    }
                } else if (tipo === 'update' && editabaEstaLinea && lineaInputId) {
                    // Actualización del carrito: el panel se re-renderizó, así que devolvemos el
                    // foco a la MISMA línea (no te movemos a otro input) para poder seguir
                    // ajustando la cantidad. Si te habías ido a otro lado, no te lo robamos.
                    const nuevo = document.getElementById(lineaInputId);
                    if (nuevo) { nuevo.focus(); try { nuevo.select(); } catch (_) { /* number input */ } }
                }
            } else {
                const msg = (data && data.message) || 'No se pudo completar la acción. Revisá los datos e intentá de nuevo.';
                showFlash(msg, false);
                if (tipo === 'scanner' && scanner) { scanner.focus(); scanner.select(); }
                else if (tipo === 'update') {
                    // No perder la cantidad anterior: revertir el input a lo último guardado.
                    const inp = form.querySelector('input[name="cantidad"]');
                    if (inp) inp.value = inp.defaultValue;
                }
            }
        }).catch(() => {
            showFlash('Error de conexión: no se aplicó el cambio. Volvé a intentar.', false);
        }).finally(() => {
            setBusy(botones, false);
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCcfEditor);
} else {
    initCcfEditor();
}
