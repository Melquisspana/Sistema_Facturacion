@php
    use App\Services\Gastos\Dinero;

    $boton = 'inline-flex min-h-9 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50';
    $botonAccion = 'inline-flex min-h-9 items-center justify-center rounded-md bg-indigo-600 px-3.5 text-xs font-medium text-white hover:bg-indigo-500';
    $campo = 'mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm';
    $etiqueta = 'block text-sm font-medium text-gray-700';

    $panel = 'fila-'.$g->id;
    $metodos = config('gastos.metodos');
    $hoy = now()->toDateString();
    $vence = $g->proxima ? \Carbon\CarbonImmutable::parse(substr((string) $g->proxima, 0, 10)) : null;
@endphp

{{-- Una obligación REAL en la pantalla principal, con la acción que le corresponde.

     Las tres acciones —pagar, abonar, anotar el monto— abren su formulario aquí mismo,
     ya cargado con todo lo que el sistema sabe. Solo se pide lo que no puede saber:
     la fecha, el método, o la cifra del recibo.

     EL SALDO PROVISIONAL SE DICE. Proveedor A y Proveedor B se cargaron con un saldo acordado de
     palabra; Distribuidora Ejemplo con un estado de cuenta en la mano. Los tres se deben igual,
     pero no son el mismo dato, y la fila lo marca en vez de presentarlos como iguales. --}}
