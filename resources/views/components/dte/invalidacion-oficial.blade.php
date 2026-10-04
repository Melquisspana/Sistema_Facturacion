@props(['dte', 'invalidacion'])

{{--
    Invalidación oficial (evento anulardte) — tarjeta + ASISTENTE en modal.

    Reemplaza al antiguo `facturacion.partials.invalidacion`, que mostraba el formulario
    CAT-024 abierto en la ficha (y duplicado: una copia para la transmisión real y otra
    para el mock). Aquí el formulario vive dentro de un modal de tres pasos y el mock
    reutiliza EXACTAMENTE la misma selección, sin una segunda copia de los campos.

    Lo que NO cambia respecto de la versión anterior:
      · la ruta (facturacion.invalidacion.transmitir) y el payload
        (tipo / motivo / reemplazo / confirmacion_invalidacion);
      · la frase-barrera exacta INVALIDAR DTE, revalidada en servidor por
        TransmitirInvalidacionRequest;
      · los candados del entorno, que el servicio re-evalúa en cada intento.

    De dónde salen los requisitos del paso 2: del SERVIDOR, ya resueltos para ESTE
    documento (DteController::show → OpcionesInvalidacion::opciones($dte->tipo_dte) →
    PoliticaInvalidacion). NO hay una segunda matriz en Alpine: la vista solo pinta las
    banderas `requiere_reemplazo` / `requiere_motivo` que vienen en cada opción, porque
    la regla depende del tipo de documento y no solo del motivo (una NC no lleva
    sustituto en ninguno de los tres motivos; un CCF lo exige en el 1 y en el 3).

    La antigua casilla «confirmo la NC relacionada» YA NO EXISTE: una nota de crédito o
    de débito vigente PROHÍBE la invalidación, y esa prohibición no se confirma, se
    resuelve invalidando primero la nota.

    Lo que cambia es CUÁNDO se ve cada cosa: el paso 1 habla en lenguaje de oficina, el
    código de catálogo aparece solo como texto secundario, y la frase de confirmación se
    pide al final en vez de estar siempre en pantalla.

    Espera $invalidacion (ver DteController::show) y $dte.
--}}
@php
    $inv = $invalidacion;
    $selloInval = $dte->sello_invalidacion;
    $selloMock = \Illuminate\Support\Str::startsWith((string) $selloInval, 'MOCK');
    // La transmisión real está bloqueada si los candados del entorno la bloquean O si el
    // documento no es candidato (p. ej. aceptación MOCK): en ambos casos la acción va
    // deshabilitada y se muestran las razones, sin ocultar la tarjeta.
    $bloqueadoReal = ($inv['candados']['bloqueado'] ?? true) || ! ($inv['puede_transmitir'] ?? false);
    $yaInvalidado = (bool) ($inv['ya_invalidado'] ?? false);
    // Dependencia fiscal: notas de crédito/débito ACEPTADAS por Hacienda y aún vigentes.
    // Mientras existan, la invalidación está prohibida (no hay confirmación que la abra).
    $notasVigentes = $inv['notas_vigentes'] ?? collect();
    $bloqueadoPorNotas = (bool) ($inv['bloqueado_por_notas'] ?? false);

    // Un error de validación del servidor debe REABRIR el asistente en el último paso,
    // no dejar al usuario frente a un modal cerrado sin saber qué pasó.
    $huboErrorInvalidacion = $errors->hasAny(['tipo', 'motivo', 'reemplazo', 'confirmacion_invalidacion']);
@endphp

