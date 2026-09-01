@props(['documento', 'salida', 'participantes', 'historial' => null])

{{-- El panel de custodia del CCF FÍSICO de UN documento, dentro de su tarjeta.

     ─────────────────────── Por qué vive acá y no en su propio módulo ───────────────────────

     La custodia se registra mientras se mira el documento: quien entrega el papel ya está en
     el detalle de la salida buscando ese correlativo. Sacarlo a una pantalla aparte obligaría
     a buscar el mismo documento dos veces, y esa fricción es exactamente la que hace que los
     registros no se hagan.

     Va plegado (`<details>`) porque una salida trae treinta documentos y ninguno necesita el
     historial abierto para saber cómo está: eso ya lo dice el resumen del `<summary>`.

     ─────────────────────── Las tres fechas que NO son la misma ───────────────────────

     Se muestran por separado y con rótulos distintos a propósito:

       ENTREGA AC01      el albarán dice que el cliente recibió la mercadería.
       CUSTODIA          dónde está el papel impreso ahora mismo.
       RECIBIDO EN       el papel firmado volvió a la oficina.
       OFICINA

     Entre la primera y la tercera pasan días, a veces meses, y a veces el papel no vuelve
     nunca. Ese hueco es lo único que este panel existe para hacer visible, así que juntarlas
     en una sola línea sería borrar la pregunta.

     ─────────────────────── Qué NO hay acá ───────────────────────

     No hay botón de «recibido». La recepción es un acto de OFICINA y vive en su propia
     pantalla con su propio permiso: si quien llevó el papel pudiera cerrar su propia
     devolución, el control no controlaría nada.

     Las acciones que se ofrecen salen de `Custodia::accionesDeCampo()`, el mismo criterio que
     el servicio vuelve a comprobar bajo llave al escribir. La pantalla no decide nada: pinta
     lo que el servidor va a aceptar, y el servidor no se fía de la pantalla. --}}

@php
    $estado = $documento->estadoCustodia();
    $tenedor = $documento->tenedorActual();
    $ultimo = $documento->ultimoEventoCustodia();
    $historial ??= collect();

    $usuario = auth()->user();
    $puedeRegistrar = $usuario?->can('rutas.custodia.registrar') ?? false;
    // Corregir es OTRA cosa que registrar: contradice algo ya asentado, así que va con su
    // propio permiso y aparece en el historial, no entre las acciones de campo.
    $puedeCorregir = $usuario?->can('rutas.custodia.corregir') ?? false;
    $cancelada = $salida->estado === \App\Enums\EstadoSalidaRuta::Cancelada;

    // Las acciones posibles por ESTADO salen del servicio; el permiso y la salida cancelada
    // se cruzan acá. Si no queda ninguna, no se pinta la sección: un botón deshabilitado
    // solo sirve para que alguien lo intente y no entienda por qué no pasa nada.
    $acciones = ($puedeRegistrar && ! $cancelada)
        ? \App\Services\Rutas\Custodia::accionesDeCampo($estado)
        : [];

    $panelId = 'custodia-'.$documento->id;

    // Si el servidor rechazó un hecho de ESTE documento, el panel vuelve abierto. Con treinta
    // tarjetas cerradas y un mensaje de error arriba, si no, habría que abrirlas de a una
    // para saber a cuál se refería.
    $fallo = session('custodia_abierta') === $documento->id;

    $rotulo = 'text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500';
    $dato = 'mt-0.5 text-sm text-gray-800 dark:text-paper-100';
    $vacio = 'mt-0.5 text-sm text-gray-400 dark:text-paper-500';
    $campo = 'w-full rounded-md border-gray-300 text-sm dark:border-ink-500 dark:bg-ink-700 dark:text-paper-100';
    $etiqueta = 'block text-xs font-medium text-gray-600 dark:text-paper-300';
@endphp

