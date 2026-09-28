{{-- Agregar o editar una presentación de un producto. --}}
@php
    $esNueva = ! $presentacion->exists;
    $accion = $esNueva
        ? route('productos.exportacion.presentaciones.store', $base)
        : route('productos.exportacion.update', $presentacion);
    $volver = route('productos.exportacion.index').($base ? '#producto-'.$base->id : '');
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $esNueva ? 'Nueva presentación' : 'Editar presentación' }}
            <span class="font-normal text-gray-500">· {{ $base?->nombre_es ?? $presentacion->nombre_es }}</span>
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ $accion }}" class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-6 space-y-5">
                @csrf
                @unless ($esNueva) @method('PUT') @endunless

                @if ($esNueva && $base && $base->presentaciones()->exists())
                    <p class="text-sm text-gray-600">
                        Ya tiene:
                        {{ $base->presentaciones()->orderBy('unidades_por_caja')->get()->map(fn ($p) => $p->etiquetaEmpaque().' · '.(float) $p->gramos_por_unidad.' g')->implode(' — ') }}
                    </p>
                @endif

                @unless ($esNueva)
                    <p class="rounded-md bg-gray-50 p-3 text-xs text-gray-600">
                        Cambiarla <strong>no toca ninguna lista de empaque ya creada</strong>: cada lista guarda su propia copia
                        de la presentación, para que corregir el catálogo no reescriba documentos que ya se enviaron.
                    </p>
                @endunless

                @include('productos.exportacion._presentacion', ['presentacion' => $presentacion])

                @if ($esNueva)
                    <div class="space-y-3 border-t border-gray-100 pt-5">
                        <div>
                            <h3 class="text-sm font-medium text-gray-700">¿A qué clientes se le vende?</h3>
                            <p class="text-xs text-gray-500">Si dejás el precio vacío, usa el precio base.</p>
                        </div>
                        @include('productos.exportacion._clientes', ['clientes' => $clientes])
                    </div>
                @endif

                <div class="flex items-center gap-3 border-t border-gray-100 pt-5">
                    <x-primary-button>{{ $esNueva ? 'Agregar presentación' : 'Guardar cambios' }}</x-primary-button>
                    <a href="{{ $volver }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