@once
    <script data-dte-asistente-invalidacion>
        /**
         * Estado del asistente de invalidación. Solo controla QUÉ se muestra y qué valor
         * viaja en cada campo del formulario de siempre: no valida nada de forma
         * autoritativa. El Form Request revalida la frase y los campos CAT-024, y el
         * serializador vuelve a aplicar las reglas de reemplazo/motivo en servidor.
         */
        window.dteAsistenteInvalidacion = function (cfg) {
            var FRASE = 'INVALIDAR DTE';
            // Espejo del patrón de App\Support\Dte\CodigoGeneracion: solo para avisar en
            // pantalla en el modo avanzado. La regla real la aplica el serializador.
            var PATRON_CODIGO = /^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/;

            return {
                paso: 1,
                opciones: cfg.opciones || [],
                reemplazos: (cfg.reemplazos || []).slice(),
                urlBuscar: cfg.urlBuscar,
                tipo: (cfg.tipoInicial === null || cfg.tipoInicial === undefined || cfg.tipoInicial === '')
                    ? null : Number(cfg.tipoInicial),
                motivo: cfg.motivoInicial || '',
                manual: '',
                elegido: null,
                avanzado: false,
                busqueda: '',
                buscando: false,
                frase: '',

                init() {
                    // Al cambiar de motivo se limpia lo que el nuevo motivo NO admite. Es
                    // imprescindible, no cosmético: la matriz del servidor RECHAZA un
                    // sustituto en las celdas que exigen null, así que un valor residual
                    // del motivo anterior haría fallar el envío.
                    this.$watch('tipo', () => this.limpiarLoQueNoAplica());

                    // Repintado tras un error de validación: recuperar el reemplazo elegido
                    // (o el código escrito a mano) y volver al paso donde estaba el usuario.
                    var inicial = (cfg.reemplazoInicial || '').trim();
                    if (inicial !== '') {
                        var doc = this.reemplazos.find(function (d) {
                            return (d.codigo_generacion || '').toUpperCase() === inicial.toUpperCase();
                        });
                        if (doc) {
                            this.elegido = doc;
                        } else {
                            this.avanzado = true;
                            this.manual = inicial;
                        }
                    }
                    if (cfg.abrirEnResumen && this.tipo !== null) {
                        this.paso = 3;
                    }
                },

                get opcion() {
                    return this.opciones.find((o) => o.valor === this.tipo) || null;
                },
                get requiereReemplazo() {
                    return !! (this.opcion && this.opcion.requiere_reemplazo);
                },
                get requiereMotivo() {
                    return !! (this.opcion && this.opcion.requiere_motivo);
                },
                /** ¿El servidor tiene regla para este documento y este motivo? */
                get soportado() {
                    return !! (this.opcion && this.opcion.soportado);
                },
                get razonNoSoportado() {
                    return (this.opcion && this.opcion.razon_no_soportado) || '';
                },
                get notaOperativa() {
                    return (this.opcion && this.opcion.nota_operativa) || '';
                },
                /** Valor que viaja en el campo `reemplazo`: el elegido, o el manual del modo avanzado. */
                get reemplazo() {
                    if (! this.requiereReemplazo) {
                        return '';
                    }
                    if (this.avanzado) {
                        return this.manual.trim().toUpperCase();
                    }
                    return this.elegido ? (this.elegido.codigo_generacion || '') : '';
                },
                get formatoManualValido() {
                    return PATRON_CODIGO.test(this.manual.trim().toUpperCase());
                },
                get puedeAvanzar() {
                    if (this.paso === 1) {
                        return this.tipo !== null && this.soportado;
                    }
                    if (this.paso === 2) {
                        if (this.requiereReemplazo && this.reemplazo === '') { return false; }
                        if (this.requiereMotivo && this.motivo.trim() === '') { return false; }
                        return true;
                    }
                    return false;
                },
                /** El botón rojo solo se habilita con la frase-barrera exacta y los datos del tipo. */
                get puedeTransmitir() {
                    return this.tipo !== null
                        && this.soportado
                        && this.frase.trim() === FRASE
                        && (! this.requiereReemplazo || this.reemplazo !== '')
                        && (! this.requiereMotivo || this.motivo.trim() !== '');
                },

                /**
                 * Deja el formulario coherente con el motivo elegido: sin sustituto donde la
                 * matriz lo prohíbe y sin texto donde no se pide. Lo llama tanto el $watch
                 * como el propio radio (x-on:change), para que el campo quede limpio en el
                 * mismo gesto del usuario y no un tick después.
                 */
                limpiarLoQueNoAplica() {
                    if (! this.requiereReemplazo) {
                        this.elegido = null;
                        this.manual = '';
                        this.avanzado = false;
                        this.busqueda = '';
                    }
                    if (! this.requiereMotivo) {
                        this.motivo = '';
                    }
                },
                alCambiarTipo() { this.limpiarLoQueNoAplica(); },

                siguiente() { if (this.puedeAvanzar && this.paso < 3) { this.paso++; } },
                atras() { if (this.paso > 1) { this.paso--; } },
                elegir(doc) { this.elegido = doc; this.avanzado = false; this.manual = ''; },
                limpiarElegido() { this.elegido = null; },
                alCambiarAvanzado() { this.avanzado ? (this.elegido = null) : (this.manual = ''); },
                alEscribirManual() { if (this.manual.trim() !== '') { this.elegido = null; } },

                /** Autocomplete: MISMO universo que la precarga, filtrado en servidor. */
                buscar() {
                    var self = this;
                    self.buscando = true;
                    fetch(self.urlBuscar + '?q=' + encodeURIComponent(self.busqueda), {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(function (r) { return r.ok ? r.json() : { resultados: [] }; })
                        .then(function (j) { self.reemplazos = (j && j.resultados) ? j.resultados : []; })
                        .catch(function () { self.reemplazos = []; })
                        .finally(function () { self.buscando = false; });
                },
            };
        };
    </script>
@endonce

<div class="bg-white shadow sm:rounded-lg p-6 border border-gray-200">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h3 class="font-semibold text-gray-700">Invalidación oficial (evento anulardte)</h3>
            @if ($dte->fecha_procesamiento_mh)
                @php
                    // Solo informativo: una tabla de inhábiles mal escrita no debe tumbar la
                    // ficha del documento (la validación fiscal sí la rechaza y bloquea).
                    $plazo = new \App\Support\Dte\PlazoInvalidacion;
                    $fechaSello = $dte->fecha_procesamiento_mh->format('Y-m-d');
                    try {
                        $limite = $plazo->limite($dte->tipo_dte, $fechaSello);
                        $calendarioCompleto = $plazo->calendarioCompleto($dte->tipo_dte, $fechaSello);
                    } catch (\InvalidArgumentException $e) {
                        $limite = null;
                    }
                @endphp
                <p class="mt-1 text-sm text-gray-500">
                    @if ($limite)
                        Plazo para transmitir la invalidación: {{ \App\Support\HoraNegocio::aLocal($limite)->format('d/m/Y') }} a las 23:59:59 (hora de El Salvador).
                        @unless ($calendarioCompleto)
                            (calculado sin calendario de días inhábiles {{ $plazo->anioCalendario($fechaSello) }}; cargarlo en config/dte.php)
                        @endunless
                    @else
                        No se pudo calcular el plazo: la tabla de días inhábiles de la configuración no es válida.
                    @endif
                </p>
            @endif
            <p class="mt-1 text-sm text-gray-500 max-w-prose">
                Anula el documento <strong>ante Hacienda</strong>. Solo se marca Invalidado si Hacienda acepta el evento;
                si lo rechaza o falla el envío, el documento conserva su estado. No se deshace.
            </p>
        </div>
        @if ($yaInvalidado)
            <span class="inline-flex shrink-0 items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">Con evento registrado</span>
        @elseif (! $bloqueadoReal)
            <span class="inline-flex shrink-0 items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">Transmisión habilitada</span>
        @else
            <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Bloqueada (modo seguro)</span>
        @endif
    </div>

    {{-- Evidencia del evento ya firmado (mock o real). Solo lectura. --}}
    @if ($yaInvalidado && filled($selloInval))
        <div class="mt-4 bg-gray-50 border border-gray-200 rounded-md p-4">
            <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                <h4 class="font-semibold text-gray-700">Evento de invalidación registrado</h4>
                <span class="inline-block rounded-full px-3 py-1 text-xs font-medium {{ $selloMock ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-700' }}">
                    {{ $selloMock ? 'Invalidación SIMULADA (MOCK)' : 'Invalidación aceptada por Hacienda' }}
                </span>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div class="sm:col-span-2"><dt class="text-gray-500">Sello de invalidación</dt><dd class="font-mono break-all text-gray-800">{{ $selloInval }}</dd></div>
                <div><dt class="text-gray-500">Tipo de anulación</dt><dd>{{ $dte->tipo_anulacion?->label() ?? '—' }}<span class="block text-xs text-gray-400">CAT-024 · {{ $dte->tipo_anulacion?->value ?? '—' }}</span></dd></div>
                <div><dt class="text-gray-500">Fecha del evento</dt><dd>{{ optional($dte->fecha_invalidacion)->format('d/m/Y H:i:s') ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500">Código de generación (evento)</dt><dd class="font-mono break-all">{{ $dte->codigo_generacion_invalidacion ?? '—' }}</dd></div>
            </dl>

            @if ($dte->respuesta_mh_invalidacion)
                @php $ri = $dte->respuesta_mh_invalidacion; @endphp
                <details class="mt-4 group">
                    <summary class="cursor-pointer text-sm font-medium text-indigo-600 hover:text-indigo-800">Ver respuesta del evento</summary>
                    <dl class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                        <div><dt class="text-gray-500">Estado</dt><dd>{{ $ri['estado'] ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Código</dt><dd class="font-mono">{{ $ri['codigoMsg'] ?? '—' }}</dd></div>
                        <div class="md:col-span-2"><dt class="text-gray-500">Descripción</dt><dd>{{ $ri['descripcionMsg'] ?? '—' }}</dd></div>
                    </dl>
                    @if (! empty($ri['observaciones']))
                        <ul class="mt-3 list-disc ml-5 text-sm text-gray-700">
                            @foreach ($ri['observaciones'] as $o)<li>{{ $o }}</li>@endforeach
                        </ul>
                    @endif
                    @if ($dte->respuesta_mh_invalidacion_path)
                        <p class="mt-3 text-xs text-gray-400 font-mono break-all">Respuesta guardada: {{ $dte->respuesta_mh_invalidacion_path }}</p>
                    @endif
                </details>
            @endif
        </div>
    @endif

    @if (! $yaInvalidado)
        @if ($bloqueadoReal)
            {{-- Mensajes para el usuario (no técnicos): las razones se derivan de las condiciones
                 de negocio. El detalle técnico completo (flags/configs/parámetros) NO se muestra en
                 la interfaz; queda en el servicio y la auditoría. Es solo presentación: no cambia
                 candados, lógica ni permisos. --}}
            @php
                $razonesUsuario = [];
                if ($inv['protegido'] ?? false) {
                    $razonesUsuario[] = 'Este documento está protegido como evidencia de pruebas.';
                }
                if (! $dte->aceptadoRealmentePorMh()) {
                    $razonesUsuario[] = 'Este documento no tiene una aceptación real de Hacienda (aceptación simulada).';
                }
                // El texto completo —con el detalle de cada nota y su enlace— va en el
                // bloque naranja de abajo; acá solo se nombra la causa para no repetirlo.
                if ($bloqueadoPorNotas) {
                    $razonesUsuario[] = 'Tiene notas relacionadas que bloquean la invalidación (ver el detalle abajo).';
                }
                // Documento apto pero bloqueado por el entorno (modo seguro): un único mensaje simple.
                if (($inv['puede_transmitir'] ?? false) && ($inv['candados']['bloqueado'] ?? false)) {
                    $razonesUsuario[] = 'La transmisión de invalidación está deshabilitada en este entorno de trabajo.';
                }
                if (empty($razonesUsuario)) {
                    $razonesUsuario[] = 'La invalidación no está disponible en este momento.';
                }
            @endphp
            <div class="mt-4 rounded-md bg-gray-50 border border-gray-200 p-3">
                <p class="text-sm font-semibold text-gray-700">Invalidación no disponible para este documento</p>
                <ul class="mt-2 text-sm text-gray-600 list-disc list-inside space-y-1">
                    @foreach ($razonesUsuario as $razon)<li>{{ $razon }}</li>@endforeach
                </ul>
            </div>
        @endif

        {{-- Dependencia fiscal: mientras exista una nota vigente no se invalida, y se dice
             QUÉ hay que invalidar antes. El enlace solo aparece con permiso de ver la nota. --}}
        @if ($bloqueadoPorNotas)
            <div class="mt-4 rounded-md border border-orange-300 bg-orange-50 p-4 text-sm text-orange-900">
                @foreach (app(\App\Services\Dte\ValidadorReglasInvalidacion::class)->problemasDeDependencias($dte) as $mensaje)
                    <p class="font-semibold">{{ $mensaje }}</p>
                @endforeach
                <ul class="mt-2 space-y-1">
                    @foreach ($notasVigentes as $nota)
                        <li>
                            <span class="font-medium">{{ $nota->tipo_dte?->label() ?? 'Nota' }}</span>
                            <span class="font-mono">{{ $nota->numero_control ?? $nota->numero_interno ?? '#'.$nota->id }}</span>
                            <span>({{ $nota->estado->label() }})</span>
                            @can('view', $nota)
                                — <a href="{{ route('facturacion.show', $nota) }}" class="underline hover:no-underline">ver la nota</a>
                            @endcan
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs">
                    Resolvé las notas indicadas antes de invalidar este comprobante.
                </p>
            </div>
        @endif

        <div class="mt-4">
            <button type="button"
                    @if ($bloqueadoReal) disabled @else x-data="" x-on:click="$dispatch('open-modal', 'invalidar-dte')" @endif
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm rounded-md font-medium {{ $bloqueadoReal ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-rose-600 text-white hover:bg-rose-700' }}">
                Invalidar oficialmente
            </button>
            @if ($bloqueadoReal)
                <p class="mt-1 text-xs text-gray-400">Botón deshabilitado: la transmisión real está candada en este entorno (ver razones arriba).</p>
            @else
                <p class="mt-1 text-xs text-gray-500">Se abre un asistente guiado. Nada se transmite hasta el último paso.</p>
            @endif
        </div>

        {{-- === ASISTENTE (modal) ===================================================
             Solo se monta cuando la acción es posible: sin candidatura ni con los
             candados cerrados no hay formulario que rellenar. El servidor vuelve a
             validarlo todo igualmente. ====================================== --}}
        @unless ($bloqueadoReal)
            <x-modal name="invalidar-dte" maxWidth="2xl" :show="$huboErrorInvalidacion" focusable>
                <div class="p-6"
                     x-data="dteAsistenteInvalidacion({
                         opciones: @js($inv['opciones_motivo']),
                         reemplazos: @js($inv['reemplazos']),
                         urlBuscar: @js($inv['buscar_reemplazo_url']),
                         tipoInicial: @js(old('tipo')),
                         motivoInicial: @js(old('motivo', '')),
                         reemplazoInicial: @js(old('reemplazo', '')),
                         abrirEnResumen: @js($huboErrorInvalidacion),
                     })">

                    {{-- Cabecera + progreso --}}
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800">Invalidar documento</h2>
                            <p class="mt-0.5 text-sm text-gray-500">
                                {{ $dte->tipo_dte?->label() }} · <span class="font-mono">{{ $dte->numero_control ?? '—' }}</span>
                            </p>
                        </div>
                        <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600 text-sm">Cerrar</button>
                    </div>

                    <ol class="mt-4 flex items-center gap-2 text-xs">
                        @foreach (['Motivo', 'Detalles', 'Confirmar'] as $i => $etiqueta)
                            <li class="flex items-center gap-2">
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full font-semibold"
                                      :class="paso >= {{ $i + 1 }} ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-400'">{{ $i + 1 }}</span>
                                <span :class="paso >= {{ $i + 1 }} ? 'text-gray-700 font-medium' : 'text-gray-400'">{{ $etiqueta }}</span>
                                @if ($i < 2)<span class="mx-1 text-gray-300">›</span>@endif
                            </li>
                        @endforeach
                    </ol>

                    <form method="POST" action="{{ route('facturacion.invalidacion.transmitir', $dte) }}"
                          class="mt-5"
                          x-on:submit="if (! puedeTransmitir) { $event.preventDefault(); return; }
                                       if (! confirm('¿TRANSMITIR la invalidación a Hacienda? Solo se marcará Invalidado si Hacienda la acepta. No se deshace.')) { $event.preventDefault(); }">
                        @csrf

                        {{-- ── PASO 1 · Motivo en lenguaje humano ───────────────────
                             Cada tarjeta mapea a su valor de CAT-024; el código va como
                             texto secundario, nunca como encabezado. --}}
                        <div x-show="paso === 1" x-cloak class="space-y-3">
                            <p class="text-sm text-gray-600">¿Por qué se invalida este documento?</p>
                            <template x-for="op in opciones" :key="op.valor">
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition"
                                       :class="tipo === op.valor ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 hover:bg-gray-50'">
                                    <input type="radio" name="tipo" class="mt-1 shrink-0" :value="op.valor"
                                           x-model.number="tipo" x-on:change="alCambiarTipo()">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-gray-800" x-text="op.titulo"></span>
                                        <span class="block text-sm text-gray-500" x-text="op.descripcion"></span>
                                        <span class="mt-1 block text-xs text-gray-400">
                                            Código oficial CAT-024 <span x-text="op.valor"></span> — <span x-text="op.etiqueta_oficial"></span>
                                        </span>
                                    </span>
                                </label>
                            </template>
                            <x-input-error :messages="$errors->get('tipo')" class="mt-1" />

                            {{-- El servidor no tiene regla para este documento: se dice, no se
                                 adopta la del CCF por defecto. --}}
                            <div x-show="tipo !== null && ! soportado" x-cloak
                                 class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800"
                                 x-text="razonNoSoportado"></div>
                        </div>

                        {{-- ── PASO 2 · Campos condicionales ────────────────────────
                             Se muestran EXACTAMENTE los que la regla del tipo elegido
                             exige. Los que no aplican se limpian al cambiar de tipo,
                             para no mandar un reemplazo en un tipo 2/3 (que el
                             serializador rechazaría). --}}
                        <div x-show="paso === 2" x-cloak class="space-y-4">
                            {{-- Aclaración operativa del servidor (p. ej. el orden de la NC:
                                 invalidar primero, emitir la corrección después). --}}
                            <div x-show="notaOperativa !== ''" x-cloak
                                 class="rounded-md border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900"
                                 x-text="notaOperativa"></div>

                            {{-- Documento sustituto: solo las celdas de la matriz que lo exigen. --}}
                            <div x-show="requiereReemplazo" x-cloak>
                                <p class="text-sm font-medium text-gray-700">¿Qué documento lo sustituye?</p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    Solo se pueden elegir documentos <strong>del mismo tipo, del mismo emisor y
                                    ambiente, ya aceptados por Hacienda</strong> y no invalidados: son los mismos
                                    requisitos que valida el servidor, así que lo que aparece acá es lo que se puede
                                    usar. El modo avanzado sirve para escribir el código a mano, pero pasa por la
                                    misma comprobación: un documento que no exista en este sistema no se puede
                                    verificar y la invalidación se bloquea.
                                </p>

                                {{-- Documento ya elegido --}}
                                <div x-show="elegido" x-cloak class="mt-3 rounded-lg border border-indigo-300 bg-indigo-50 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 text-sm">
                                            <p class="font-semibold text-gray-800">
                                                <span x-text="elegido?.tipo_label"></span> ·
                                                <span class="font-mono" x-text="elegido?.numero_control"></span>
                                            </p>
                                            <p class="text-gray-600" x-text="elegido?.cliente"></p>
                                            <p class="text-gray-500">
                                                <span x-text="elegido?.fecha"></span> · $<span x-text="elegido?.total"></span> ·
                                                <span x-text="elegido?.estado"></span>
                                            </p>
                                            <p class="mt-1 font-mono text-xs text-gray-400 break-all" x-text="elegido?.codigo_generacion"></p>
                                        </div>
                                        <button type="button" x-on:click="limpiarElegido()" class="shrink-0 text-xs text-indigo-700 hover:underline">Cambiar</button>
                                    </div>
                                </div>

                                {{-- Buscador --}}
                                <div x-show="! elegido" x-cloak class="mt-3">
                                    <x-text-input type="search" class="block w-full" placeholder="Buscar por número de control, código de generación, cliente o fecha (dd/mm/aaaa)"
                                                  x-model="busqueda" x-on:input.debounce.300ms="buscar()" />
                                    <p class="mt-1 text-xs text-gray-400" x-show="buscando" x-cloak>Buscando…</p>

                                    <ul class="mt-2 max-h-56 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200">
                                        <template x-for="doc in reemplazos" :key="doc.id">
                                            <li>
                                                <button type="button" x-on:click="elegir(doc)"
                                                        class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50">
                                                    <span class="flex flex-wrap items-baseline gap-x-2">
                                                        <span class="font-semibold text-gray-800" x-text="doc.tipo_label"></span>
                                                        <span class="font-mono text-gray-600" x-text="doc.numero_control"></span>
                                                        <span class="text-gray-500" x-text="doc.fecha"></span>
                                                        <span class="text-gray-500">$<span x-text="doc.total"></span></span>
                                                        <span class="text-gray-400" x-text="doc.estado"></span>
                                                    </span>
                                                    <span class="block truncate text-gray-500" x-text="doc.cliente"></span>
                                                </button>
                                            </li>
                                        </template>
                                        <li x-show="! reemplazos.length && ! buscando" x-cloak class="px-3 py-3 text-sm text-gray-500">
                                            Ningún documento coincide. Probá con otro dato o usá el modo avanzado.
                                        </li>
                                    </ul>
                                </div>

                                {{-- Modo avanzado: código a mano, claramente separado. --}}
                                <div class="mt-3 rounded-md border border-dashed border-gray-300 bg-gray-50/60 p-3">
                                    <label class="inline-flex items-center gap-2 text-xs font-medium text-gray-600">
                                        <input type="checkbox" x-model="avanzado" x-on:change="alCambiarAvanzado()" class="rounded border-gray-300">
                                        Modo avanzado: escribir el código de generación a mano
                                    </label>
                                    <div x-show="avanzado" x-cloak class="mt-2">
                                        <x-text-input type="text" class="block w-full font-mono text-sm" placeholder="00000000-0000-4000-8000-000000000000"
                                                      x-model="manual" x-on:input="alEscribirManual()" />
                                        <p class="mt-1 text-xs" x-show="manual.trim() !== ''" x-cloak
                                           :class="formatoManualValido ? 'text-gray-500' : 'text-amber-700'"
                                           x-text="formatoManualValido
                                               ? 'El formato es correcto, pero eso no prueba nada: el servidor comprobará que el documento exista acá, sea del mismo tipo, emisor y ambiente, y esté aceptado por Hacienda.'
                                               : 'No parece un código de generación oficial (UUID en mayúsculas). Podés enviarlo, pero el sistema lo rechazará antes de transmitir.'"></p>
                                    </div>
                                </div>

                                {{-- Valor que realmente viaja al POST: mismo campo `reemplazo` de siempre. --}}
                                <input type="hidden" name="reemplazo" :value="requiereReemplazo ? reemplazo : ''">
                                <x-input-error :messages="$errors->get('reemplazo')" class="mt-1" />
                            </div>

                            {{-- Motivo en texto: solo el tipo que lo exige. --}}
                            <div x-show="requiereMotivo" x-cloak>
                                <x-input-label for="inval_motivo_texto" value="Explicá el motivo de la anulación *" />
                                <x-text-input id="inval_motivo_texto" type="text" class="mt-1 block w-full"
                                              placeholder="Ej. El cliente canceló la operación después de emitido el documento"
                                              x-model="motivo" />
                                <p class="mt-1 text-xs text-gray-400">Este texto viaja a Hacienda dentro del evento.</p>
                                <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                            </div>
                            <input type="hidden" name="motivo" :value="requiereMotivo ? motivo : ''">

                            {{-- Ni reemplazo ni motivo: el tipo elegido no pide nada más. --}}
                            <div x-show="! requiereReemplazo && ! requiereMotivo" x-cloak
                                 class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
                                Este motivo no necesita datos adicionales. Continuá para revisar el resumen.
                            </div>

                        </div>

                        {{-- ── PASO 3 · Resumen, advertencia y frase-barrera ──────── --}}
                        <div x-show="paso === 3" x-cloak class="space-y-4">
                            <div class="rounded-lg border border-gray-200 divide-y divide-gray-100 text-sm">
                                <div class="p-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Documento a invalidar</p>
                                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <div><dt class="text-gray-500">Tipo</dt><dd class="text-gray-800">{{ $dte->tipo_dte?->label() }}</dd></div>
                                        <div><dt class="text-gray-500">Número de control</dt><dd class="font-mono text-gray-800">{{ $dte->numero_control ?? '—' }}</dd></div>
                                        <div><dt class="text-gray-500">Cliente</dt><dd class="text-gray-800">{{ $dte->cliente?->nombre ?? '—' }}</dd></div>
                                        <div><dt class="text-gray-500">Total</dt><dd class="text-gray-800">${{ number_format((float) $dte->total_pagar, 2) }}</dd></div>
                                    </dl>
                                </div>
                                <div class="p-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Tipo de anulación</p>
                                    <p class="mt-1 font-medium text-gray-800" x-text="opcion?.titulo"></p>
                                    <p class="text-xs text-gray-400">
                                        Código oficial CAT-024 <span x-text="opcion?.valor"></span> — <span x-text="opcion?.etiqueta_oficial"></span>
                                    </p>
                                </div>
                                <div class="p-3" x-show="requiereMotivo" x-cloak>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Motivo</p>
                                    <p class="mt-1 text-gray-800" x-text="motivo"></p>
                                </div>
                                <div class="p-3" x-show="requiereReemplazo" x-cloak>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Documento sustituto</p>
                                    <template x-if="elegido">
                                        <p class="mt-1 text-gray-800">
                                            <span x-text="elegido?.tipo_label"></span> ·
                                            <span class="font-mono" x-text="elegido?.numero_control"></span> ·
                                            <span x-text="elegido?.cliente"></span>
                                        </p>
                                    </template>
                                    <p class="mt-1 font-mono text-xs text-gray-500 break-all" x-text="reemplazo"></p>
                                    <p class="mt-1 text-xs text-amber-700" x-show="! elegido" x-cloak>Código escrito a mano (modo avanzado).</p>
                                </div>
                            </div>

                            <div class="rounded-md border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800">
                                <p class="font-semibold">Esta acción se transmitirá realmente a Hacienda.</p>
                                <p class="mt-1">
                                    El documento <strong>no se aplicará localmente hasta que Hacienda acepte</strong> el evento.
                                    Si lo rechaza o falla el envío, el documento conserva su estado actual. No se deshace.
                                </p>
                            </div>

                            <div>
                                <label for="inval_frase" class="block text-xs font-semibold text-rose-700 mb-1">
                                    Para continuar, escribí exactamente: <span class="font-mono">INVALIDAR DTE</span>
                                </label>
                                <input id="inval_frase" type="text" name="confirmacion_invalidacion" autocomplete="off" spellcheck="false"
                                       placeholder="INVALIDAR DTE" x-model="frase"
                                       class="w-72 rounded-md border-rose-300 text-sm font-mono focus:border-rose-500 focus:ring-rose-500">
                                <x-input-error :messages="$errors->get('confirmacion_invalidacion')" class="mt-1" />
                            </div>
                        </div>

                        {{-- ── Navegación ─────────────────────────────────────────── --}}
                        <div class="mt-6 flex items-center justify-between gap-3 border-t border-gray-100 pt-4">
                            <button type="button" x-show="paso > 1" x-cloak x-on:click="atras()"
                                    class="inline-flex items-center px-4 py-2 text-sm rounded-md font-medium bg-gray-100 text-gray-700 hover:bg-gray-200">
                                Atrás
                            </button>
                            <span x-show="paso === 1" x-cloak></span>

                            <div class="flex items-center gap-2">
                                <button type="button" x-on:click="$dispatch('close')"
                                        class="inline-flex items-center px-4 py-2 text-sm rounded-md font-medium bg-white text-gray-600 hover:bg-gray-50 border border-gray-200">
                                    Cancelar
                                </button>
                                <button type="button" x-show="paso < 3" x-cloak x-on:click="siguiente()" :disabled="! puedeAvanzar"
                                        class="inline-flex items-center px-4 py-2 text-sm rounded-md font-medium"
                                        :class="puedeAvanzar ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                                    Continuar
                                </button>
                                {{-- Único botón rojo del asistente, y solo en el último paso. --}}
                                <button type="submit" x-show="paso === 3" x-cloak :disabled="! puedeTransmitir"
                                        class="inline-flex items-center px-4 py-2 text-sm rounded-md font-medium"
                                        :class="puedeTransmitir ? 'bg-rose-600 text-white hover:bg-rose-700' : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                                    Transmitir invalidación a Hacienda
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </x-modal>
        @endunless
    @endif

    {{-- === Modo prueba (MOCK) + dry-run: diagnóstico avanzado, no transmite nada. ===
         Antes duplicaba los tres campos CAT-024; ahora reutiliza el MISMO tipo elegido
         aquí, en un único selector compacto. El payload que reciben las rutas de mock y
         dry-run no cambia. === --}}
    @if (($inv['puede_mock'] ?? false) || $inv['dry_run'])
        <details class="mt-6 group rounded-lg border border-dashed border-gray-300 bg-gray-50/60">
            <summary class="cursor-pointer select-none px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-800">
                Avanzado · modo prueba (MOCK) y dry-run
                <span class="ml-1 font-normal text-gray-400">(diagnóstico, no transmite a Hacienda)</span>
            </summary>
            <div class="px-4 pb-4 pt-1 space-y-4">
                <div class="bg-amber-50 border border-amber-300 rounded-md p-3 text-xs text-amber-800 font-semibold">
                    SIMULACIÓN — NO TRANSMITE A HACIENDA
                    <p class="mt-1 font-normal">
                        El modo prueba firma el evento con un sello ficticio (<span class="font-mono">MOCK-INVAL-…</span>) y el
                        dry-run solo prepara/valida el evento. Ninguno transmite ni cambia el estado del documento.
                    </p>
                </div>

                @if ($inv['puede_mock'])
                    <form method="POST" action="{{ route('facturacion.invalidacion.mock', $dte) }}"
                          class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end"
                          onsubmit="return confirm('¿Firmar el evento de invalidación en MODO PRUEBA (MOCK)? No se transmite nada a Hacienda ni cambia el estado del documento.');">
                        @csrf
                        <div>
                            <x-input-label for="inval_tipo" value="Tipo de anulación (CAT-024) *" />
                            <select id="inval_tipo" name="tipo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                @foreach ($inv['tipos'] as $valor => $label)
                                    <option value="{{ $valor }}" @selected((int) old('tipo', \App\Enums\TipoAnulacionMh::RescindirOperacion->value) === $valor)>{{ $valor }} — {{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="inval_motivo" value="Motivo en texto (obligatorio si tipo = 3)" />
                            <x-text-input id="inval_motivo" name="motivo" type="text" class="mt-1 block w-full" :value="old('motivo')" />
                            <x-input-error :messages="$errors->get('motivo')" class="mt-1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-input-label for="inval_reemplazo" value="Código de generación del documento sustituto (solo donde la matriz lo exige)" />
                            <x-text-input id="inval_reemplazo" name="reemplazo" type="text" class="mt-1 block w-full font-mono" :value="old('reemplazo')" />
                            <x-input-error :messages="$errors->get('reemplazo')" class="mt-1" />
                        </div>
                        <div class="flex flex-col gap-2">
                            @unless ($inv['mock_activo'])
                                <label class="inline-flex items-center gap-2 text-xs text-gray-600">
                                    <input type="checkbox" name="confirmar_sin_flag" value="1" class="rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                                    Ejecutar aunque el mock esté apagado (no transmite nada)
                                </label>
                            @endunless
                        </div>
                        <div class="md:col-span-3 flex flex-wrap gap-3">
                            <button class="inline-flex items-center px-4 py-2 bg-amber-600 text-white text-sm rounded-md hover:bg-amber-700">
                                Firmar invalidación (MOCK)
                            </button>
                            <button type="submit" form="inval_dry_run_form"
                                    class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                Dry-run visual
                            </button>
                        </div>
                    </form>

                    {{-- Form separado del dry-run (reusa los mismos campos vía JS al enviar). --}}
                    <form method="POST" action="{{ route('facturacion.invalidacion.dry-run', $dte) }}" id="inval_dry_run_form"
                          onsubmit="this.querySelector('[name=tipo]').value = document.getElementById('inval_tipo').value;
                                    this.querySelector('[name=motivo]').value = document.getElementById('inval_motivo').value;
                                    this.querySelector('[name=reemplazo]').value = document.getElementById('inval_reemplazo').value;">
                        @csrf
                        <input type="hidden" name="tipo">
                        <input type="hidden" name="motivo">
                        <input type="hidden" name="reemplazo">
                    </form>
                @endif

                {{-- Resultado del último dry-run visual (seguro: sin token/contraseña; el JWS va como marcador). --}}
                @if ($inv['dry_run'])
                    @php $dr = $inv['dry_run']; @endphp
                    <div class="bg-white border border-gray-200 rounded-md p-4 text-sm">
                        <h4 class="font-semibold text-gray-700 mb-2">Resultado del dry-run de invalidación (no transmitido)</h4>
                        <dl class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <div><dt class="text-gray-500">¿Transmitiría?</dt><dd>{{ $dr['transmitiria'] ? 'sí' : 'no' }}</dd></div>
                            <div><dt class="text-gray-500">ambiente</dt><dd>{{ $dr['ambiente'] }}</dd></div>
                            <div><dt class="text-gray-500">schema válido</dt><dd>{{ $dr['schema']['valido'] ? 'sí' : 'no' }} ({{ $dr['schema']['estado'] }})</dd></div>
                            <div class="md:col-span-3"><dt class="text-gray-500">endpoint</dt><dd class="font-mono break-all">{{ $dr['endpoint'] }}</dd></div>
                        </dl>
                        @if (! empty($dr['schema']['errores']))
                            <ul class="mt-2 list-disc ml-5 text-xs text-rose-600">
                                @foreach (array_slice($dr['schema']['errores'], 0, 8) as $e)<li>{{ $e }}</li>@endforeach
                            </ul>
                        @endif
                        <details class="mt-3 group">
                            <summary class="cursor-pointer text-sm font-medium text-indigo-600 hover:text-indigo-800">Ver evento serializado</summary>
                            <pre class="mt-2 bg-gray-50 border border-gray-200 rounded p-3 text-xs overflow-x-auto">{{ json_encode($dr['evento'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                        <p class="mt-2 text-xs text-gray-500">El documento firmado (JWS) se genera solo al transmitir; en dry-run va como marcador. El token y la contraseña nunca se muestran.</p>
                    </div>
                @endif
            </div>
        </details>
    @endif
</div>
