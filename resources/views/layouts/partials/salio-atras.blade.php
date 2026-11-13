{{-- «Atrás» después de «Salir» (13/11/2026). Al salir, la pestaña anota que salió (lo hace el
     cierre de sesión de layouts/partials/sw-contingencia). Si después «atrás» trae una pantalla
     de antes, desde la memoria del navegador o desde su copia guardada, se pide de nuevo antes de
     mostrarla y el servidor manda al login: sin sesión no se ven datos. Al entrar otra vez se
     olvida. Va en el <head> para que no alcance a dibujarse nada. Reemplaza el
     `Clear-Site-Data: "cache"` del cierre, que tardaba segundos en el celular. --}}
<script>
(() => {
    const salio = () => { try { return sessionStorage.getItem('dlg-salio') !== null; } catch (error) { return true; } };
    const recargar = () => { document.documentElement.style.visibility = 'hidden'; location.reload(); };
    const navegacion = performance.getEntriesByType('navigation')[0];
    if (navegacion && navegacion.type === 'back_forward' && salio()) {
        recargar();
    } else {
        @auth
            try { sessionStorage.removeItem('dlg-salio'); } catch (error) {}
        @endauth
    }
    window.addEventListener('pageshow', (event) => { if (event.persisted && salio()) recargar(); });
})();
</script>
