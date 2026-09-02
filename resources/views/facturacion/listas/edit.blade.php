<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Editar lista de empaque #{{ $lista->id }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @if ($lista->facturas()->isNotEmpty())
                <div class="mb-4 rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                    Esta lista ya tiene factura(s) vinculada(s): <strong>{{ $lista->textoFactura() }}</strong>.
                    Cambiar productos o cantidades <strong>no toca esas facturas</strong> —son documentos fiscales aparte—, así que
                    si el cambio afecta a lo facturado hay que corregir también el documento.
                </div>
            @endif

            <form method="POST" action="{{ route('facturacion.listas.update', $lista) }}" class="space-y-6">
                @csrf
                @method('PUT')
                @include('facturacion.listas._form')

                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('facturacion.listas.show', $lista) }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancelar</a>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
