@if ($modoVendedor)
    @extends('layouts.vendedor')
    @section('content')
        @include('rutas.liquidacion._contenido')
    @endsection
@else
    <x-app-layout>
        <x-slot name="header"><h2 class="text-xl font-semibold dark:text-paper-100">Terminar ruta</h2></x-slot>
        <div class="mx-auto max-w-3xl px-4 py-6">
            <x-rutas.avisos />
            @include('rutas.liquidacion._contenido')
        </div>
    </x-app-layout>
@endif
