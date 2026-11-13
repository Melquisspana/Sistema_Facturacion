@props(['activos' => 0, 'id' => 'filtros'])

{{--
    Filtros plegables en el celular (docs/GUIA_PANTALLAS.md › Listas y tablas).

    Debajo de md, los filtros quedan detrás de un botón «Filtros» de 44 px que dice cuántos
    hay puestos; desde md se ven abiertos, como siempre. Lo de adentro (el formulario GET)
    no cambia: solo se envuelve.

    Sin `md:flex` en el CSS de producción, el envoltorio es un bloque: `hidden md:block`, y
    Alpine le quita `hidden` al abrirlo en el celular.
--}}
<div x-data="{ filtrosAbiertos: false }" {{ $attributes }}>
    <button type="button" @click="filtrosAbiertos = ! filtrosAbiertos"
            :aria-expanded="filtrosAbiertos ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $id }}"
            class="md:hidden mb-3 inline-flex min-h-11 items-center gap-2 rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9M3.75 6h3.75m0 0a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0zM3.75 12h9m8.25 0h-3.75m0 0a1.5 1.5 0 103 0 1.5 1.5 0 00-3 0zM10.5 18h9m-13.5 0h-3.75m0 0a1.5 1.5 0 103 0 1.5 1.5 0 00-3 0z" />
        </svg>
        Filtros
        @if ($activos > 0)
            <span class="inline-flex h-5 items-center justify-center rounded-full bg-indigo-600 px-2 text-xs font-semibold text-white">{{ $activos }}</span>
            <span class="sr-only">{{ $activos === 1 ? 'filtro puesto' : 'filtros puestos' }}</span>
        @endif
    </button>

    <div id="{{ $id }}" class="hidden md:block" :class="{ 'hidden': ! filtrosAbiertos }">
        {{ $slot }}
    </div>
</div>
