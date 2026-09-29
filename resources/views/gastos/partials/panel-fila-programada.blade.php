@php
    use App\Services\Gastos\Dinero;

    $boton = 'inline-flex min-h-9 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50';
    $botonAccion = 'inline-flex min-h-9 items-center justify-center rounded-md bg-indigo-600 px-3.5 text-xs font-medium text-white hover:bg-indigo-500';
    $campo = 'mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm';
    $etiqueta = 'block text-sm font-medium text-gray-700';

    $metodos = config('gastos.metodos');
    $hoy = now()->toDateString();

    // Identificador del panel: regla + período. Dos filas de la misma regla —octubre y
    // noviembre— tienen que poder abrirse por separado.
    $panel = 'prog-'.$f->regla_id.'-'.str_replace(['-', ' '], '_', $f->periodo);

    $variable = $f->monto_modo === 'variable';
    $esPasada = $tipo === 'pasada';
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
     crea nada: el formulario se despliega en el navegador y no toca el servidor. --}}
<div class="border-t border-gray-100 first:border-t-0">
    <div class="flex items-center justify-between gap-3 px-4 py-3">
        <div class="min-w-0">
            <p class="truncate font-medium text-gray-900">{{ $f->beneficiario }}</p>
            <p class="truncate text-sm text-gray-500">
                {{ $f->concepto }} ·
                {{ $esPasada ? 'vencía el '.$vence.' y no se registró' : 'vence el '.$vence }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-3">
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
                    <button type="button" @click="abrir('{{ $panel }}')" class="{{ $botonAccion }}">Registrar pago</button>
                @endcan
            @endcan
        </div>
    </div>

    <div x-show="abierto === '{{ $panel }}'" x-cloak class="border-t border-gray-100 bg-gray-50 px-4 py-4">
        <form method="POST" action="{{ route('gastos.panel.programado.pagar', $f->regla_id) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="clave" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            {{-- El período viaja acá, y el servidor lo revalida contra el calendario de
                 la regla: un valor inventado no pasa. --}}
            <input type="hidden" name="periodo" value="{{ $f->periodo }}">

            <p class="text-sm text-gray-700">
                Pagar <span class="font-semibold">{{ $f->beneficiario }}</span> · {{ $f->concepto }}
                · período {{ $f->periodo }}, con vencimiento {{ $vence }}.
            </p>

            <div class="grid gap-3 sm:grid-cols-3">
                @if ($variable)
                    <div>
                        <label class="{{ $etiqueta }}" for="pg-imp-{{ $panel }}">Importe del recibo</label>
                        <input id="pg-imp-{{ $panel }}" name="importe" required inputmode="decimal" class="{{ $campo }}" placeholder="0.00">
                    </div>
                @endif
                <div>
                    <label class="{{ $etiqueta }}" for="pg-fecha-{{ $panel }}">Cuándo pagaste</label>
                    <input id="pg-fecha-{{ $panel }}" name="fecha" type="date" required value="{{ $hoy }}" max="{{ $hoy }}" class="{{ $campo }}">
                </div>
                <div>
                    <label class="{{ $etiqueta }}" for="pg-metodo-{{ $panel }}">Cómo</label>
                    <select id="pg-metodo-{{ $panel }}" name="metodo" class="{{ $campo }}">
                        @foreach ($metodos as $valor => $texto)
                            <option value="{{ $valor }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $etiqueta }}" for="pg-ref-{{ $panel }}">Referencia</label>
                    <input id="pg-ref-{{ $panel }}" name="referencia" maxlength="180" class="{{ $campo }}" placeholder="Opcional">
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="{{ $botonAccion }}">
                    @if ($variable)
                        Registrar la obligación y el pago
                    @else
                        Registrar ${{ Dinero::mostrar((int) $importe) }} pagados
                    @endif
                </button>
                <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
            </div>

            <p class="text-xs text-gray-500">
                Al confirmar se crea la obligación de este período y se registra el pago, en una sola operación. Si ya
                existiera, se usa esa misma: no se duplica. Cancelar no deja nada creado. El comprobante se adjunta
                después, desde el detalle del pago.
            </p>
        </form>
    </div>
</div>
