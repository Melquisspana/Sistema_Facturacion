@php
    use App\Services\Gastos\Dinero;

    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50';
    $botonAccion = 'inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-3.5 text-xs font-medium text-white hover:bg-indigo-500';
    $campo = 'mt-1 min-h-11 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm';
    $etiqueta = 'block text-sm font-medium text-gray-700';

    $metodos = config('gastos.metodos');
    $hoy = now()->toDateString();

    // Identificador del panel: regla + período. Dos filas de la misma regla —octubre y
    // noviembre— tienen que poder abrirse por separado.
    $panel = 'prog-'.$f->regla_id.'-'.str_replace(['-', ' '], '_', $f->periodo);
    $panelSaltar = 'saltar-'.$f->regla_id.'-'.str_replace(['-', ' '], '_', $f->periodo);

    $variable = $f->monto_modo === 'variable';
    $esPasada = $tipo === 'pasada';

    // «Registrar pago» que volvió con error: se reabre como «Saltar», con lo escrito, el
    // aviso debajo de su campo y el foco en el primero que falló. Solo esta fila.
    $visibles = $variable ? ['importe', 'fecha', 'metodo', 'referencia'] : ['fecha', 'metodo', 'referencia'];
    $volvio = $errors->any() && old('panel') === $panel;
    $escrito = fn (string $campo, string $siNo = '') => $volvio ? (is_string(old($campo)) ? old($campo) : '') : $siNo;
    $foco = $volvio ? (collect($visibles)->first(fn ($c) => $errors->has($c)) ?? $visibles[0]) : null;
    // Lo que no es de un campo a la vista (período, clave, pago…) se lee igual acá adentro.
    $otrosErrores = $volvio ? collect($errors->getMessages())->except($visibles)->flatten() : collect();
    // Al volver con error se conserva la MISMA clave: si ese envío sí llegó a guardarse
    // (doble clic), reenviarlo no registra otro pago; si no, la clave sigue libre.
    $clave = $volvio && \Illuminate\Support\Str::isUuid(old('clave')) ? old('clave') : (string) \Illuminate\Support\Str::uuid();
@endphp

{{-- Una fila PROGRAMADA: un período que la regla producirá (o ya debió producir) y que
     todavía no existe como obligación en la base.

     ── Por qué tiene botón de pagar, si «no existe» ──────────────────────────────
     Porque el dinero sí existe. Que el registro no esté creado no demuestra que no se
     deba: solo demuestra que nadie lo registró. Obligar a ir a otra pantalla a
     «generar la ocurrencia» antes de poder pagar es hacerle explicar el modelo de datos
     a quien solo quiere pagar la factura de Starlink.

     Al confirmar, el servidor crea la obligación del período —o reutiliza la que ya
     haya— y registra el pago en una sola operación. Abrir este panel y cerrarlo no
     crea nada: el formulario se despliega en el navegador y no toca el servidor.

     ── «Saltar», solo en las de fecha pasada ─────────────────────────────────────
     Un mes que ya pasó y no se debe (el de una pausa, por ejemplo) no tenía salida:
     «Lo que viene» de la regla solo ofrece los futuros. Saltar usa la misma ruta que
     allá (gastos.reglas.omitir), con motivo obligatorio y su candado de período. --}}
