<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Importar catálogo de exportación</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('error'))
                <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-5">
                <div class="text-sm text-gray-600 space-y-2">
                    <p>
                        Lee la hoja <span class="font-semibold">"Lista"</span> del Excel de lista de empaque y crea
                        los productos del catálogo con: descripción español/inglés, unidad, unidades por caja,
                        gramos y onzas por unidad, precio por caja y pesos neto/bruto en kg y lb.
                    </p>
                    <p>
                        Los productos que ya existen (mismo nombre en español) se <span class="font-semibold">omiten</span>:
                        podés re-importar sin duplicar.
                    </p>
                    @if ($plantilla)
                        <p class="text-xs text-gray-400">Plantilla guardada: <code>{{ $plantilla }}</code></p>
                    @else
                        <p class="text-red-600">No hay plantilla guardada en el servidor: subí un archivo.</p>
                    @endif
                    <p class="text-xs text-gray-400">Productos actuales en el catálogo: {{ $totalProductos }}</p>
                </div>

                <form method="POST" action="{{ route('exportaciones.productos.importar.run') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Archivo Excel (.xlsx)</label>
                        <input type="file" name="archivo" accept=".xlsx"
                               class="mt-1 w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:text-gray-700 hover:file:bg-gray-200">
                        <p class="mt-1 text-xs text-gray-400">
                            Opcional: si no subís nada, se importa desde la plantilla guardada en el servidor.
                        </p>
                        @error('archivo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100">
                        <a href="{{ route('exportaciones.productos.index') }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancelar</a>
                        <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Importar catálogo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
