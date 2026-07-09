<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nueva lista de empaque</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @if ($clientes->isEmpty())
                <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">
                    No hay clientes de exportación.
                    <a href="{{ route('exportaciones.clientes.create') }}" class="font-medium underline">Creá el primero</a>
                    antes de armar una lista de empaque.
                </div>
            @endif
            @if ($productos->isEmpty())
                <div class="mb-4 rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">
                    El catálogo de productos de exportación está vacío.
                    <a href="{{ route('exportaciones.productos.importar') }}" class="font-medium underline">Importalo desde la plantilla</a>
                    antes de crear una lista de empaque.
                </div>
            @endif

            <form method="POST" action="{{ route('exportaciones.store') }}" class="space-y-6">
                @csrf
                @include('exportaciones._form')

                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('exportaciones.index') }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancelar</a>
                    <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Guardar lista de empaque</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