<details class="group rounded-lg border border-gray-200 dark:border-ink-600" id="{{ $panelId }}" {{ $fallo ? 'open' : '' }}>

    {{-- ───────────── Resumen: lo que se necesita saber sin abrir ───────────── --}}
    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-2 gap-y-1 rounded-lg px-3 py-2.5
                    hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2
                    focus-visible:outline-indigo-500 dark:hover:bg-ink-700">
        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $estado->clase() }}">
            <span aria-hidden="true">{{ $estado->icono() }}</span>{{ $estado->label() }}
        </span>

        @if ($tenedor)
            <span class="min-w-0 truncate text-sm text-gray-700 dark:text-paper-200">{{ $tenedor->nombre }}</span>
            @unless ($tenedor->activo)
                <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700">inactivo</span>
            @endunless
        @endif

        <span class="ml-auto shrink-0 text-xs text-gray-400 dark:text-paper-500">
            <span class="group-open:hidden">Ver custodia</span>
            <span class="hidden group-open:inline">Ocultar</span>
        </span>
    </summary>

    <div class="space-y-4 border-t border-gray-100 px-3 py-3 dark:border-ink-700">

        {{-- ───────────── Los hechos, cada uno por su lado ───────────── --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <p class="{{ $rotulo }}">Custodia del papel</p>
                <p class="{{ $dato }}">{{ $estado->label() }}</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-paper-400">{{ $estado->detalle() }}</p>
            </div>

            <div>
                <p class="{{ $rotulo }}">Titular actual</p>
                @if ($tenedor)
                    <p class="{{ $dato }}">{{ $tenedor->nombre }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-paper-400">
                        Desde el {{ $ultimo?->ocurrido_en?->translatedFormat('d M Y H:i') }}
                    </p>
                @else
                    <p class="{{ $vacio }}">Nadie lo lleva</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-paper-400">El papel no está en manos de una persona.</p>
                @endif
            </div>

            <div>
                {{-- El albarán prueba la ENTREGA del pedido. No dice nada del papel. --}}
                <p class="{{ $rotulo }}">Entrega al cliente (AC01)</p>
                @if ($documento->entregado())
                    <p class="{{ $dato }}">{{ $documento->fechaEntrega()?->translatedFormat('d M Y') ?? 'Entregado' }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-paper-400">Según el albarán. No prueba que el papel volviera.</p>
                @else
                    <p class="{{ $vacio }}">Sin albarán todavía</p>
                @endif
            </div>

            <div>
                <p class="{{ $rotulo }}">Recibido en oficina</p>
                @if ($documento->documentacionFisicaRecibida())
                    <p class="{{ $dato }}">{{ $documento->documentacion_fisica_recibida_at?->translatedFormat('d M Y H:i') }}</p>
                    @if ($documento->documentacionRecibidaPor)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-paper-400">Lo recibió {{ $documento->documentacionRecibidaPor->name }}</p>
                    @endif
                @else
                    <p class="{{ $vacio }}">No ha vuelto firmado</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-paper-400">Se confirma en Recepción de CCF, no acá.</p>
                @endif
            </div>
        </div>

        {{-- ───────────── Acciones de campo ───────────── --}}
        @if ($acciones !== [])
            <div class="space-y-2 border-t border-gray-100 pt-3 dark:border-ink-700">
                <p class="{{ $rotulo }}">Registrar un hecho</p>

                {{-- Una acción por bloque plegable. En un teléfono quedan apiladas y a la
                     altura del pulgar; en pantalla ancha siguen en columna porque son
                     formularios, no botones de barra. --}}
                @foreach ($acciones as $accion)
                    @php
                        $ruta = match ($accion) {
                            \App\Enums\TipoEventoCustodia::EntregaAPersonal => 'rutas.salidas.documentos.custodia.entregar',
                            \App\Enums\TipoEventoCustodia::Transferencia => 'rutas.salidas.documentos.custodia.transferir',
                            \App\Enums\TipoEventoCustodia::Incidencia => 'rutas.salidas.documentos.custodia.incidencia',
                        };
                        $campoId = $panelId.'-'.$accion->value;
                        $confirmacion = match ($accion) {
                            \App\Enums\TipoEventoCustodia::EntregaAPersonal => '¿Registrar que este documento sale de bodega? Queda en la bitácora y no se borra.',
                            \App\Enums\TipoEventoCustodia::Transferencia => '¿Registrar que el documento cambia de manos? Queda en la bitácora y no se borra.',
                            \App\Enums\TipoEventoCustodia::Incidencia => '¿Reportar una incidencia con este documento? Queda en la bitácora y no se borra.',
                        };
                    @endphp

                    <details class="rounded-md border border-gray-200 dark:border-ink-600">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-md px-3 py-2.5 text-sm font-medium
                                        text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2
                                        focus-visible:outline-offset-2 focus-visible:outline-indigo-500
                                        dark:text-paper-200 dark:hover:bg-ink-700">
                            <span class="inline-flex items-center gap-2">
                                <span class="inline-flex h-5 items-center rounded px-1.5 text-[10px] font-semibold uppercase tracking-wide {{ $accion->clase() }}">
                                    {{ $accion->label() }}
                                </span>
                            </span>
                            <span class="text-xs text-gray-400 dark:text-paper-500" aria-hidden="true">+</span>
                        </summary>

                        <form method="POST" action="{{ route($ruta, [$salida, $documento]) }}"
                              class="space-y-3 border-t border-gray-100 px-3 py-3 dark:border-ink-700"
                              onsubmit="return confirm(@js($confirmacion));">
                            @csrf

                            @if ($accion->requiereDestino())
                                {{-- Solo participantes ACTIVOS de esta salida. El servicio lo
                                     vuelve a exigir: esta lista es comodidad, no seguridad. --}}
                                <div>
                                    <label for="{{ $campoId }}-destino" class="{{ $etiqueta }}">
                                        {{ $accion === \App\Enums\TipoEventoCustodia::Transferencia ? 'Pasárselo a' : 'Entregárselo a' }}
                                    </label>
                                    <select id="{{ $campoId }}-destino" name="destino_personal_id" required
                                            class="{{ $campo }} mt-1">
                                        <option value="">Elegí a quién…</option>
                                        @foreach ($participantes as $participante)
                                            @continue($tenedor && $participante->rutas_personal_id === $tenedor->id)
                                            <option value="{{ $participante->rutas_personal_id }}">
                                                {{ $participante->personal->nombre }}@if ($participante->esResponsable()) · {{ $participante->rol->label() }}@endif
                                            </option>
                                        @endforeach
                                    </select>
                                    @if ($participantes->isEmpty())
                                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                            Esta salida no tiene participantes activos. Agregá gente a la salida antes de entregar documentos.
                                        </p>
                                    @endif
                                </div>

                                @if ($accion === \App\Enums\TipoEventoCustodia::Transferencia && $tenedor)
                                    {{-- Contra la pantalla vieja: si el papel ya cambió de manos
                                         mientras este formulario estaba abierto, el servidor
                                         rechaza en vez de encadenar sobre un origen que quien
                                         envía nunca vio. --}}
                                    <input type="hidden" name="custodio_esperado_id" value="{{ $tenedor->id }}">
                                    <p class="text-xs text-gray-500 dark:text-paper-400">Ahora lo lleva {{ $tenedor->nombre }}.</p>
                                @endif
                            @endif

                            <div>
                                <label for="{{ $campoId }}-obs" class="{{ $etiqueta }}">
                                    {{ $accion === \App\Enums\TipoEventoCustodia::Incidencia ? 'Qué pasó' : 'Observación (opcional)' }}
                                </label>
                                <textarea id="{{ $campoId }}-obs" name="observacion" rows="2" maxlength="500"
                                          @required($accion === \App\Enums\TipoEventoCustodia::Incidencia)
                                          placeholder="{{ $accion === \App\Enums\TipoEventoCustodia::Incidencia ? 'Se quedó en la sala, se mojó, no aparece…' : '' }}"
                                          class="{{ $campo }} mt-1"></textarea>
                                @if ($accion === \App\Enums\TipoEventoCustodia::Incidencia)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">
                                        Reportar no da el papel por perdido ni por recibido: lo deja señalado para que alguien lo mire.
                                    </p>
                                @endif
                            </div>

                            <button type="submit"
                                    class="w-full rounded-md bg-gray-800 px-3 py-2.5 text-sm font-medium text-white
                                           hover:bg-gray-900 focus-visible:outline focus-visible:outline-2
                                           focus-visible:outline-offset-2 focus-visible:outline-indigo-500
                                           dark:bg-paper-100 dark:text-ink-900 dark:hover:bg-white">
                                {{ $accion->label() }}
                            </button>
                        </form>
                    </details>
                @endforeach
            </div>
        @elseif ($puedeRegistrar && $estado->estaEnLaEmpresa())
            <p class="border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-ink-700 dark:text-paper-400">
                El papel ya volvió a la oficina. Desde acá no se registra nada más: si el registro está mal, se anula en el historial.
            </p>
        @endif

        {{-- ───────────── Historial: nada se borra ─────────────

             La ANULACIÓN vive acá y no arriba a propósito. Corregir no es registrar: no
             cuenta un hecho nuevo del papel, contradice uno ya asentado. Ponerla junto a
             «Entregar» la convertiría en una cuarta acción de campo, que es justo lo que no
             es —tiene otro permiso, exige motivo y solo la usa quien corrige errores—.

             Solo se ofrece sobre el ÚLTIMO evento vigente: anular uno del medio dejaría una
             cadena que no describe ninguna realidad. El servidor lo vuelve a exigir bajo
             llave, así que un formulario viejo se rechaza aunque el botón se haya pintado. --}}
        <div class="border-t border-gray-100 pt-3 dark:border-ink-700">
            <p class="{{ $rotulo }}">Historial</p>

            @if ($historial->isEmpty())
                <p class="{{ $vacio }}">Todavía no se registró ningún movimiento.</p>
            @else
                <ol class="mt-2 space-y-2">
                    @foreach ($historial as $evento)
                        <li class="flex gap-2.5 text-sm {{ $evento->anulado || $evento->esAnulacion() ? 'opacity-60' : '' }}">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $evento->vigente() ? 'bg-gray-400 dark:bg-paper-500' : 'bg-gray-300 dark:bg-ink-500' }}"
                                  aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-gray-700 dark:text-paper-200">
                                    {{ $evento->resumen() }}
                                    @if ($evento->anulado)
                                        <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-600 dark:bg-ink-700 dark:text-paper-400">anulado</span>
                                    @endif
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-paper-400">
                                    {{ $evento->ocurrido_en?->translatedFormat('d M Y H:i') }}
                                    @if ($evento->registradoPor)
                                        · lo registró {{ $evento->registradoPor->name }}
                                    @endif
                                </p>
                                @if ($evento->observacion)
                                    <p class="mt-0.5 text-xs italic text-gray-600 dark:text-paper-300">«{{ $evento->observacion }}»</p>
                                @endif
                                @if ($evento->motivo)
                                    <p class="mt-0.5 text-xs text-gray-600 dark:text-paper-300">Motivo: {{ $evento->motivo }}</p>
                                @endif

                                @if ($puedeCorregir && $ultimo && $evento->id === $ultimo->id)
                                    <details class="mt-1.5">
                                        <summary class="inline-flex cursor-pointer list-none rounded px-1.5 py-1 text-xs text-gray-500
                                                        hover:text-gray-800 hover:underline focus-visible:outline focus-visible:outline-2
                                                        focus-visible:outline-offset-2 focus-visible:outline-indigo-500
                                                        dark:text-paper-400 dark:hover:text-paper-100">
                                            Anular este registro
                                        </summary>

                                        <form method="POST" action="{{ route('rutas.custodia.anular', $evento) }}"
                                              class="mt-2 space-y-2 rounded-md border border-gray-200 p-3 dark:border-ink-600"
                                              onsubmit="return confirm('¿Anular este registro? No se borra: queda en el historial marcado como anulado, con tu nombre y el motivo. El documento vuelve al estado anterior.');">
                                            @csrf @method('DELETE')

                                            <label for="{{ $panelId }}-motivo" class="{{ $etiqueta }}">
                                                Por qué se anula
                                            </label>
                                            {{-- Obligatorio porque lo exige el TIPO que se va a crear —una anulación—,
                                                 no el que se está anulando. La regla vive en el enum. --}}
                                            <textarea id="{{ $panelId }}-motivo" name="motivo" rows="2" minlength="10" maxlength="500"
                                                      @required(\App\Enums\TipoEventoCustodia::Anulacion->requiereMotivo())
                                                      placeholder="Se registró sobre el documento equivocado…"
                                                      class="{{ $campo }}"></textarea>
                                            <p class="text-xs text-gray-500 dark:text-paper-400">
                                                Al menos 10 caracteres. Es lo que va a explicar la diferencia más adelante.
                                            </p>

                                            <button type="submit"
                                                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700
                                                           hover:bg-gray-50 focus-visible:outline focus-visible:outline-2
                                                           focus-visible:outline-offset-2 focus-visible:outline-indigo-500
                                                           dark:border-ink-500 dark:text-paper-200 dark:hover:bg-ink-700">
                                                Anular registro
                                            </button>
                                        </form>
                                    </details>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</details>
