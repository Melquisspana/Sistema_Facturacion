@php
    use App\Services\Gastos\Dinero;

    $metodos = config('gastos.metodos');

    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
    $botonAccion = 'inline-flex min-h-9 items-center justify-center rounded-md bg-indigo-600 px-3.5 text-sm font-medium text-white hover:bg-indigo-500';
    $campo = 'mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm';
    $etiqueta = 'block text-sm font-medium text-gray-700';

    // Cada fila lleva su ámbito como una marca discreta. Es informativa: el candado de
    // verdad ya se aplicó en la consulta, no acá.
    $chipAmbito = fn (string $a) => $a === 'personal'
        ? '<span class="rounded bg-violet-50 px-1.5 py-0.5 text-[11px] font-medium text-violet-700">Casa</span>'
        : '<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">Empresa</span>';

    $fecha = fn (?string $iso) => $iso ? \Carbon\CarbonImmutable::parse($iso)->format('d/m') : null;
@endphp

{{-- LA PANTALLA PRINCIPAL DE GASTOS Y PAGOS

     Dos cifras arriba que NUNCA se suman entre sí, y debajo la lista completa agrupada
     por lo que hay que hacer con cada cosa.

     ── Por qué las dos cifras están separadas ────────────────────────────────────
     «Se debe hoy» son obligaciones reales: filas de la base, con su cuota y su saldo.
     «Se espera este mes» son previsiones: fechas calculadas a partir de reglas activas,
     que todavía no existen como deuda. Están en cajas distintas, con color distinto, y
     en ningún punto de esta plantilla se las suma. Tampoco hay una variable que las
     contenga sumadas: el servicio devuelve `se_debe` y `se_espera` por separado a
     propósito.

     ── Lo que NO se dibuja ───────────────────────────────────────────────────────
     Una previsión no tiene botón de pagar. No se puede pagar lo que todavía no existe:
     cuando la regla la genere, bajará sola al grupo de arriba con su botón.

     Los importes desconocidos se muestran como «llega el recibo», nunca como 0.00. Un
     monto que no se sabe no es cero, y escribirlo como cero sería inventar. --}}
