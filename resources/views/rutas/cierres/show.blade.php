@if ($modoVendedor)
    @extends('layouts.vendedor')
    @section('content')
        @include('rutas.cierres._form')
    @endsection
@else
    <x-app-layout>
        <x-slot name="header"><h1 class="text-xl font-semibold dark:text-paper-100">Terminar día</h1></x-slot>
        <div class="mx-auto max-w-3xl px-4 py-6">@include('rutas.cierres._form')</div>
    </x-app-layout>
@endif
