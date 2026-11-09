<x-configuracion-layout :titulo="$pagina['titulo']">
    {{--
        Opciones de negocio de un módulo (App\Http\Controllers\Configuracion\OpcionesModuloController).

        Cada campo es un ajuste del catálogo: lo guardado acá manda sobre el .env y, debajo
        de cada uno, se ve de dónde sale el valor que se usa hoy.

        Distinto de la guía en una cosa, a propósito: ningún campo lleva «(opcional)» ni
        `required`. Acá un campo vacío no significa «sin dato», significa «dejá el que ya
        manda»; para volver al .env está «Usar el valor del servidor».
    --}}
    <form method="POST" action="{{ $accion }}" class="space-y-6">
        @csrf
        @method('PUT')

        <p class="text-sm text-gray-500">{{ $pagina['descripcion'] }}</p>

        @foreach ($grupos as $grupo)
            <x-configuracion.seccion :titulo="$grupo['titulo']" :descripcion="$grupo['descripcion']">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    @foreach ($grupo['campos'] as $campo => $fila)
                        @php
                            /** @var \App\Ajustes\Definicion\DefinicionAjuste $definicion */
                            $definicion = $fila['definicion'];
                            $estado = $fila['estado'];
                            $tipo = $definicion->tipo;
                            // Valor de una regla del catálogo («min:1» → «1»), para los atributos del campo.
                            $regla = function (string $prefijo) use ($definicion): ?string {
                                foreach ($definicion->reglas as $r) {
                                    if (str_starts_with($r, $prefijo)) {
                                        return substr($r, strlen($prefijo));
                                    }
                                }

                                return null;
                            };
                            $actual = $estado->valor;
                            // Para el selector de cliente: lo que se eligió o el que manda hoy.
                            $elegido = (string) old($campo, $actual);
                        @endphp

                        @if ($tipo === \App\Ajustes\Definicion\TipoAjuste::Booleano)
                            {{-- Sí/no: casilla a todo el ancho, como en Módulos. --}}
                            <div class="sm:col-span-2">
                                <label for="{{ $campo }}" class="flex items-start gap-3">
                                    <input type="checkbox" id="{{ $campo }}" name="{{ $campo }}" value="1"
                                           @checked((bool) old($campo, $actual))
                                           class="mt-0.5 h-5 w-5 rounded border-gray-300 text-indigo-600">
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-medium text-gray-800">{{ $definicion->etiqueta }}</span>
                                            <x-configuracion.badge :estado="$actual
                                                ? \App\Ajustes\Resumen\EstadoTarjeta::Activo
                                                : \App\Ajustes\Resumen\EstadoTarjeta::Desactivado" />
                                        </span>
                                        <span class="mt-0.5 block text-sm text-gray-500">{{ $definicion->descripcion }}</span>
                                        <span class="mt-1 block text-xs text-gray-400">
                                            Fuente: {{ $estado->fuente->etiqueta() }}
                                            <x-configuracion.usar-valor-servidor :estado="$estado" />
                                        </span>
                                    </span>
                                </label>
                                @error($campo)
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                                @if ($estado->invalido)
                                    <p class="mt-1 text-xs text-amber-800">{{ $estado->invalido }}</p>
                                @endif
                            </div>
                        @else
                            <x-configuracion.campo :nombre="$campo" :etiqueta="$definicion->etiqueta" :estado="$estado"
                                                   :ayuda="$definicion->descripcion">
                                @if ($tipo === \App\Ajustes\Definicion\TipoAjuste::Enumerado)
                                    <select id="{{ $campo }}" name="{{ $campo }}" class="w-full rounded-md border-gray-300 text-sm">
                                        @foreach ($definicion->opciones as $opcion)
                                            <option value="{{ $opcion }}" @selected(old($campo, $actual) === $opcion)>
                                                {{ ucfirst(str_replace('_', ' ', $opcion)) }}
                                            </option>
                                        @endforeach
                                    </select>
                                @elseif ($tipo === \App\Ajustes\Definicion\TipoAjuste::Cliente)
                                    {{-- La misma lista de clientes del filtro de lotes. Vacío = no tocar. --}}
                                    <select id="{{ $campo }}" name="{{ $campo }}" class="w-full rounded-md border-gray-300 text-sm">
                                        <option value="">
                                            {{ $elegido !== '' && ! $clientes->contains('id', (int) $elegido)
                                                ? 'El cliente '.$elegido.' no existe: elegí otro'
                                                : '— Elegir cliente —' }}
                                        </option>
                                        @foreach ($clientes as $cliente)
                                            <option value="{{ $cliente->id }}" @selected($elegido === (string) $cliente->id)>{{ $cliente->nombre }}</option>
                                        @endforeach
                                    </select>
                                @elseif (in_array($tipo, [\App\Ajustes\Definicion\TipoAjuste::Entero, \App\Ajustes\Definicion\TipoAjuste::Decimal], true))
                                    <input type="number" id="{{ $campo }}" name="{{ $campo }}"
                                           @if ($regla('min:') !== null) min="{{ $regla('min:') }}" @endif
                                           @if ($regla('max:') !== null) max="{{ $regla('max:') }}" @endif
                                           step="{{ $tipo === \App\Ajustes\Definicion\TipoAjuste::Decimal ? '0.01' : '1' }}"
                                           inputmode="{{ $tipo === \App\Ajustes\Definicion\TipoAjuste::Decimal ? 'decimal' : 'numeric' }}"
                                           value="{{ old($campo, $actual) }}"
                                           class="w-full rounded-md border-gray-300 text-sm">
                                @else
                                    <input type="{{ match (true) {
                                               $tipo === \App\Ajustes\Definicion\TipoAjuste::Email => 'email',
                                               in_array('hora', $definicion->reglas, true) => 'time',
                                               in_array('fecha', $definicion->reglas, true) => 'date',
                                               default => 'text',
                                           } }}"
                                           id="{{ $campo }}" name="{{ $campo }}"
                                           @if ($regla('maxlen:') !== null) maxlength="{{ $regla('maxlen:') }}" @endif
                                           @if (in_array('rango_horas', $definicion->reglas, true)) placeholder="08:00-17:00" @endif
                                           autocomplete="off"
                                           value="{{ old($campo, $actual) }}"
                                           class="w-full rounded-md border-gray-300 text-sm">
                                @endif
                            </x-configuracion.campo>
                        @endif
                    @endforeach
                </div>
            </x-configuracion.seccion>
        @endforeach

        <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
            <a href="{{ $volver }}"
               class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancelar
            </a>
            <button type="submit"
                    class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                Guardar cambios
            </button>
        </div>
    </form>
</x-configuracion-layout>
