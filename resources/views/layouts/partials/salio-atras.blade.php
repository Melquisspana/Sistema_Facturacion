{{-- «Atrás» después de «Salir» (13/11/2026). Al salir, el navegador anota la hora en
     localStorage ('dlg-salio'), que ven todas las pestañas: lo hacen el cierre de
     layouts/partials/sw-contingencia y el del layout del vendedor. Cada pantalla guarda en su
     entrada del historial la hora en que se cargó. Si «atrás» trae una pantalla cargada antes
     de la última salida (desde la memoria del navegador o desde su copia guardada), se pide de
     nuevo antes de mostrarla y el servidor decide (sin sesión, al login). Va en el <head> para
     que no alcance a dibujarse nada. Reemplaza el `Clear-Site-Data: "cache"` del cierre, que
     tardaba segundos en el celular. --}}
<script>
(() => {
    const salida = () => { try { return Number(localStorage.getItem('dlg-salio')) || 0; } catch (error) { return Infinity; } };
    const cargada = () => (history.state && history.state.dlgCargada) || 0;
    const vieja = () => { const hora = salida(); return hora > 0 && cargada() < hora; };
    const recargar = () => { document.documentElement.style.visibility = 'hidden'; location.reload(); };
    const navegacion = performance.getEntriesByType('navigation')[0];
    if (navegacion && navegacion.type === 'back_forward' && vieja()) {
        recargar();
    } else if (! navegacion || navegacion.type !== 'back_forward') {
        try { history.replaceState(Object.assign({}, history.state, { dlgCargada: Date.now() }), ''); } catch (error) {}
    }
    window.addEventListener('pageshow', (event) => { if (event.persisted && vieja()) recargar(); });
})();
</script>
