{{--
    A qué clientes se vende una presentación y a qué precio. Marcado sin precio
    toma el precio base. Desmarcar no borra el precio: lo guarda apagado.

    Recibe: $clientes (ExportacionCliente activos) y, al editar, $asignaciones
    (ExportacionClienteProducto de la presentación, por exportacion_cliente_id).
--}}
@php
    $asignaciones ??= collect();
    $prefijo = $prefijo ?? 'cli';
    // En el catálogo hay un editor por presentación: el old() de un envío fallido
    // solo se aplica al editor que lo envió.
    $usarOld = $usarOld ?? true;
@endphp

@if ($clientes->isEmpty())
    <p class="text-sm text-gray-500">No hay clientes de exportación activos.</p>
@else
    <div class="divide-y divide-gray-100 rounded-md border border-gray-200">
        @foreach ($clientes as $cliente)
            @php
                $asignacion = $asignaciones->get($cliente->id);
                $marcadoGuardado = $asignacion?->activo ?? false;
                $precioGuardado = $asignacion !== null ? number_format((float) $asignacion->precio_caja, 2, '.', '') : null;
                $marcado = $usarOld ? (bool) old("clientes.{$cliente->id}.activo", $marcadoGuardado) : $marcadoGuardado;
                $precio = $usarOld ? old("clientes.{$cliente->id}.precio", $precioGuardado) : $precioGuardado;
                $id = "{$prefijo}_{$cliente->id}";
            @endphp
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2" x-data="{ marcado: @js($marcado) }">
                <input id="{{ $id }}" type="checkbox" name="clientes[{{ $cliente->id }}][activo]" value="1" x-model="marcado"
                       class="rounded border-gray-300" @checked($marcado)>
                <label for="{{ $id }}" class="min-w-0 flex-1 text-sm text-gray-700">{{ $cliente->nombreLegal() }}</label>
                <div class="flex items-center gap-1">
                    <span class="text-sm text-gray-500" aria-hidden="true">$</span>
                    <input type="number" name="clientes[{{ $cliente->id }}][precio]" value="{{ $precio }}" min="0" step="0.01"
                           :disabled="!marcado" aria-label="Precio por caja para {{ $cliente->nombreLegal() }}"
                           placeholder="precio base"
                           class="w-28 rounded-md border-gray-300 text-sm tabular-nums disabled:opacity-40">
                </div>
            </div>
            @if ($usarOld)
                @error("clientes.{$cliente->id}.precio") <p class="px-3 pb-2 text-xs text-red-600">{{ $message }}</p> @enderror
            @endif
        @endforeach
    </div>
@endif
