<section class="mt-6 rounded-2xl bg-green-50 p-5 ring-1 ring-green-300 dark:bg-ink-800 dark:ring-green-600" aria-label="Cierre del día">
    <h2 class="text-xl font-bold text-green-900 dark:text-green-300">Día cerrado</h2>
    <p class="mt-1 text-sm dark:text-paper-300">{{ \Illuminate\Support\Carbon::parse($fecha)->translatedFormat('d M Y') }}</p>
    <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach (['efectivo' => 'Efectivo', 'cheques' => 'Cheques', 'transferencias' => 'Transferencias'] as $campo => $label)
            <div><dt class="text-sm text-gray-600 dark:text-paper-300">{{ $label }}</dt><dd class="text-2xl font-bold tabular-nums dark:text-paper-100">${{ number_format((float) $cierre->{$campo.'_cobrado'}, 2) }}</dd></div>
        @endforeach
    </dl>
    <p class="mt-5 text-2xl font-bold text-green-900 dark:text-green-300">Efectivo a entregar ${{ number_format((float) $cierre->a_entregar, 2) }}</p>
    <a href="{{ route($prefijo.'liquidacion', [$salida, $fecha]) }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-green-700 px-5 py-3 font-semibold text-white hover:bg-green-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600">Descargar liquidación</a>
</section>
