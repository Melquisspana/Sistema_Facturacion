<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Nuevo gasto que se repite</h1>
            <a href="{{ route('gastos.reglas.index') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-4xl space-y-4">
            <x-gastos-aviso />

            {{-- Lo primero que se lee, antes de cualquier campo: crear la regla no crea
                 deuda. Es la confusión que más caro sale en esta pantalla. --}}
            <div class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                <p class="font-medium">Guardar esta regla no crea ninguna obligación todavía.</p>
                <p class="mt-1">
                    Las obligaciones aparecen cuando se genera cada período —a mano desde la ficha de la regla,
                    o por el proceso programado si está encendido—. Y cuando aparecen, nacen
                    <strong>sin pagar</strong>: que algo se pague todos los meses no autoriza a dar por pagado este.
                </p>
            </div>

            <form method="POST" action="{{ route('gastos.reglas.store') }}" class="space-y-6">
                @csrf
                <input type="hidden" name="clave" value="{{ old('clave', $clave) }}">

                @include('gastos.reglas.partials.formulario', ['regla' => null, 'hoy' => $hoy])

                <div class="flex flex-wrap justify-end gap-2">
                    <a href="{{ route('gastos.reglas.index') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
