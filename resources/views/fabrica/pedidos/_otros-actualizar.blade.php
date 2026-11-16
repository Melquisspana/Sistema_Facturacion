{{-- Solo esta fila cambia; el formulario y su borrador conservan su estado. --}}
<div x-data="{
    ids: @js($otros->where('origen', \App\Enums\OrigenPedidoSala::Web)->pluck('id')->values()),
    intervalo: null, avisoWeb: false, ocultar: null, consultando: false,
    init() { this.intervalo = setInterval(() => this.actualizar(), 30000); },
    destroy() { clearInterval(this.intervalo); clearTimeout(this.ocultar); },
    async actualizar() {
        if (this.enviando || this.saliendo || this.consultando) return;
        this.consultando = true;
        try {
            const respuesta = await fetch(@js(route('fabrica.pedidos.otros', $pedido)), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });
            if ([401, 419].includes(respuesta.status)) { clearInterval(this.intervalo); return; }
            if (!respuesta.ok) return;
            const datos = await respuesta.json();
            if (this.enviando || this.saliendo) return;
            if (datos.ids.some(id => !this.ids.includes(id))) {
                this.avisoWeb = true;
                clearTimeout(this.ocultar);
                this.ocultar = setTimeout(() => this.avisoWeb = false, 8000);
            }
            this.ids = datos.ids;
            this.$refs.otros.innerHTML = datos.html;
        } catch (_) {
            // Sin interrumpir el trabajo: se vuelve a consultar en la próxima vuelta.
        } finally { this.consultando = false; }
    }
}">
    <div x-ref="otros">@include('fabrica.pedidos._otros')</div>
    <p x-show="avisoWeb" x-cloak role="status" aria-live="polite"
       class="mt-3 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700">Llegó un pedido web</p>
</div>