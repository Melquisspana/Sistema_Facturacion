{{--
    Pantalla para «el código de fase 2 está, el esquema no».

    NO se usa la página de error 503 de Laravel: esa la comparte el modo
    mantenimiento, y pisarla para explicar algo de Gastos cambiaría el mensaje de
    todo el sistema cuando alguien lo ponga en mantenimiento. Además la genérica no
    muestra el motivo, así que quien la ve solo lee «Service Unavailable» y no sabe
    qué hacer.

    Va dentro del layout normal, con su menú: la aplicación funciona, lo único que
    falta es esta parte. Una pantalla de error a página completa daría la impresión
    contraria.
--}}
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Recurrencias y avisos: falta instalar</h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-3xl space-y-4">

            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-4 text-sm text-amber-900" role="status">
                <p class="font-semibold">Esta parte del módulo todavía no está instalada en esta base de datos.</p>
                <p class="mt-2">
                    El resto del sistema funciona con normalidad, y <strong>Gastos también</strong>: el listado,
                    el alta, los pagos y los informes no dependen de esto. Lo único que no está disponible son
                    las reglas recurrentes y los avisos.
                </p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Qué falta</h2>
                <ul class="mt-2 list-inside list-disc text-sm text-gray-700">
                    @foreach ($tablasQueFaltan as $tabla)
                        <li><code class="rounded bg-gray-100 px-1 py-0.5 text-xs">{{ $tabla }}</code></li>
                    @endforeach
                </ul>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Cómo se resuelve</h2>
                <p class="mt-2 text-sm text-gray-700">
                    Hay que aplicar las <strong>migraciones pendientes</strong> en esta base:
                </p>
                <pre class="mt-2 overflow-x-auto rounded-md bg-gray-900 px-3 py-2 text-xs text-gray-100"><code>php artisan migrate</code></pre>
                <p class="mt-2 text-xs text-gray-500">
                    Conviene revisar antes con <code>php artisan migrate:status</code> qué se va a aplicar, y
                    tener un respaldo de la base. Estas migraciones solo agregan tablas nuevas y una columna
                    opcional; no modifican ni borran datos existentes.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('gastos.panel') }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Ir a Gastos</a>
                <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver al inicio</a>
            </div>
        </div>
    </div>
</x-app-layout>
