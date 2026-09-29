@php
    use App\Models\Gastos\Regla;

    /* Una clase por estado de Regla::ESTADOS. El acceso es directo —$insignia[...]—,
       así que un estado sin entrada acá tumba la pantalla entera con «Undefined array
       key». Pasó al agregar «borrador»: el listado y el detalle dieron 500.

       Si mañana aparece otro estado, este mapa es lo primero que hay que tocar. El
       `?? ` del final evita que vuelva a ser un 500, pero no sustituye a completarlo. */
    $insignia = [
        'borrador' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'activa' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'pausada' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'cancelada' => 'bg-gray-100 text-gray-600 ring-gray-500/20',
    ];
    $claseEstado = fn (string $e) => $insignia[$e] ?? 'bg-gray-100 text-gray-600 ring-gray-500/20';

    /* Qué le falta a un borrador, dicho en la frase que va dentro de «todavía no sabe
       …». `null` = no le falta ni el día ni el importe, así que el pendiente es otro y
       no se puede nombrar desde acá: lo dice la regla en sus observaciones. */
    $comoSeLlama = ['el día de cobro' => 'qué día se cobra', 'el importe' => 'cuánto se cobra'];
    $faltantes = $regla->faltantes();
    $faltaEnPalabras = $faltantes === []
        ? null
        : implode(' ni ', array_map(fn (string $f) => $comoSeLlama[$f] ?? $f, $faltantes));
    $boton = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">{{ $regla->nombre }}</h1>
                <p class="text-sm text-gray-500">{{ $regla->beneficiario }} · {{ $calendario->enPalabras($regla->calendario()) }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('gastos.reglas.index') }}" class="{{ $boton }}">Volver</a>
                @can('gastos.recurrencias')
                    @if ($regla->estado !== 'cancelada')
                        <a href="{{ route('gastos.reglas.edit', $regla) }}" class="{{ $boton }}">Editar</a>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-4">
            <x-gastos-aviso />

            {{-- Lo primero, y en grande: CUÁNDO cae el siguiente. Es lo que se viene a
                 mirar acá; el resto es configuración. --}}
            @if ($proximo !== null)
                <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">El próximo vence</p>
                    <p class="text-2xl font-semibold tabular-nums text-indigo-900">{{ $proximo['vence'] }}</p>
                    <p class="text-xs text-indigo-800">
                        Se va a crear {{ $regla->dias_generar_antes > 0 ? $regla->dias_generar_antes.' día(s) antes' : 'ese mismo día' }}, sin pagar.
                    </p>
                </div>
            @elseif ($regla->porCompletar())
                {{-- Lo primero que se ve en una regla a medio llenar: QUÉ le falta. No un
                     hueco donde iría una fecha, ni una lista de próximos vencimientos que
                     no se van a generar —prometer eso sería peor que no decir nada—.

                     Y le falta lo que le falta DE VERDAD. Este bloque afirmaba siempre que
                     no sabía qué día se cobra, incluso sobre una regla que ya tenía el día
                     guardado: el dato pendiente era otro —el nombre de la institución— y
                     estaba anotado en las observaciones, a la vista de nadie. --}}
                <div class="rounded-lg border-2 border-dashed border-sky-300 bg-sky-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sky-800">Falta completar</p>
                    @if ($faltaEnPalabras !== null)
                        <p class="mt-1 text-sm text-sky-900">
                            Esta repetición está guardada, pero <strong>todavía no sabe {{ $faltaEnPalabras }}</strong>.
                            Mientras siga así no crea ninguna obligación y no aparece en «Por pagar».
                        </p>
                    @else
                        <p class="mt-1 text-sm text-sky-900">
                            El calendario y el importe <strong>ya están</strong>: {{ $calendario->enPalabras($regla->calendario()) }}@if ($regla->monto_modo === 'fijo'), {{ $regla->moneda }} {{ $regla->importe }}@endif.
                            Quedó como borrador por un dato que había que confirmar aparte, y hasta
                            confirmarlo no crea ninguna obligación ni aparece en «Por pagar».
                        </p>
                        @if (filled($regla->observaciones))
                            <p class="mt-2 rounded-md bg-sky-100 px-3 py-2 text-sm text-sky-900">
                                <span class="font-medium">Lo que se anotó al guardarla:</span> {{ $regla->observaciones }}
                            </p>
                        @else
                            <p class="mt-2 text-sm text-sky-900">
                                No quedó anotado cuál era. Revisala antes de activarla.
                            </p>
                        @endif
                    @endif
                </div>
            @elseif ($regla->estado === 'activa')
                <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                    No viene ningún vencimiento nuevo con las fechas actuales.
                </div>
            @endif

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $claseEstado($regla->estado) }}">{{ Regla::ESTADOS[$regla->estado] }}</span>
                    <span class="text-xs text-gray-500">versión {{ $regla->version }}</span>
                    @if ($regla->ambito === 'personal')
                        <span class="rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700 ring-1 ring-inset ring-purple-600/20">Personal</span>
                    @endif
                </div>

                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="inline text-gray-500">Concepto:</dt> <dd class="inline text-gray-900">{{ $regla->concepto }}</dd></div>
                    <div><dt class="inline text-gray-500">Categoría:</dt> <dd class="inline text-gray-900">{{ $regla->categoria }}</dd></div>
                    <div>
                        <dt class="inline text-gray-500">Monto:</dt>
                        <dd class="inline text-gray-900">
                            {{ $regla->monto_modo === 'fijo' ? $regla->moneda.' '.$regla->importe : 'Variable (llega recibo)' }}
                        </dd>
                    </div>
                    <div><dt class="inline text-gray-500">Responsable:</dt> <dd class="inline text-gray-900">{{ $regla->responsable?->name }}</dd></div>
                    <div><dt class="inline text-gray-500">Desde / hasta:</dt> <dd class="inline text-gray-900">{{ $regla->vigente_desde?->format('Y-m-d') }} → {{ $regla->vigente_hasta?->format('Y-m-d') ?? 'sin fin' }}</dd></div>
                    <div><dt class="inline text-gray-500">Se crea:</dt> <dd class="inline text-gray-900">{{ $regla->dias_generar_antes }} día(s) antes del vencimiento</dd></div>
                </dl>

                @if ($regla->estado === 'pausada' && $regla->motivo_pausa)
                    <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <strong>Pausada:</strong> {{ $regla->motivo_pausa }}
                        <span class="block text-xs">No genera períodos nuevos. Las obligaciones que ya creó siguen exigibles.</span>
                    </p>
                @endif

                @if ($regla->estado === 'cancelada' && $regla->motivo_cancelacion)
                    <p class="mt-3 rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        <strong>Cancelada:</strong> {{ $regla->motivo_cancelacion }}
                        <span class="block text-xs">Lo que ya generó sigue vivo: si alguna obligación no corresponde, resolvela una por una.</span>
                    </p>
                @endif
            </section>

            @can('gastos.recurrencias')
                <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Acciones</h2>

                    {{-- Completar la programación es la única acción que tiene sentido en
                         una regla a medio llenar, y la que la vuelve activa. Se pide solo lo
                         que falta: el resto ya está guardado. --}}
                    @if ($regla->porCompletar())
                        <form method="POST" action="{{ route('gastos.reglas.activar', $regla) }}"
                              class="space-y-3 rounded-md border border-sky-200 bg-sky-50 p-3">
                            @csrf
                            <p class="text-sm font-medium text-gray-700">Completar y activar</p>

                            <div class="grid gap-3 sm:grid-cols-2">
                                {{-- A quién se le paga va SIEMPRE, y prellenado. Es el dato que
                                     queda pendiente cuando el día ya está —el nombre de la
                                     institución—, y sin este campo había que irse a «Editar» a
                                     buscarlo, o peor, crear una regla nueva al lado. --}}
                                <div class="sm:col-span-2">
                                    <label for="beneficiario" class="block text-xs text-gray-600">¿A quién se le paga?</label>
                                    <input id="beneficiario" name="beneficiario" type="text" required maxlength="180"
                                           value="{{ old('beneficiario', $regla->beneficiario) }}"
                                           class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                                    @error('beneficiario') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                @if ($regla->frecuencia === 'semanal')
                                    <div>
                                        <label for="dia-semana" class="block text-xs text-gray-600">¿Qué día de la semana?</label>
                                        <select id="dia-semana" name="dia_semana" required class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                                            <option value="">Elegir…</option>
                                            @foreach (App\Services\Gastos\Recurrencia\CalendarioRecurrencia::DIAS_SEMANA as $n => $texto)
                                                <option value="{{ $n }}" @selected((int) old('dia_semana', $regla->dia_semana) === $n)>{{ $texto }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @else
                                    <div>
                                        <label for="dia-mes" class="block text-xs text-gray-600">
                                            {{ $regla->frecuencia === 'quincenal' ? 'Primera fecha del mes' : '¿Qué día del mes?' }}
                                        </label>
                                        <input id="dia-mes" name="dia_mes" type="number" min="1" max="31" required
                                               value="{{ old('dia_mes', $regla->dia_mes) }}" class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                                        <p class="mt-1 text-xs text-gray-500">31 = el último día del mes.</p>
                                    </div>
                                @endif

                                @if ($regla->frecuencia === 'quincenal')
                                    <div>
                                        <label for="dia-mes-2" class="block text-xs text-gray-600">Segunda fecha del mes</label>
                                        <input id="dia-mes-2" name="dia_mes_2" type="number" min="1" max="31" required
                                               value="{{ old('dia_mes_2', $regla->dia_mes_2) }}" class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                                    </div>
                                @endif

                                @if ($regla->frecuencia === 'anual')
                                    <div>
                                        <label for="mes" class="block text-xs text-gray-600">¿Qué mes?</label>
                                        <select id="mes" name="mes" required class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                                            <option value="">Elegir…</option>
                                            @foreach (range(1, 12) as $m)
                                                <option value="{{ $m }}" @selected((int) old('mes', $regla->mes) === $m)>{{ $calendario->nombreMes($m) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </div>

                            <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-sky-700 px-4 text-sm font-semibold text-white hover:bg-sky-800">
                                Completar y activar
                            </button>
                            <p class="text-xs text-gray-500">
                                Rige <strong>desde el {{ $regla->vigente_desde?->format('Y-m-d') }}</strong>@if ($regla->vigente_hasta !== null)
                                    y <strong>hasta el {{ $regla->vigente_hasta->format('Y-m-d') }}</strong>@endif,
                                tal como se guardó: completarla no mueve la vigencia. Activarla tampoco
                                crea ninguna deuda; los períodos se generan cuando vos lo pidas.
                            </p>
                        </form>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        @if ($regla->estado === 'activa')
                            <form method="POST" action="{{ route('gastos.reglas.generar', $regla) }}">
                                @csrf
                                <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                                    Crear los que ya tocan
                                </button>
                            </form>
                        @endif
                    </div>

                    @if ($regla->estado === 'activa')
                        <p class="text-xs text-gray-500">
                            Crea las obligaciones de los períodos que ya deberían existir. Es idempotente: apretarlo dos veces
                            no duplica nada. Las obligaciones nacen <strong>sin pagar</strong>.
                        </p>
                    @endif

                    {{-- Los tres cambios de estado exigen motivo. Van en formularios simples y
                         no en un modal: el motivo tiene que escribirse, no aceptarse de apuro. --}}
                    <div class="grid gap-3 sm:grid-cols-2">
                        @if ($regla->estado === 'activa')
                            <form method="POST" action="{{ route('gastos.reglas.pausar', $regla) }}" class="space-y-2 rounded-md border border-gray-200 p-3">
                                @csrf
                                <label for="motivo-pausa" class="block text-sm font-medium text-gray-700">Pausar</label>
                                <input id="motivo-pausa" name="motivo" type="text" required minlength="5" maxlength="500"
                                       placeholder="Motivo (el local está cerrado por remodelación)"
                                       class="block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <button type="submit" class="{{ $boton }}">Pausar la generación</button>
                            </form>
                        @endif

                        @if ($regla->estado === 'pausada')
                            <form method="POST" action="{{ route('gastos.reglas.reanudar', $regla) }}" class="space-y-2 rounded-md border border-gray-200 p-3">
                                @csrf
                                <label for="motivo-reanudar" class="block text-sm font-medium text-gray-700">Reanudar</label>
                                <input id="motivo-reanudar" name="motivo" type="text" required minlength="5" maxlength="500"
                                       placeholder="Motivo (volvimos a ocupar el local)"
                                       class="block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <button type="submit" class="{{ $boton }}">Reanudar</button>
                            </form>
                        @endif

                        @if ($regla->estado !== 'cancelada' && ! $regla->porCompletar())
                            <form method="POST" action="{{ route('gastos.reglas.cancelar', $regla) }}" class="space-y-2 rounded-md border border-red-200 p-3">
                                @csrf
                                <label for="motivo-cancelar" class="block text-sm font-medium text-gray-700">Cancelar del todo</label>
                                <input id="motivo-cancelar" name="motivo" type="text" required minlength="5" maxlength="500"
                                       placeholder="Motivo (se terminó el contrato)"
                                       class="block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <button type="submit" class="inline-flex min-h-11 items-center rounded-md border border-red-300 bg-white px-4 text-sm font-medium text-red-700 hover:bg-red-50">
                                    Cancelar definitivamente
                                </button>
                                <p class="text-xs text-gray-500">No borra ninguna obligación ya creada.</p>
                            </form>
                        @endif
                    </div>
                </section>
            @endcan

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Lo que viene</h2>

                @if ($proximos === [])
                    <p class="mt-2 text-sm text-gray-600">No vienen períodos nuevos con la vigencia actual.</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($proximos as $p)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    <span class="font-medium text-gray-900">{{ $p['periodo'] }}</span>
                                    <span class="text-gray-500">· vence {{ $p['vence'] }}</span>
                                </span>

                                @if ($p['resuelto'] === 'generada')
                                    <span class="text-xs font-medium text-emerald-700">Ya generado</span>
                                @elseif ($p['resuelto'] === 'omitida')
                                    <span class="text-xs font-medium text-gray-500">Omitido</span>
                                @else
                                    @can('gastos.recurrencias')
                                        <form method="POST" action="{{ route('gastos.reglas.omitir', $regla) }}" class="flex flex-wrap items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="periodo" value="{{ $p['periodo'] }}">
                                            <label for="omitir-{{ $p['periodo'] }}" class="sr-only">Motivo para omitir {{ $p['periodo'] }}</label>
                                            <input id="omitir-{{ $p['periodo'] }}" name="motivo" type="text" required minlength="5" maxlength="500"
                                                   placeholder="Motivo para saltarlo"
                                                   class="min-h-11 rounded-md border-gray-300 text-sm shadow-sm">
                                            <button type="submit" class="{{ $boton }}">Saltar</button>
                                        </form>
                                    @endcan
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-gray-500">
                        Saltar ocupa el período con un motivo: es la única forma de decir «este no» sin que la
                        generación automática lo vuelva a crear.
                    </p>
                @endif
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Lo que ya pasó</h2>

                @if ($ocurrencias->isEmpty())
                    <p class="mt-2 text-sm text-gray-600">Todavía no se generó ningún período.</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <caption class="sr-only">Períodos generados u omitidos</caption>
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="py-2 pr-4 font-medium">Período</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Vence</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Resultado</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Versión</th>
                                    <th scope="col" class="py-2 font-medium">Quién</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($ocurrencias as $o)
                                    <tr>
                                        <td class="py-2 pr-4 font-medium text-gray-900">{{ $o->periodo }}</td>
                                        <td class="py-2 pr-4 text-gray-600">{{ $o->vence?->format('Y-m-d') ?? '—' }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($o->omitida())
                                                <span class="text-gray-500">Omitido</span>
                                                <span class="block text-xs text-gray-500">{{ $o->motivo }}</span>
                                            @elseif ($o->gasto)
                                                <a href="{{ route('gastos.show', $o->gasto) }}" class="font-medium text-indigo-600 hover:underline">Ver obligación</a>
                                            @else
                                                <span class="text-gray-500">Generado</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 tabular-nums text-gray-500">v{{ $o->version_regla }}</td>
                                        <td class="py-2 text-gray-600">{{ $o->autor() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Historial de cambios</h2>
                <ul class="mt-2 divide-y divide-gray-100 text-sm">
                    @foreach ($versiones as $v)
                        <li class="py-2">
                            <p class="font-medium text-gray-900">v{{ $v->version }} · desde {{ $v->vigente_desde?->format('Y-m-d') }}</p>
                            <p class="text-gray-600">{{ $v->motivo }}</p>
                            <p class="text-xs text-gray-500">{{ $v->registrador?->name }} · {{ $v->created_at?->format('Y-m-d H:i') }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>
</x-app-layout>