<div class="border-t border-gray-100 first:border-t-0">
    <div class="flex items-center justify-between gap-3 px-4 py-3">
        <div class="min-w-0">
            <a href="{{ route('gastos.show', $g) }}" class="truncate font-medium text-gray-900 hover:text-indigo-700">
                {{ $g->beneficiario }}
            </a>
            <p class="truncate text-sm text-gray-500">
                {{ $g->concepto }}
                @if ($vence)
                    · vence el {{ $vence->format('d/m') }}
                @endif
            </p>
            @if ($g->esProvisional())
                <p class="mt-0.5 text-[11px] font-medium text-amber-700">
                    Saldo provisional · pendiente de confirmar con documento
                </p>
            @elseif ($g->confirmadoPorElUsuario())
                {{-- Confirmado por una persona, no por un papel. La distinción se dice
                     en vez de callarse: decir «confirmado» a secas al lado de uno que
                     sí tiene estado de cuenta los igualaría, y no son iguales. --}}
                <p class="mt-0.5 text-[11px] font-medium text-slate-500">
                    Confirmado por el usuario · sin documento de respaldo
                </p>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-3">
            @if ($g->ambito === 'personal')
                <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[11px] font-medium text-violet-700">Casa</span>
            @else
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">Empresa</span>
            @endif

            <span class="whitespace-nowrap tabular-nums text-gray-900">
                @if ($g->montoDesconocido())
                    <span class="text-sm italic text-gray-500">llega el recibo</span>
                @else
                    ${{ Dinero::mostrar((int) $g->pendiente) }}
                @endif
            </span>

            @if ($accion === 'pagar')
                @can('gastos.pagos.registrar')
                    <button type="button" @click="abrir('{{ $panel }}')" class="{{ $botonAccion }}">Pagar</button>
                @endcan
            @elseif ($accion === 'abonar')
                @can('gastos.pagos.registrar')
                    <button type="button" @click="abrir('{{ $panel }}')" class="{{ $botonAccion }}">Abonar</button>
                @endcan
            @elseif ($accion === 'completar')
                @can('gastos.registrar')
                    <button type="button" @click="abrir('{{ $panel }}')" class="{{ $botonAccion }}">Anotar el monto</button>
                @endcan
            @endif
        </div>
    </div>

    {{-- ══════════════ El formulario de la acción ══════════════ --}}
    <div x-show="abierto === '{{ $panel }}'" x-cloak class="border-t border-gray-100 bg-gray-50 px-4 py-4">

        @if ($accion === 'pagar')
            {{-- Pagar: el sistema ya sabe a quién, qué y cuánto. Solo falta cuándo y cómo. --}}
            <form method="POST" action="{{ route('gastos.panel.pagar', $g) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="clave" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <p class="text-sm text-gray-700">
                    Pagar <span class="font-semibold">${{ Dinero::mostrar((int) $g->pendiente) }}</span>
                    a {{ $g->beneficiario }}. Se salda el total pendiente de esta obligación.
                </p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="{{ $etiqueta }}" for="p-fecha-{{ $g->id }}">Cuándo pagaste</label>
                        <input id="p-fecha-{{ $g->id }}" name="fecha" type="date" required value="{{ $hoy }}" max="{{ $hoy }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="p-metodo-{{ $g->id }}">Cómo</label>
                        <select id="p-metodo-{{ $g->id }}" name="metodo" class="{{ $campo }}">
                            @foreach ($metodos as $valor => $texto)
                                <option value="{{ $valor }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="p-ref-{{ $g->id }}">Referencia</label>
                        <input id="p-ref-{{ $g->id }}" name="referencia" maxlength="180" class="{{ $campo }}" placeholder="Opcional">
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="{{ $botonAccion }}">Registrar el pago</button>
                    <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                    <a href="{{ route('gastos.show', $g) }}" class="text-xs text-indigo-600 hover:underline">
                        Pagar solo una parte, o repartir por cuotas
                    </a>
                </div>
                <p class="text-xs text-gray-500">El comprobante se adjunta desde el detalle del pago, apenas se guarde.</p>
            </form>

        @elseif ($accion === 'abonar')
            {{-- Abonar: el reparto lo propone el servicio, de la compra más vieja a la más
                 nueva. Cuando el abono era por una compra concreta, el enlace lleva a la
                 cuenta, donde el reparto se edita línea por línea. --}}
            <form method="POST" action="{{ route('gastos.panel.abonos') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="clave" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                {{-- De dónde salió este abono. Es una palabra de una lista cerrada, no
                     una dirección: el servidor la traduce a una ruta nuestra. --}}
                <input type="hidden" name="origen" value="panel">
                <input type="hidden" name="beneficiario" value="{{ $g->beneficiario }}">
                <input type="hidden" name="moneda" value="{{ $g->moneda }}">
                <p class="text-sm text-gray-700">
                    Abonar a {{ $g->beneficiario }}. Debe
                    <span class="font-semibold">${{ Dinero::mostrar((int) $g->pendiente) }}</span>.
                </p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="{{ $etiqueta }}" for="a-importe-{{ $g->id }}">Cuánto abonás</label>
                        <input id="a-importe-{{ $g->id }}" name="importe" required inputmode="decimal" class="{{ $campo }}" placeholder="0.00">
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="a-fecha-{{ $g->id }}">Cuándo</label>
                        <input id="a-fecha-{{ $g->id }}" name="fecha" type="date" required value="{{ $hoy }}" max="{{ $hoy }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="a-metodo-{{ $g->id }}">Cómo</label>
                        <select id="a-metodo-{{ $g->id }}" name="metodo" class="{{ $campo }}">
                            @foreach ($metodos as $valor => $texto)
                                <option value="{{ $valor }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="{{ $botonAccion }}">Registrar el abono</button>
                    <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                    <a href="{{ route('gastos.cuentas.show', ['proveedor' => $g->beneficiario, 'moneda' => $g->moneda]) }}"
                       class="text-xs text-indigo-600 hover:underline">
                        Ver y editar el reparto entre compras
                    </a>
                </div>
                <p class="text-xs text-gray-500">
                    Se aplica de la compra más antigua a la más nueva. Un abono mayor que la deuda no se guarda: se avisa.
                </p>
            </form>

        @elseif ($accion === 'completar')
            {{-- Anotar el monto: COMPLETA la obligación que ya existe, no crea otra.
                 El vencimiento real del recibo se guarda si viene; si no viene, la cuota
                 se queda sin fecha. No se inventa ninguna. --}}
            <form method="POST" action="{{ route('gastos.panel.completar', $g) }}" class="space-y-3">
                @csrf
                <p class="text-sm text-gray-700">
                    Anotar lo que dice el recibo de {{ $g->beneficiario }} · {{ $g->concepto }}.
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $etiqueta }}" for="r-importe-{{ $g->id }}">Importe del recibo</label>
                        <input id="r-importe-{{ $g->id }}" name="importe" required inputmode="decimal" class="{{ $campo }}" placeholder="0.00">
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="r-vence-{{ $g->id }}">Vence el (opcional)</label>
                        <input id="r-vence-{{ $g->id }}" name="vence" type="date" class="{{ $campo }}">
                        <p class="mt-1 text-xs text-gray-500">La fecha que trae el papel. Si no la ponés, queda sin vencimiento.</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="{{ $botonAccion }}">Guardar el monto</button>
                    <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                </div>
            </form>
        @endif
    </div>
</div>
