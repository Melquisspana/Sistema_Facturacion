<script>
(() => {
    const workerPath = '/sw-contingencia.js';
    const cleanup = async () => {
        if ('serviceWorker' in navigator) {
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(registrations.filter((registration) =>
                [registration.installing, registration.waiting, registration.active].some((worker) =>
                    worker && new URL(worker.scriptURL).pathname === workerPath))
                .map((registration) => registration.unregister()));
        }
        if ('caches' in window) {
            const names = await caches.keys();
            await Promise.all(names.filter((name) => name.startsWith('sin-internet-'))
                .map((name) => caches.delete(name)));
        }
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post'
            || !['/logout', '/logout-completo'].includes(new URL(form.action).pathname)) return;
        event.preventDefault();
        try { await cleanup(); } catch (error) { console.warn('No se pudo limpiar el aviso sin internet', error); }
        HTMLFormElement.prototype.submit.call(form);
    }, true);

    @if (auth()->check() && config('dte.contingencia.enabled'))
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(workerPath, { scope: '/' })
                .catch((error) => console.warn('No se pudo registrar el aviso sin internet', error));
        }
    @else
        cleanup().catch((error) => console.warn('No se pudo limpiar el aviso sin internet', error));
    @endif
})();
</script>
