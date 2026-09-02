<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Importar productos de exportación</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6 space-y-5">

                @if (session('error'))
                    <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
                @endif

                <p class="text-sm text-gray-700">
                    Lee la hoja <strong>«Lista»</strong> de un Excel con el mismo layout que la lista de empaque y da de alta los
                    productos que falten. Los que ya existen (mismo nombre en español) <strong>se omiten</strong>: importar dos
                    veces no duplica el catálogo ni pisa precios.
                </p>

                <div class="rounded-md bg-gray-50 p-3 text-xs text-gray-600">
                    Hoy el catálogo tiene <strong>{{ $totalProductos }}</strong> producto(s).
                    @if ($archivoServidor)
                        Hay un archivo guardado en el servidor: <span class="break-all font-mono">{{ $archivoServidor }}</span>.
                        Si no subís nada, se importa de ahí.
                    @else
                        <span class="text-amber-700">No hay ningún archivo guardado en el servidor</span>, así que tenés que subir uno.
                        Esto no afecta a la descarga del Excel: la lista de empaque se genera por su cuenta y no depende de ninguna plantilla.
                    @endif
                </div>

                <form method="POST" action="{{ route('productos.exportacion.importar.run') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf

                    <div>
                        <label for="archivo" class="block text-sm font-medium text-gray-700">Archivo Excel (.xlsx)</label>
                        <input id="archivo" type="file" name="archivo" accept=".xlsx"
                               class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:text-gray-700 hover:file:bg-gray-200">
                        @error('archivo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @unless ($archivoServidor)
                            <p class="mt-1 text-xs text-gray-500">Obligatorio: no hay archivo guardado en el servidor del que leer.</p>
                        @endunless
                    </div>

                    <div class="flex items-center gap-3 border-t border-gray-100 pt-4">
                        <x-primary-button>Importar</x-primary-button>
                        <a href="{{ route('productos.exportacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