<div class="border-t border-gray-100 first:border-t-0">
    <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
        <div class="min-w-0">
            <p class="truncate font-medium text-gray-900">{{ $f->beneficiario }}</p>
            <p class="text-sm text-gray-500">
                {{ $f->concepto }} ·
                {{ $esPasada ? 'vencía el '.$vence.' y no se registró' : 'vence el '.$vence }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 sm:shrink-0">
            @if ($f->ambito === 'personal')
                <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[11px] font-medium text-violet-700">Casa</span>
            @else
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">Empresa</span>
            @endif

            <span class="whitespace-nowrap tabular-nums {{ $esPasada ? 'text-sm text-gray-500' : 'text-gray-700' }}">
                @if ($importe === null)
                    <span class="text-sm italic text-gray-500">llega el recibo</span>
                @else
                    {{ $esPasada ? 'previsto ' : '' }}${{ Dinero::mostrar((int) $importe) }}
                @endif
            </span>

            @can('gastos.pagos.registrar')
                @can('gastos.registrar')
                    <button type="button" @click="abrir('{{ $panel }}')" class="{{ $botonAccion }}"
                            :aria-expanded="abierto === '{{ $panel }}' ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $panel }}">
                        Registrar pago<span class="sr-only"> de {{ $f->beneficiario }}, período {{ $f->periodo }}</span>
                    </button>
                @endcan
            @endcan

            @if ($esPasada)
                @can('gastos.recurrencias')
                    <button type="button" @click="abrir('{{ $panelSaltar }}')" class="{{ $boton }}"
                            :aria-expanded="abierto === '{{ $panelSaltar }}' ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $panelSaltar }}">
                        Saltar<span class="sr-only"> {{ $f->beneficiario }}, período {{ $f->periodo }}</span>
                    </button>
                @endcan
            @endif
        </div>
    </div>

    @if ($esPasada)
        @can('gastos.recurrencias')
            <div id="{{ $panelSaltar }}" x-show="abierto === '{{ $panelSaltar }}'" x-cloak class="border-t border-gray-100 bg-gray-50 px-4 py-4">
                <form method="POST" action="{{ route('gastos.reglas.omitir', $f->regla_id) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="periodo" value="{{ $f->periodo }}">
                    {{-- Si vuelve con error, esta fila se abre sola y conserva lo escrito:
                         todas las filas mandan «motivo», y sin esto se veía en todas. --}}
                    <input type="hidden" name="panel" value="{{ $panelSaltar }}">

                    <p class="text-sm text-gray-700">
                        Saltar <span class="font-semibold">{{ $f->beneficiario }}</span> · {{ $f->concepto }}
                        · período {{ $f->periodo }}, que vencía el {{ $vence }}.
                    </p>

                    <div>
                        <label class="{{ $etiqueta }}" for="saltar-motivo-{{ $panelSaltar }}">Por qué no se debe</label>
                        <input id="saltar-motivo-{{ $panelSaltar }}" name="motivo" type="text" required minlength="5" maxlength="500"
                               value="{{ old('panel') === $panelSaltar ? old('motivo') : '' }}"
                               @if (old('panel') === $panelSaltar) x-init="$nextTick(() => $el.focus())" @endif
                               class="{{ $campo }}" placeholder="Por ejemplo: no se usó el servicio">
                        @if (old('panel') === $panelSaltar)
                            @error('motivo')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            @error('periodo')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>

                    <p class="text-sm font-medium text-amber-800">
                        No se puede deshacer: después ya no se puede pagar este período desde la regla. Usalo solo si
                        estás seguro de que no se debe.
                    </p>

                    <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                        <button type="submit" class="{{ $botonAccion }}">Saltar este período</button>
                    </div>

                    <p class="text-xs text-gray-500">
                        Sale de esta lista, no se crea la obligación y la generación automática ya no lo vuelve a
                        crear. Queda anotado en la regla con tu motivo. Cancelar no deja nada guardado.
                    </p>
                </form>
            </div>
        @endcan
    @endif

    <div id="{{ $panel }}" x-show="abierto === '{{ $panel }}'" x-cloak class="border-t border-gray-100 bg-gray-50 px-4 py-4">
        <form method="POST" action="{{ route('gastos.panel.programado.pagar', $f->regla_id) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="clave" value="{{ $clave }}">
            <input type="hidden" name="panel" value="{{ $panel }}">
            {{-- El período viaja acá, y el servidor lo revalida contra el calendario de
                 la regla: un valor inventado no pasa. --}}
            <input type="hidden" name="periodo" value="{{ $f->periodo }}">

            <p class="text-sm text-gray-700">
                Pagar <span class="font-semibold">{{ $f->beneficiario }}</span> · {{ $f->concepto }}
                · período {{ $f->periodo }}, con vencimiento {{ $vence }}.
            </p>
            @foreach ($otrosErrores as $mensaje)
                <p class="text-sm text-red-600">{{ $mensaje }}</p>
            @endforeach

            <div class="grid gap-3 sm:grid-cols-3">
                @if ($variable)
                    <div>
                        <label class="{{ $etiqueta }}" for="pg-imp-{{ $panel }}">Importe del recibo</label>
                        <input id="pg-imp-{{ $panel }}" name="importe" required inputmode="decimal" value="{{ $escrito('importe') }}"
                               @if ($foco === 'importe') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}" placeholder="0.00">
                        @if ($volvio)
                            @error('importe')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                @endif
                <div>
                    <label class="{{ $etiqueta }}" for="pg-fecha-{{ $panel }}">Cuándo pagaste</label>
                    <input id="pg-fecha-{{ $panel }}" name="fecha" type="date" required value="{{ $escrito('fecha', $hoy) }}" max="{{ $hoy }}"
                           @if ($foco === 'fecha') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}">
                    @if ($volvio)
                        @error('fecha')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    @endif
                </div>
                <div>
                    <label class="{{ $etiqueta }}" for="pg-metodo-{{ $panel }}">Cómo</label>
                    <select id="pg-metodo-{{ $panel }}" name="metodo"
                            @if ($foco === 'metodo') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}">
                        @foreach ($metodos as $valor => $texto)
                            <option value="{{ $valor }}" @selected($volvio && old('metodo') === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                    @if ($volvio)
                        @error('metodo')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    @endif
                </div>
                <div>
                    <label class="{{ $etiqueta }}" for="pg-ref-{{ $panel }}">Referencia</label>
                    <input id="pg-ref-{{ $panel }}" name="referencia" maxlength="180" value="{{ $escrito('referencia') }}"
                           @if ($foco === 'referencia') x-init="$nextTick(() => $el.focus())" @endif class="{{ $campo }}" placeholder="Opcional">
                    @if ($volvio)
                        @error('referencia')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    @endif
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                <button type="submit" class="{{ $botonAccion }}">
                    @if ($variable)
                        Registrar la obligación y el pago
                    @else
                        Registrar ${{ Dinero::mostrar((int) $importe) }} pagados
                    @endif
                </button>
            </div>

            <p class="text-xs text-gray-500">
                Al confirmar se crea la obligación de este período y se registra el pago, en una sola operación. Si ya
                existiera, se usa esa misma: no se duplica. Cancelar no deja nada creado. El comprobante se adjunta
                después, desde el detalle del pago.
            </p>
        </form>
    </div>
</div>
