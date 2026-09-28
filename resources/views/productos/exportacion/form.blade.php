{{-- Alta en un solo paso: el producto y su primera presentación. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nuevo producto de exportación</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('productos.exportacion.store') }}"
                  class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl divide-y divide-gray-100">
                @csrf

                <section class="p-6 space-y-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-800">El producto</h3>
                        <p class="text-sm text-gray-500">El dulce, sin importar cómo se empaque. Si ya existe, agregale una presentación desde el catálogo.</p>
                    </div>
                    @include('productos.exportacion._campos_base', ['base' => $base])
                </section>

                <section class="p-6 space-y-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-800">Primera presentación</h3>
                        <p class="text-sm text-gray-500">Cómo va en la caja. Las demás presentaciones se agregan después con «+ Presentación».</p>
                    </div>
                    @include('productos.exportacion._presentacion', ['presentacion' => new \App\Models\ExportacionProducto()])
                </section>

                <section class="p-6 space-y-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-800">¿A qué clientes se le vende?</h3>
                        <p class="text-sm text-gray-500">Marcá los clientes y su precio por caja. Si lo dejás vacío, usa el precio base. Se puede cambiar después desde el catálogo.</p>
                    </div>
                    @include('productos.exportacion._clientes', ['clientes' => $clientes])
                </section>

                <div class="flex items-center gap-3 p-6">
                    <x-primary-button>Agregar al catálogo</x-primary-button>
                    <a href="{{ route('productos.exportacion.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
