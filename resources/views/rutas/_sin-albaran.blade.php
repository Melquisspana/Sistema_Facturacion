{{-- Aviso del tablero (Resumen y Viajes), 12/11/2026: una sala con albarán que un vendedor
     anotó como entregada y que al día siguiente todavía no tiene su albarán de entrega. El
     albarán debería caer apenas se entrega; si no cae, alguien lo tiene que reclamar. La
     lista la arma EntregasCcf::esperandoAlbaran(): una consulta para las entregas y otra para
     sus albaranes. Parcial aparte para tocar lo mínimo las vistas del tablero. --}}
@inject('entregasCcf', \App\Services\Rutas\EntregasCcf::class)
@php
    $sinAlbaran = $entregasCcf->esperandoAlbaran(\App\Support\HoraNegocio::fechaHoy());
@endphp
@if ($sinAlbaran->isNotEmpty())
    <section aria-labelledby="sin-albaran-titulo" class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
        <h2 id="sin-albaran-titulo" class="text-base font-semibold text-amber-800 dark:text-amber-300">
            {{ $sinAlbaran->count() === 1 ? '1 sala entregada sigue sin albarán' : $sinAlbaran->count().' salas entregadas siguen sin albarán' }}
        </h2>
        <p class="mt-1 text-sm text-gray-700 dark:text-paper-300">El albarán cae apenas se entrega. Si no cayó, hay que reclamarlo.</p>
        <ul class="mt-3 space-y-2">
            @foreach ($sinAlbaran as $entrega)
                <li class="rounded-md bg-white p-3 text-sm ring-1 ring-amber-200 dark:bg-ink-800 dark:ring-ink-600">
                    <p class="font-medium text-gray-800 dark:text-paper-100">{{ $entrega->sala?->nombre ?? 'Sala sin identificar' }}</p>
                    <p class="text-amber-800 dark:text-amber-300">Entregada por {{ $entrega->entregadoPor?->nombre }} el {{ $entrega->fecha_resultado?->format('d/m') }} y todavía sin albarán</p>
                    <p class="text-gray-600 dark:text-paper-300">
                        <span class="font-mono">{{ $entrega->dte?->numero_control }}</span> · ${{ number_format((float) $entrega->dte?->total_pagar, 2) }}
                    </p>
                </li>
            @endforeach
        </ul>
    </section>
@endif
