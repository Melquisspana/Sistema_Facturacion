{{-- Aviso de una acción que ya ocurrió (adjuntar, revertir, ajustar). Se enfoca al
     aparecer para que un lector de pantalla lo anuncie sin tener que buscarlo. --}}
@if (session('gastos.aviso'))
    <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status" tabindex="-1" x-init="$el.focus()">
        {{ session('gastos.aviso') }}
    </div>
@endif

@if ($errors->any())
    <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" tabindex="-1" x-init="$el.focus()">
        <p class="font-medium">Revisá estos datos. No se guardó nada.</p>
        <ul class="mt-1 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
