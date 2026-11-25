@props(['titulo', 'message' => null])
{{-- La versión en texto de mail::documento: el nombre del correo y la empresa arriba, como mail::message. --}}
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        {{ $titulo }} · {{ \App\Support\NombreEmpresa::actual() }}
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        © {{ date('Y') }} {{ \App\Support\NombreEmpresa::actual() }}. @lang('All rights reserved.')
    </x-slot:footer>
</x-mail::layout>
