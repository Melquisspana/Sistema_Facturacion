@php
    use App\Services\Gastos\Dinero;

    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50';
    $botonAccion = 'inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-3.5 text-xs font-medium text-white hover:bg-indigo-500';
    $campo = 'mt-1 min-h-11 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm';
    $etiqueta = 'block text-sm font-medium text-gray-700';

    // El panel es de la acción, no solo del gasto: si «Anotar el monto» vuelve con error
    // porque otro envío ya lo anotó, la fila ya ofrece Pagar o Abonar y no se abre ese
    // otro formulario con el error y la cifra escrita para el recibo.
    $panel = 'fila-'.$accion.'-'.$g->id;
    $metodos = config('gastos.metodos');
    $hoy = now()->toDateString();
    $vence = $g->proxima ? \Carbon\CarbonImmutable::parse(substr((string) $g->proxima, 0, 10)) : null;

    // Si este formulario volvió con error, se reabre con lo escrito, el aviso debajo de su
    // campo y el foco en el primero que falló (como «Saltar»). Todas las filas usan los
    // mismos nombres de campo: sin esta condición lo escrito aparecería en todas.
    $visibles = match ($accion) {
        'pagar' => ['fecha', 'metodo', 'referencia'],
        'abonar' => ['importe', 'fecha', 'metodo'],
        'completar' => ['importe', 'vence'],
        default => [],
    };
    // Lo escrito (old) solo vuelve cuando el envío se rechazó: por un error de validación o
    // porque la sesión había vencido (419), que trae el aviso en session('error') y ningún
    // $errors. Por eso alcanza con que haya vuelto el panel, como en «Saltar».
    $volvio = old('panel') === $panel;
    $escrito = fn (string $campo, string $siNo = '') => $volvio ? (is_string(old($campo)) ? old($campo) : '') : $siNo;
    $foco = $volvio ? (collect($visibles)->first(fn ($c) => $errors->has($c)) ?? ($visibles[0] ?? null)) : null;
    // Lo que no es de un campo a la vista (clave, pago, beneficiario, la sesión vencida…) se
    // lee igual acá adentro.
    $otrosErrores = $volvio
        ? collect($errors->getMessages())->except($visibles)->flatten()->when(session('error'), fn ($c, $aviso) => $c->push($aviso))
        : collect();
    // El aviso queda enlazado a su campo: al llegar el foco, el lector de pantalla dice que
    // está mal y lee el aviso (el aviso lleva el id del campo más «-error»).
    $conAviso = fn (string $campo, string $id) => $volvio && $errors->has($campo)
        ? new \Illuminate\Support\HtmlString('aria-invalid="true" aria-describedby="'.e($id).'-error"')
        : '';
    // Al volver con error se conserva la MISMA clave: si ese envío sí llegó a guardarse
    // (doble clic), reenviarlo no registra otro pago; si no, la clave sigue libre.
    $clave = $volvio && \Illuminate\Support\Str::isUuid(old('clave')) ? old('clave') : (string) \Illuminate\Support\Str::uuid();
@endphp

