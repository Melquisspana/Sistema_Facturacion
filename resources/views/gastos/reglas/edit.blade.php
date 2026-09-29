<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Editar «{{ $regla->nombre }}»</h1>
            <a href="{{ route('gastos.reglas.show', $regla) }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-4xl space-y-4">
            <x-gastos-aviso />

            {{-- El punto entero de versionar: editar no reescribe el pasado. Se dice acá
                 para que nadie edite pensando que corrige lo que ya se generó. --}}
            <div class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                <p class="font-medium">Este cambio rige de acá en adelante.</p>
                <p class="mt-1">
                    Las {{ $regla->ocurrencias()->where('estado', 'generada')->count() }} obligación(es) ya generadas
                    <strong>no se tocan</strong>: se conservan con la versión de la regla que las creó (vas por la v{{ $regla->version }}).
                    Si una obligación vieja está mal, corregila en su ficha con un ajuste; no desde acá.
                </p>
            </div>

            @if ($yaGenero)
                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Esta regla ya generó períodos. Si cambiás la <strong>frecuencia</strong>, poné «vigente desde»
                    después del último período generado: si no, los períodos viejos y los nuevos usarían claves
                    distintas para el mismo tramo y podrían solaparse.
                </div>
            @endif

            <form method="POST" action="{{ route('gastos.reglas.update', $regla) }}" class="space-y-6">
                @csrf
                @method('PUT')

                @include('gastos.reglas.partials.formulario', ['regla' => $regla])

                <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                    <label for="motivo" class="block text-sm font-medium text-gray-700">Motivo del cambio</label>
                    <textarea id="motivo" name="motivo" rows="2" required minlength="5" maxlength="500"
                              class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                              placeholder="El alquiler subió a 450 desde marzo.">{{ old('motivo') }}</textarea>
                    <p class="text-xs text-gray-500">Queda con la versión nueva. Sin él no se puede comparar qué cambió y por qué.</p>
                </section>

                <div class="flex flex-wrap justify-end gap-2">
                    <a href="{{ route('gastos.reglas.show', $regla) }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                        Guardar versión {{ $regla->version + 1 }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
