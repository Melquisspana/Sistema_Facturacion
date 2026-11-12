@props(['dte', 'original' => null])

{{-- La copia de un CCF rechazado que Hacienda después aceptó. El original vale; esta copia
     no se puede generar, firmar ni transmitir (lo frena el servidor) hasta que quien puede
     emitir diga, con un motivo, que es otra venta. Va en la ficha y en el editor de la
     copia. `$original` lo calcula el controlador: si es null, no hay nada que avisar.
     No es `x-avisos`: no es un mensaje de una acción, es un estado del documento con su
     propia decisión. --}}
@if ($original)
    <div class="rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-800" role="alert">
        <p><strong>Esta es la copia de un documento que Hacienda aceptó después.</strong></p>
        <p class="mt-1">
            Se hizo para corregir el {{ $original->tipo_dte->label() }}
            <a href="{{ route('facturacion.show', $original) }}" class="underline font-semibold">{{ $original->numero_control ?? $original->numero_interno ?? '#'.$original->id }}</a>,
            que figuraba rechazado. Hacienda después lo aceptó: el que vale es ese.
        </p>
        <p class="mt-1">
            @if ($dte->estado === \App\Enums\EstadoDte::Aceptado)
                Esta copia también está aceptada: la misma venta puede estar facturada dos veces. Revisá cuál de las dos hay que invalidar.
            @else
                Mientras nadie lo revise, esta copia no se puede generar, firmar ni transmitir: la misma venta quedaría facturada dos veces.
            @endif
        </p>
        @if ($dte->relationLoaded('enviosPedido') && $dte->enviosPedido->isNotEmpty())
            <p class="mt-1">
                El pedido de la sala quedó enlazado a esta copia y no al original. Acá no se cambió nada: revisalo con quien lleva los pedidos.
            </p>
        @endif

        @can('resolverCopiaDeAceptado', $dte)
            <form method="POST" action="{{ route('facturacion.copia-aceptado.resolver', $dte) }}" class="mt-3 border-t border-amber-300 pt-3">
                @csrf
                <x-input-label for="motivo-otra-venta" value="Si es otra venta, contá por qué" />
                <x-text-input id="motivo-otra-venta" name="motivo" type="text" class="mt-1 block w-full min-h-11" maxlength="300" required :value="old('motivo')" />
                <p class="mt-1 text-xs text-gray-500">Queda anotado en la bitácora con tu usuario. No se invalida ni se mueve nada.</p>
                <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <x-primary-button>Es otra venta: emitir igual</x-primary-button>
                </div>
            </form>
        @endcan
    </div>
@endif