{{-- Una obligación REAL en la pantalla principal, con la acción que le corresponde.

     Las tres acciones —pagar, abonar, anotar el monto— abren su formulario aquí mismo,
     ya cargado con todo lo que el sistema sabe. Solo se pide lo que no puede saber:
     la fecha, el método, o la cifra del recibo.

     La fila no dice qué tan firme es el saldo (provisional o confirmado): ninguna
     pantalla carga ese dato, así que mostrarlo afirmaba algo que nadie escribió. --}}
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
                    <button type="button" @click="abrir('{{ $panel }}')" class="{{ $botonAccion }}"
                            :aria-expanded="abierto === '{{ $panel }}' ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $panel }}">Pagar</button>
                @endcan
            @elseif ($accion === 'abonar')
                @can('gastos.pagos.registrar')
                    <button type="button" @click="abrir('{{ $panel }}')" class="{{ $botonAccion }}"
                            :aria-expanded="abierto === '{{ $panel }}' ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $panel }}">Abonar</button>
                @endcan
            @elseif ($accion === 'completar')
                @can('gastos.registrar')
                    <button type="button" @click="abrir('{{ $panel }}')" class="{{ $botonAccion }}"
                            :aria-expanded="abierto === '{{ $panel }}' ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $panel }}">Anotar el monto</button>
                @endcan
            @endif
        </div>
    </div>

    {{-- ══════════════ El formulario de la acción ══════════════ --}}
    <div id="{{ $panel }}" x-show="abierto === '{{ $panel }}'" x-cloak class="border-t border-gray-100 bg-gray-50 px-4 py-4">

        @if ($accion === 'pagar')
            {{-- Pagar: el sistema ya sabe a quién, qué y cuánto. Solo falta cuándo y cómo. --}}
            <form method="POST" action="{{ route('gastos.panel.pagar', $g) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="clave" value="{{ $clave }}">
                <input type="hidden" name="panel" value="{{ $panel }}">
                <p class="text-sm text-gray-700">
                    Pagar <span class="font-semibold">${{ Dinero::mostrar((int) $g->pendiente) }}</span>
                    a {{ $g->beneficiario }}. Se salda el total pendiente de esta obligación.
                </p>
                @foreach ($otrosErrores as $mensaje)
                    <p class="text-sm text-red-600">{{ $mensaje }}</p>
                @endforeach
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="{{ $etiqueta }}" for="p-fecha-{{ $g->id }}">Cuándo pagaste</label>
                        <input id="p-fecha-{{ $g->id }}" name="fecha" type="date" required value="{{ $escrito('fecha', $hoy) }}" max="{{ $hoy }}"
                               {{ $conAviso('fecha', 'p-fecha-'.$g->id) }} @if ($foco === 'fecha') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}">
                        @if ($volvio)
                            @error('fecha')<p id="p-fecha-{{ $g->id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="p-metodo-{{ $g->id }}">Cómo</label>
                        <select id="p-metodo-{{ $g->id }}" name="metodo"
                                {{ $conAviso('metodo', 'p-metodo-'.$g->id) }} @if ($foco === 'metodo') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}">
                            @foreach ($metodos as $valor => $texto)
                                <option value="{{ $valor }}" @selected($volvio && old('metodo') === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        @if ($volvio)
                            @error('metodo')<p id="p-metodo-{{ $g->id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="p-ref-{{ $g->id }}">Referencia</label>
                        <input id="p-ref-{{ $g->id }}" name="referencia" maxlength="180" value="{{ $escrito('referencia') }}"
                               {{ $conAviso('referencia', 'p-ref-'.$g->id) }} @if ($foco === 'referencia') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}" placeholder="Opcional">
                        @if ($volvio)
                            @error('referencia')<p id="p-ref-{{ $g->id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                    <button type="submit" class="{{ $botonAccion }}">Registrar el pago</button>
                </div>
                <p>
                    <a href="{{ route('gastos.show', $g) }}" class="text-xs text-indigo-600 hover:underline">
                        Pagar solo una parte, o repartir por cuotas
                    </a>
                </p>
                <p class="text-xs text-gray-500">El comprobante se adjunta desde el detalle del pago, apenas se guarde.</p>
            </form>

        @elseif ($accion === 'abonar')
            {{-- Abonar: el reparto lo propone el servicio, de la compra más vieja a la más
                 nueva. Cuando el abono era por una compra concreta, el enlace lleva a la
                 cuenta, donde el reparto se edita línea por línea. --}}
            <form method="POST" action="{{ route('gastos.panel.abonos') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="clave" value="{{ $clave }}">
                <input type="hidden" name="panel" value="{{ $panel }}">
                {{-- De dónde salió este abono. Es una palabra de una lista cerrada, no
                     una dirección: el servidor la traduce a una ruta nuestra. --}}
                <input type="hidden" name="origen" value="panel">
                <input type="hidden" name="beneficiario" value="{{ $g->beneficiario }}">
                <input type="hidden" name="moneda" value="{{ $g->moneda }}">
                <p class="text-sm text-gray-700">
                    Abonar a {{ $g->beneficiario }}. Debe
                    <span class="font-semibold">${{ Dinero::mostrar((int) $g->pendiente) }}</span>.
                </p>
                @foreach ($otrosErrores as $mensaje)
                    <p class="text-sm text-red-600">{{ $mensaje }}</p>
                @endforeach
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="{{ $etiqueta }}" for="a-importe-{{ $g->id }}">Cuánto abonás</label>
                        <input id="a-importe-{{ $g->id }}" name="importe" required inputmode="decimal" value="{{ $escrito('importe') }}"
                               {{ $conAviso('importe', 'a-importe-'.$g->id) }} @if ($foco === 'importe') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}" placeholder="0.00">
                        @if ($volvio)
                            @error('importe')<p id="a-importe-{{ $g->id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="a-fecha-{{ $g->id }}">Cuándo</label>
                        <input id="a-fecha-{{ $g->id }}" name="fecha" type="date" required value="{{ $escrito('fecha', $hoy) }}" max="{{ $hoy }}"
                               {{ $conAviso('fecha', 'a-fecha-'.$g->id) }} @if ($foco === 'fecha') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}">
                        @if ($volvio)
                            @error('fecha')<p id="a-fecha-{{ $g->id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="a-metodo-{{ $g->id }}">Cómo</label>
                        <select id="a-metodo-{{ $g->id }}" name="metodo"
                                {{ $conAviso('metodo', 'a-metodo-'.$g->id) }} @if ($foco === 'metodo') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}">
                            @foreach ($metodos as $valor => $texto)
                                <option value="{{ $valor }}" @selected($volvio && old('metodo') === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        @if ($volvio)
                            @error('metodo')<p id="a-metodo-{{ $g->id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                    <button type="submit" class="{{ $botonAccion }}">Registrar el abono</button>
                </div>
                <p>
                    <a href="{{ route('gastos.cuentas.show', ['proveedor' => $g->beneficiario, 'moneda' => $g->moneda]) }}"
                       class="text-xs text-indigo-600 hover:underline">
                        Ver y editar el reparto entre compras
                    </a>
                </p>
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
                <input type="hidden" name="panel" value="{{ $panel }}">
                <p class="text-sm text-gray-700">
                    Anotar lo que dice el recibo de {{ $g->beneficiario }} · {{ $g->concepto }}.
                </p>
                @foreach ($otrosErrores as $mensaje)
                    <p class="text-sm text-red-600">{{ $mensaje }}</p>
                @endforeach
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $etiqueta }}" for="r-importe-{{ $g->id }}">Importe del recibo</label>
                        <input id="r-importe-{{ $g->id }}" name="importe" required inputmode="decimal" value="{{ $escrito('importe') }}"
                               {{ $conAviso('importe', 'r-importe-'.$g->id) }} @if ($foco === 'importe') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}" placeholder="0.00">
                        @if ($volvio)
                            @error('importe')<p id="r-importe-{{ $g->id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                    <div>
                        <label class="{{ $etiqueta }}" for="r-vence-{{ $g->id }}">Vence el (opcional)</label>
                        <input id="r-vence-{{ $g->id }}" name="vence" type="date" value="{{ $escrito('vence') }}"
                               {{ $conAviso('vence', 'r-vence-'.$g->id) }} @if ($foco === 'vence') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}">
                        @if ($volvio)
                            @error('vence')<p id="r-vence-{{ $g->id }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                        <p class="mt-1 text-xs text-gray-500">La fecha que trae el papel. Si no la ponés, queda sin vencimiento.</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                    <button type="submit" class="{{ $botonAccion }}">Guardar el monto</button>
                </div>
            </form>
        @endif
    </div>
</div>