<x-app-layout>
    <x-slot name="header">
        {{-- Los accesos secundarios, en el orden en que se necesitan: a quién le debo,
             qué pagué, qué se repite, y el resumen. Es el mismo orden del menú lateral
             —no dos ordenaciones distintas de lo mismo— y ninguno de ellos es parte del
             recorrido habitual: para eso está la pantalla de abajo.

             «Buscar y filtrar» lleva al listado completo, que conserva la búsqueda por
             texto, los filtros por ámbito, categoría, moneda y responsable, y las seis
             pestañas. Va aparte y en tono menor porque responde a otra necesidad: no
             «qué hay que hacer hoy» sino «encontrame esto concreto». --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Gastos y pagos</h1>
                <p class="text-sm capitalize text-gray-500">{{ $datos['mes'] }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('gastos.cuentas') }}" class="{{ $boton }}">Cuentas</a>
                <a href="{{ route('gastos.pagos.index') }}" class="{{ $boton }}">Historial</a>
                @if ($fase2)
                    <a href="{{ route('gastos.reglas.index') }}" class="{{ $boton }}">Gastos que se repiten</a>
                @endif
                <a href="{{ route('gastos.informes') }}" class="{{ $boton }}">Informes</a>
                <a href="{{ route('gastos.index') }}" class="text-sm text-gray-500 underline underline-offset-2 hover:text-gray-700">
                    Buscar y filtrar
                </a>
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6"
         x-data="{ abierto: null, abrir(id) { this.abierto = this.abierto === id ? null : id } }">
        <div class="mx-auto max-w-4xl space-y-4">

            {{-- El aviso puede traer un enlace al registro que se acaba de crear. Volver
                 al panel no puede costar perder de vista lo recién hecho: el resumen es
                 el sitio donde se trabaja, pero el detalle sigue a un clic. --}}
            @if (session('gastos.aviso'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">
                    {{ session('gastos.aviso') }}
                    @if (is_array(session('gastos.aviso_enlace')))
                        <a href="{{ session('gastos.aviso_enlace')['url'] }}" class="ml-1 font-medium underline underline-offset-2">
                            {{ session('gastos.aviso_enlace')['texto'] }}
                        </a>
                    @endif
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md border border-red-300 bg-red-50 px-4 py-2.5 text-sm text-red-800" role="alert">
                    <p class="font-medium">No se pudo guardar:</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ══════════════ LAS DOS CIFRAS ══════════════
                 Deliberadamente en dos tarjetas separadas, de distinto color, sin ningún
                 total que las abarque. --}}
            <div class="grid gap-3 sm:grid-cols-2">
                {{-- «Saldo registrado» y no «confirmado»: que algo esté anotado no quiere
                     decir que esté respaldado. Parte de este total son saldos acordados
                     de palabra, y eso se dice en vez de presentarlo todo como cerrado. --}}
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Se debe hoy</p>
                    <p class="mt-1 text-3xl font-bold tabular-nums text-emerald-800">
                        ${{ Dinero::mostrar((int) $datos['se_debe']) }}
                    </p>
                    <p class="mt-1 text-xs text-emerald-800">
                        Saldo registrado.
                        @if ($datos['se_debe_provisional'] > 0)
                            De eso, <span class="font-semibold">${{ Dinero::mostrar((int) $datos['se_debe_provisional']) }}</span>
                            es provisional y espera documento.
                        @else
                            Todo respaldado con documento.
                        @endif
                    </p>
                </div>

                {{-- «Programado · pendiente de confirmar» y no «todavía no se debe»: que el
                     registro no exista no demuestra que no debamos ese dinero. --}}
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Se espera este mes</p>
                    <p class="mt-1 text-3xl font-bold tabular-nums text-amber-800">
                        ${{ Dinero::mostrar((int) $datos['se_espera']) }}
                    </p>
                    <p class="mt-1 text-xs text-amber-800">
                        Programado · pendiente de confirmar.
                        @if ($datos['esperando_recibo']->isNotEmpty())
                            Más {{ $datos['esperando_recibo']->count() }} que esperan su recibo.
                        @endif
                    </p>
                </div>
            </div>

            <p class="px-1 text-xs text-gray-500">
                Las dos cifras no se suman en ningún lado: una es lo que está registrado y la otra lo que está
                programado.
            </p>

            {{-- Filtro de comodidad. El candado de ámbito ya se aplicó en la consulta. --}}
            @can('gastos.personales')
                <div class="flex flex-wrap gap-2">
                    @foreach (['' => 'Todo', 'empresarial' => 'Empresa', 'personal' => 'Casa'] as $valor => $texto)
                        <a href="{{ route('gastos.panel', array_filter(['ambito' => $valor])) }}"
                           class="rounded-full border px-3 py-1 text-sm {{ (string) $ambito === (string) $valor ? 'border-indigo-500 bg-indigo-50 font-medium text-indigo-700' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50' }}">
                            {{ $texto }}
                        </a>
                    @endforeach
                </div>
            @endcan

            {{-- ══════════════ ACCIONES QUE NO SALEN DE UNA FILA ══════════════ --}}
            <div class="flex flex-wrap gap-2">
                @can('gastos.registrar')
                    @can('gastos.pagos.registrar')
                        <button type="button" @click="abrir('compra')" class="{{ $botonAccion }}">Compré y pagué</button>
                    @endcan
                    <a href="{{ route('gastos.create') }}" class="{{ $boton }}">Anotar un gasto</a>
                    <a href="{{ route('gastos.cuentas') }}" class="{{ $boton }}">Agregar compra a cuenta</a>
                @endcan
            </div>

            {{-- Compré y pagué: el gasto y el pago nacen juntos. Nunca existe la deuda. --}}
            @can('gastos.registrar')
                @can('gastos.pagos.registrar')
                    <div x-show="abierto === 'compra'" x-cloak class="rounded-lg border border-indigo-200 bg-white p-4 shadow-sm">
                        <form method="POST" action="{{ route('gastos.panel.compras') }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="clave" value="{{ $clave }}">
                            <input type="hidden" name="moneda" value="USD">
                            <p class="font-semibold text-gray-900">Compré y pagué</p>
                            <p class="text-xs text-gray-500">Gasto y pago en una sola operación. No queda ninguna deuda abierta.</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="{{ $etiqueta }}" for="c-concepto">Qué compraste</label>
                                    <input id="c-concepto" name="concepto" required maxlength="200" class="{{ $campo }}" placeholder="Bolsas para empaque">
                                </div>
                                <div>
                                    <label class="{{ $etiqueta }}" for="c-importe">Cuánto</label>
                                    <input id="c-importe" name="importe" required inputmode="decimal" class="{{ $campo }}" placeholder="0.00">
                                </div>
                                <div>
                                    <label class="{{ $etiqueta }}" for="c-beneficiario">A quién</label>
                                    <input id="c-beneficiario" name="beneficiario" maxlength="180" class="{{ $campo }}" placeholder="Opcional">
                                </div>
                                <div>
                                    <label class="{{ $etiqueta }}" for="c-fecha">Cuándo</label>
                                    <input id="c-fecha" name="fecha" type="date" required value="{{ $hoy }}" max="{{ $hoy }}" class="{{ $campo }}">
                                </div>
                                <div>
                                    <label class="{{ $etiqueta }}" for="c-metodo">Cómo pagaste</label>
                                    <select id="c-metodo" name="metodo" class="{{ $campo }}">
                                        @foreach ($metodos as $valor => $texto)
                                            <option value="{{ $valor }}">{{ $texto }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="{{ $etiqueta }}" for="c-ambito">De quién es</label>
                                    <select id="c-ambito" name="ambito" class="{{ $campo }}">
                                        <option value="empresarial">De la empresa</option>
                                        @can('gastos.personales')
                                            <option value="personal">Personal</option>
                                        @endcan
                                    </select>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="{{ $botonAccion }}">Guardar</button>
                                <button type="button" @click="abrir(null)" class="{{ $boton }}">Cancelar</button>
                            </div>
                        </form>
                    </div>
                @endcan
            @endcan

            {{-- ══════════════ VENCIDO ══════════════ --}}
            <x-gastos-grupo titulo="Vencido" tono="rojo" compacto
                            :total="$datos['vencido']->sum('pendiente')"
                            vacio="Sin vencidos.">
                @foreach ($datos['vencido'] as $g)
                    @include('gastos.partials.panel-fila-deuda', ['g' => $g, 'accion' => 'pagar'])
                @endforeach
            </x-gastos-grupo>

            {{-- ══════════════ ESTE MES, CONFIRMADO ══════════════ --}}
            @if ($datos['este_mes']->isNotEmpty())
                <x-gastos-grupo titulo="Vence este mes" tono="verde" :total="$datos['este_mes']->sum('pendiente')">
                    @foreach ($datos['este_mes'] as $g)
                        @include('gastos.partials.panel-fila-deuda', ['g' => $g, 'accion' => 'pagar'])
                    @endforeach
                </x-gastos-grupo>
            @endif

            {{-- ══════════════ CUENTAS ABIERTAS ══════════════
                 Se debe, pero no hay fecha que reclamar. Acá viven Proveedor A, Proveedor B y
                 Distribuidora Ejemplo, S.A. de C.V., y el saldo provisional se dice con todas las letras. --}}
            @if ($datos['sin_fecha']->isNotEmpty())
                <x-gastos-grupo titulo="Cuentas abiertas · sin fecha" tono="verde" :total="$datos['sin_fecha']->sum('pendiente')">
                    @foreach ($datos['sin_fecha'] as $g)
                        @include('gastos.partials.panel-fila-deuda', ['g' => $g, 'accion' => 'abonar'])
                    @endforeach
                </x-gastos-grupo>
            @endif

            {{-- ══════════════ ESPERANDO EL RECIBO ══════════════
                 Obligaciones que YA EXISTEN y todavía no tienen importe. No suman a
                 ninguna cifra: un monto desconocido no es cero. --}}
            @if ($datos['esperando_recibo']->isNotEmpty())
                <x-gastos-grupo titulo="Esperando el recibo" tono="gris" leyenda="sin monto">
                    @foreach ($datos['esperando_recibo'] as $g)
                        @include('gastos.partials.panel-fila-deuda', ['g' => $g, 'accion' => 'completar'])
                    @endforeach
                </x-gastos-grupo>
            @endif

            {{-- ══════════════ PREVISIÓN ══════════════
                 Caja aparte, color aparte, sin botón de pagar. --}}
            <x-gastos-grupo titulo="Programado · pendiente de confirmar" tono="ambar" compacto
                            :total="$datos['previsiones']->sum('importe')"
                            vacio="Nada programado para lo que queda del mes.">
                @foreach ($datos['previsiones'] as $p)
                    @include('gastos.partials.panel-fila-programada', [
                        'f' => $p,
                        'tipo' => 'prevision',
                        'importe' => $p->importe,
                        'vence' => $fecha($p->vence),
                    ])
                @endforeach
                <x-slot name="pie">
                    <x-gastos-ayuda titulo="¿Por qué está aparte del saldo registrado?">
                        Todavía no existe el registro de esta deuda, y eso no demuestra que no se deba: solo demuestra
                        que nadie la anotó. Por eso va en su propia cifra, sin sumarse al saldo registrado y sin
                        restarle nada. «Registrar pago» crea la obligación del período y la paga de una vez; si ya
                        existiera, se usa esa misma y no se duplica.
                    </x-gastos-ayuda>
                </x-slot>
            </x-gastos-grupo>

            {{-- ══════════════ FECHA PASADA · POR CONFIRMAR ══════════════
                 Previsiones posteriores al arranque cuyo día ya pasó y que nadie
                 generó. Con la generación automática apagada, esto es lo que pasa
                 solo: llega el día y no aparece la obligación.

                 NO SE BORRAN de la pantalla al vencer. Una fila que se evapora sola
                 es dinero que se deja de mencionar sin que nadie lo haya decidido.

                 Van en su propio grupo, SIN total: no son deuda confirmada —nadie
                 verificó que se deban— y tampoco son ya «lo que viene». Sin botón de
                 pagar: todavía no existen como obligación. --}}
            @if ($datos['fecha_pasada']->isNotEmpty())
                <x-gastos-grupo titulo="Fecha pasada · por confirmar" tono="ambar"
                                :leyenda="(string) $datos['fecha_pasada']->count()">
                    @foreach ($datos['fecha_pasada'] as $f)
                        @include('gastos.partials.panel-fila-programada', [
                            'f' => $f,
                            'tipo' => 'pasada',
                            'importe' => $f->importe_previsto,
                            'vence' => $fecha($f->vence),
                        ])
                    @endforeach
                    <x-slot name="pie">
                        <x-gastos-ayuda titulo="¿Por qué siguen acá y no cuentan como deuda?">
                            Ya pasó su día y nadie creó la obligación. No desaparecen al vencer —una fila que se borra
                            sola es dinero que se deja de mencionar sin que nadie lo decida— y tampoco se suman a
                            ninguna de las dos cifras, porque nadie verificó que se deban. «Registrar pago» las convierte
                            en obligación y las paga de una vez; si ya no corresponde, se pausa la regla.
                        </x-gastos-ayuda>
                    </x-slot>
                </x-gastos-grupo>
            @endif

            {{-- ══════════════ ANTERIORES AL ARRANQUE ══════════════
                 SIN CIFRA Y SIN BOTÓN, a propósito. No se presentan como deuda porque
                 nadie confirmó que se deban; se listan para que la omisión se vea. --}}
            @if ($datos['anteriores_al_arranque']->isNotEmpty())
                {{-- PLEGADO. Es una nota estable sobre cómo arrancó cada regla: no cambia
                     de un día para otro y no reclama ninguna acción. Ocupaba media
                     pantalla todos los días para decir siempre lo mismo. Cerrado por
                     defecto, pero presente: la omisión tiene que poder verse. --}}
                <details class="overflow-hidden rounded-lg border border-dashed border-gray-300 bg-white">
                    <summary class="cursor-pointer select-none px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Anteriores al arranque · sin confirmar
                        <span class="ml-1 font-normal text-gray-500">({{ $datos['anteriores_al_arranque']->count() }})</span>
                    </summary>
                    @foreach ($datos['anteriores_al_arranque'] as $a)
                        <div class="border-t border-gray-100 px-4 py-3">
                            <p class="text-sm font-medium text-gray-800">
                                {{ $a->beneficiario }} <span class="font-normal text-gray-500">· {{ $a->concepto }}</span>
                            </p>
                            <p class="text-xs text-gray-500">
                                Habría vencido el {{ $fecha($a->vence) }}. {{ $a->motivo }}
                            </p>
                        </div>
                    @endforeach
                    <x-gastos-ayuda titulo="¿Qué hago con esto?">
                        Son períodos que la regla habría producido antes de empezar a regir, así que no se generan
                        nunca solos y no entran en ninguna cifra. Se listan para que la omisión se vea y no para
                        reclamar nada: si alguno de verdad se debe, se confirma generando ese período desde su regla.
                    </x-gastos-ayuda>
                </details>
            @endif

            {{-- ══════════════ POR COMPLETAR ══════════════
                 Reglas a las que les falta el día o el importe. No se les inventa un
                 período ni un vencimiento con tal de mostrarles un botón. --}}
            @if ($datos['por_completar']->isNotEmpty())
                <x-gastos-grupo titulo="Por completar" tono="gris" :leyenda="(string) $datos['por_completar']->count()">
                    @foreach ($datos['por_completar'] as $r)
                        <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 first:border-t-0">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-900">{{ $r->beneficiario }}</p>
                                <p class="truncate text-sm text-gray-500">
                                    {{ $r->concepto }}
                                    @if ($r->importe !== null)
                                        · ${{ Dinero::mostrar((int) $r->importe) }}
                                    @endif
                                    · falta {{ $r->falta }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                {!! $chipAmbito($r->ambito) !!}
                                @can('gastos.recurrencias')
                                    <a href="{{ route('gastos.reglas.show', $r->regla_id) }}" class="{{ $boton }} min-h-9 px-3 text-xs">Completar</a>
                                @endcan
                            </div>
                        </div>
                    @endforeach
                    <x-slot name="pie">
                        <x-gastos-ayuda titulo="¿Por qué no aparecen con fecha?">
                            Mientras les falte un dato no generan nada y no aparecen como deuda. No se les inventa una
                            fecha para poder mostrarlas: un día inventado se ve idéntico a uno real y produciría deuda
                            en el día equivocado.
                        </x-gastos-ayuda>
                    </x-slot>
                </x-gastos-grupo>
            @endif

            {{-- ══════════════ YA PAGADO ══════════════ --}}
            <x-gastos-grupo titulo="Ya pagado este mes" tono="gris"
                            :total="$datos['pagado_en_el_mes']->sum('visible')"
                            vacio="Todavía nada este mes.">
                @foreach ($datos['pagado_en_el_mes'] as $p)
                    <a href="{{ route('gastos.pagos.show', $p->id) }}"
                       class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 first:border-t-0 hover:bg-indigo-50/30">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-900">
                                {{ $metodos[$p->metodo] ?? $p->metodo }}
                                @if ($p->referencia)
                                    <span class="font-normal text-gray-500">· {{ $p->referencia }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $fecha($p->fecha) }} · ver el comprobante y el detalle</p>
                        </div>
                        <span class="whitespace-nowrap tabular-nums text-gray-900">${{ Dinero::mostrar((int) $p->visible) }}</span>
                    </a>
                @endforeach
            </x-gastos-grupo>

            <details class="px-1 pb-8">
                <summary class="cursor-pointer select-none text-xs text-gray-500 hover:text-gray-700">
                    Qué sigue estando en el detalle
                </summary>
                <p class="pt-2 text-xs leading-relaxed text-gray-500">
                    El vencimiento real de cada recibo, los comprobantes de cada pago y el reparto editable de un abono
                    entre varias compras siguen donde estaban. Esta pantalla resume y da el camino corto; no reemplaza
                    ninguna de las anteriores ni les quita nada.
                </p>
            </details>
        </div>
    </div>
</x-app-layout>
