@if ($modoVendedor)
    @extends('layouts.vendedor')
    @section('content')
        @include('rutas.cierres._form')
    @endsection
@else
    <x-app-layout>
        <x-slot name="header"><h2 class="text-xl font-semibold dark:text-paper-100">Cierre del día</h2></x-slot>
        <div class="mx-auto max-w-3xl px-4 py-6">@include('rutas.cierres._form')</div>
    </x-app-layout>
@endif
