{{-- Planilla encendida pero sin migrar. Misma decisión que en Gastos: pantalla
     propia y no la página 503 de Laravel, que la comparte el modo mantenimiento y
     además no dice el motivo. --}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Planilla: falta instalar</h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-4">
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-4 text-sm text-amber-900" role="status">
                <p class="font-semibold">El módulo está encendido pero sus tablas todavía no existen en esta base.</p>
                <p class="mt-2">El resto del sistema funciona con normalidad.</p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Qué falta</h2>
                <ul class="mt-2 list-inside list-disc text-sm text-gray-700">
                    @foreach ($tablasQueFaltan as $tabla)
                        <li><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $tabla }}</code></li>
                    @endforeach
                </ul>
                <pre class="mt-3 overflow-x-auto rounded-md bg-gray-900 px-3 py-2 text-xs text-gray-100"><code>php artisan migrate</code></pre>
                <p class="mt-2 text-xs text-gray-500">
                    Estas migraciones solo agregan tablas nuevas; no modifican ni borran datos existentes.
                </p>
            </div>

            <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver al inicio</a>
        </div>
    </div>
</x-app-layout>
