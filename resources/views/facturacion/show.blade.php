<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Documento #{{ $dte->id }} — {{ $dte->tipo_dte->label() }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-rose-50 border border-rose-200 p-3 text-sm text-rose-700">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Aviso de MODO DTE (solo gestores): seguro (no emite producción) vs. emisión real posible. --}}
            <x-modo-dte-aviso :modo="$modoDte ?? null" />

            {{-- Aviso operativo (solo gestores): correos en cola sin procesar hace >5 min.
                 Solo lectura de la tabla jobs; el controlador lo calcula únicamente para gestores. --}}
            @if (($correosAtascados ?? 0) > 0)
                <div class="rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-800">
                    <strong>⚠ Hay {{ $correosAtascados }} {{ $correosAtascados === 1 ? 'correo' : 'correos' }} en cola sin procesar.</strong>
                    El worker parece apagado; abrí <code class="font-mono">start-queue.bat</code> para que salgan.
                </div>
            @endif

            {{-- Tarjeta de ESTADO (clara, de un vistazo). Solo lectura. --}}
            @php
                $correoEnviado = $dte->envios->contains(fn ($e) => in_array($e->estado, ['enviado', 'simulado'], true));
                $correoFallo = ! $correoEnviado && $dte->envios->contains(fn ($e) => $e->estado === 'error');
                $listoEmitir = ! empty($emisionProduccion['preflight']['puede']);
                [$stTxt, $stColor, $stSub] = match (true) {
                    $dte->estado === \App\Enums\EstadoDte::Invalidado => ['Invalidado', 'rose', 'Documento anulado/invalidado internamente.'],
                    $dte->estado === \App\Enums\EstadoDte::Rechazado  => ['Rechazado por Hacienda', 'rose', 'Revisá el motivo en la respuesta del MH.'],
                    $dte->estado === \App\Enums\EstadoDte::Aceptado   => ['Aceptado por Hacienda', 'green', $correoEnviado ? 'Correo enviado al cliente.' : 'Pendiente de enviar el correo al cliente.'],
                    $dte->estado === \App\Enums\EstadoDte::Firmado    => ['Firmado (pendiente de transmitir)', 'indigo', 'Firmado localmente; falta transmitir a Hacienda.'],
                    $dte->estado === \App\Enums\EstadoDte::Generado   => [$listoEmitir ? 'Listo para emitir' : 'Generado', 'indigo', 'Número reservado y JSON generado; falta firmar y transmitir.'],
                    default                                          => [$listoEmitir ? 'Listo para emitir' : 'Borrador', 'gray', 'En preparación; sin número ni JSON oficial todavía.'],
                };
                $stMap = [
                    'gray'   => 'bg-gray-100 text-gray-700 ring-gray-200',
                    'indigo' => 'bg-indigo-100 text-indigo-700 ring-indigo-200',
                    'green'  => 'bg-green-100 text-green-700 ring-green-200',
                    'amber'  => 'bg-amber-100 text-amber-700 ring-amber-200',
                    'rose'   => 'bg-rose-100 text-rose-700 ring-rose-200',
                ];
            @endphp
            <div class="bg-white shadow sm:rounded-lg p-4 flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold ring-1 {{ $stMap[$stColor] }}">{{ $stTxt }}</span>
                <span class="text-sm text-gray-500">{{ $stSub }}</span>
                <div class="ms-auto flex items-center gap-2">
                    @if ($dte->estado === \App\Enums\EstadoDte::Aceptado)
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $correoEnviado ? 'bg-green-100 text-green-700' : ($correoFallo ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">
                            {{ $correoEnviado ? 'Correo enviado' : ($correoFallo ? 'Correo falló' : 'Pendiente de correo') }}
                        </span>
                        @if (! empty($enReporteContadora))
                            <span class="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-medium text-sky-700" title="Aparece en Facturación → Reporte contadora (ventas reales)">En Reporte contadora</span>
                        @endif
                    @endif
                </div>
            </div>

            <div class="bg-white shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <h3 class="font-semibold text-gray-700">Datos del documento</h3>
                    <div class="flex items-center gap-3 flex-wrap">
                        <a href="{{ route('facturacion.imprimir', $dte) }}" target="_blank" class="text-gray-600 hover:underline text-sm">Imprimir</a>
                        <a href="{{ route('facturacion.pdf', $dte) }}" target="_blank" class="text-indigo-600 hover:underline text-sm">Ver PDF preliminar</a>
                        <a href="{{ route('facturacion.pdf.descargar', $dte) }}" class="text-indigo-600 hover:underline text-sm">Descargar PDF preliminar</a>

                        {{-- Solo el estado; la acción de enviar vive en la sección "Correo del cliente". --}}
                        @can('enviarCorreo', $dte)
                            @if ($dte->envios->contains(fn ($e) => in_array($e->estado, ['enviado', 'simulado'], true)))
                                <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                                    Enviado por correo
                                </span>
                            @endif
                        @endcan

                        {{-- Duplicar CCF: nuevo borrador con los mismos datos y líneas (no toca este documento). --}}
                        @if ($dte->tipo_dte === \App\Enums\TipoDte::CreditoFiscal && ! $dte->esEditable())
                            @can('create', App\Models\Dte::class)
                                <form method="POST" action="{{ route('facturacion.duplicar', $dte) }}" class="inline-flex"
                                      onsubmit="return confirm('¿Duplicar este CCF como un borrador nuevo? Este documento no se modifica.');">
                                    @csrf
                                    <button class="text-indigo-600 hover:underline text-sm">Duplicar</button>
                                </form>
                            @endcan
                        @endif

                        @can('update', $dte)
                            <a href="{{ route('facturacion.edit', $dte) }}" class="text-indigo-600 hover:underline text-sm">Editar</a>
                        @endcan
                    </div>
                </div>
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-gray-500">Cliente</dt><dd>{{ $dte->cliente?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Sala / sucursal</dt><dd>{{ $dte->clienteSucursal?->nombre ?? '—' }}</dd></div>
                    @if ($dte->tipo_nota_credito)
                        <div><dt class="text-gray-500">Tipo de NC</dt><dd>{{ $dte->tipo_nota_credito->label() }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">Estado</dt><dd><x-estado-dte-badge :estado="$dte->estado" /></dd></div>
                    <div><dt class="text-gray-500">Número interno</dt><dd class="font-mono">{{ $dte->numero_interno ?? '—' }}</dd></div>
                    @if ($dte->numero_control)
                        <div><dt class="text-gray-500">Número de control</dt><dd class="font-mono">{{ $dte->numero_control }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">Fecha</dt><dd>{{ $dte->fecha_emision?->format('d/m/Y') }}</dd></div>
                    <div><dt class="text-gray-500">Orden de compra</dt><dd>{{ $dte->numero_orden_compra ?? '—' }}</dd></div>
                    @if ($dte->dte_relacionado_id)
                        <div>
                            <dt class="text-gray-500">Documento original</dt>
                            <dd><a href="{{ route('facturacion.show', $dte->dteRelacionado) }}" class="text-indigo-600 hover:underline font-mono">
                                {{ $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}
                            </a></dd>
                        </div>
                    @elseif ($dte->tipo_dte === \App\Enums\TipoDte::NotaCredito)
                        <div>
                            <dt class="text-gray-500">Documento relacionado</dt>
                            <dd class="text-gray-400">Sin documento relacionado interno</dd>
                        </div>
                    @endif
                    @if ($dte->motivo)
                        <div class="md:col-span-2"><dt class="text-gray-500">Motivo</dt><dd>{{ $dte->motivo }}</dd></div>
                    @endif
                </dl>
            </div>

            {{-- Líneas y Totales: contenido real del documento, arriba y bien visible
                 (antes quedaban al final de la ficha, después de todos los paneles técnicos). --}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-700 mb-3">Líneas</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-3 py-2">#</th>
                                <th class="px-3 py-2">Descripción</th>
                                <th class="px-3 py-2 text-right">Precio</th>
                                <th class="px-3 py-2 text-right">Cantidad</th>
                                <th class="px-3 py-2 text-right">Descuento</th>
                                <th class="px-3 py-2 text-right">Gravado</th>
                                <th class="px-3 py-2 text-right">IVA</th>
                                <th class="px-3 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($dte->lineas as $linea)
                                <tr>
                                    <td class="px-3 py-2">{{ $linea->numero_linea }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $linea->descripcion }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->precio_unitario, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ rtrim(rtrim($linea->cantidad, '0'), '.') }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->descuento_monto, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->venta_gravada, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->iva_linea, 2) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">${{ number_format($linea->total_linea, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Sin líneas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Totales: partial único de presentación (no recalcula nada). --}}
            @include('facturacion.partials.totales', ['dte' => $dte, 'esAgenteRetencion' => $esAgenteRetencion ?? null])

            {{-- Aceptado: estado, sello, fecha de procesamiento y respuesta MH.
                 En modo MOCK (sello MOCK-…) se rotula como simulado para no confundir. --}}
            @if ($dte->estado === \App\Enums\EstadoDte::Aceptado)
                @php $esMock = \Illuminate\Support\Str::startsWith((string) $dte->sello_recepcion, 'MOCK'); @endphp
                <div class="bg-white shadow sm:rounded-lg p-6 border-l-4 {{ $esMock ? 'border-amber-400' : 'border-green-500' }}">
                    <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
                        <h3 class="font-semibold {{ $esMock ? 'text-amber-700' : 'text-green-700' }} flex items-center gap-2">
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                            {{ $esMock ? 'Aceptado simulado (MOCK)' : 'Aceptado por Hacienda' }}
                        </h3>
                        <span class="inline-block rounded-full px-3 py-1 text-xs font-medium {{ $esMock ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-700' }}">
                            {{ $esMock ? 'Estado: Aceptado (MOCK)' : 'Estado: Aceptado por MH' }}
                        </span>
                    </div>

                    @if ($esMock)
                        <div class="mb-4 bg-amber-50 border border-amber-300 rounded-md p-3 text-xs text-amber-800 font-semibold">
                            MODO PRUEBA / MOCK — NO VÁLIDO ANTE HACIENDA
                            <p class="mt-1 font-normal">
                                Aceptación <strong>simulada</strong> en modo prueba: el sello y la fecha de procesamiento son
                                ficticios. Este documento <strong>no fue transmitido ni aceptado por Hacienda</strong>.
                            </p>
                        </div>
                    @endif
                    <dl class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div class="sm:col-span-2"><dt class="text-gray-500">Sello de recepción</dt><dd class="font-mono break-all text-gray-800">{{ $dte->sello_recepcion ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Fecha/hora de procesamiento</dt><dd>{{ optional($dte->fecha_procesamiento_mh)->format('d/m/Y H:i:s') ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Número de control</dt><dd class="font-mono">{{ $dte->numero_control ?? '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-gray-500">Código de generación</dt><dd class="font-mono break-all">{{ $dte->codigo_generacion ?? '—' }}</dd></div>
                    </dl>

                    @if ($dte->respuesta_mh)
                        @php $r = $dte->respuesta_mh; @endphp
                        <details class="mt-4 group">
                            <summary class="cursor-pointer text-sm font-medium text-indigo-600 hover:text-indigo-800">Ver respuesta MH</summary>
                            <dl class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                <div><dt class="text-gray-500">Estado MH</dt><dd>{{ $r['estado'] ?? '—' }}</dd></div>
                                <div><dt class="text-gray-500">Código</dt><dd class="font-mono">{{ $r['codigoMsg'] ?? '—' }}</dd></div>
                                <div class="md:col-span-2"><dt class="text-gray-500">Descripción</dt><dd>{{ $r['descripcionMsg'] ?? '—' }}</dd></div>
                                @if (! empty($r['clasificaMsg']))
                                    <div><dt class="text-gray-500">Clasificación</dt><dd>{{ $r['clasificaMsg'] }}</dd></div>
                                @endif
                            </dl>
                            @if (! empty($r['observaciones']))
                                <div class="mt-3 text-sm">
                                    <dt class="text-gray-500">Observaciones</dt>
                                    <ul class="list-disc ml-5 text-gray-700">
                                        @foreach ($r['observaciones'] as $o)<li>{{ $o }}</li>@endforeach
                                    </ul>
                                </div>
                            @endif
                            @if ($dte->respuesta_mh_path)
                                <p class="mt-3 text-xs text-gray-400 font-mono break-all">Respuesta guardada: {{ $dte->respuesta_mh_path }}</p>
                            @endif
                        </details>
                    @endif
                </div>
            @endif

            {{-- Envío por correo al cliente (manual): destinatario editable + historial.
                 Presentación condicionada al estado: solo se ofrece la acción de enviar/reenviar
                 cuando el DTE está ACEPTADO por Hacienda; en cualquier otro estado se muestra un
                 aviso (la política enviarCorreo sigue igual, esto es solo visibilidad en la UI). --}}
            @can('enviarCorreo', $dte)
                @php
                    $correoDefault = $dte->clienteSucursal?->correo ?: ($dte->cliente?->correo ?? '');
                    $envios = $dte->envios;
                    $ultimo = $envios->first();
                    $estadoEnvio = match ($ultimo?->estado) {
                        'enviado' => ['Enviado', '🟢', 'bg-green-100 text-green-700'],
                        'simulado' => ['Simulado (prueba)', '🟣', 'bg-violet-100 text-violet-700'],
                        'pendiente' => ['Pendiente', '🟡', 'bg-amber-100 text-amber-700'],
                        'error' => ['Fallido', '🔴', 'bg-rose-100 text-rose-700'],
                        default => ['No enviado', '⚪', 'bg-gray-100 text-gray-600'],
                    };
                    $destinatariosUltimo = $ultimo?->destinatariosTexto();
                    $yaEnviado = $envios->contains(fn ($e) => in_array($e->estado, ['enviado', 'simulado'], true));
                    $mailerDriver = config('mail.default');
                    $mailerReal = ! in_array(config("mail.mailers.$mailerDriver.transport", $mailerDriver), ['log', 'array'], true);
                    $dteAceptado = $dte->estado === \App\Enums\EstadoDte::Aceptado;
                @endphp
                <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                    {{-- Header compacto: título + estado --}}
                    <div class="flex items-center justify-between gap-2 px-5 py-3 border-b border-gray-100 bg-gray-50/70">
                        <div class="flex items-center gap-2">
                            <svg class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4a2 2 0 00-2 2v1.161l8.441 4.221a1.25 1.25 0 001.118 0L19 7.162V6a2 2 0 00-2-2H3z"/><path d="M19 8.839l-7.77 3.885a2.75 2.75 0 01-2.46 0L1 8.839V14a2 2 0 002 2h14a2 2 0 002-2V8.839z"/></svg>
                            <h3 class="font-semibold text-gray-700">Correo del cliente</h3>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium {{ $estadoEnvio[2] }}">{{ $estadoEnvio[1] }} {{ $estadoEnvio[0] }}</span>
                    </div>

                    <div class="p-5 space-y-4">
                        @unless ($dteAceptado)
                            {{-- No aceptado todavía: se oculta la acción de enviar, solo aviso. --}}
                            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                El correo al cliente se habilita cuando el DTE esté aceptado por Hacienda.
                            </div>
                        @else
                            @unless ($mailerReal)
                                <div class="rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-800">
                                    <strong>Modo prueba (MAIL_MAILER={{ $mailerDriver }}):</strong> el correo <strong>no se envía realmente</strong>; se escribe en <code>storage/logs/laravel.log</code> y queda como <em>Simulado</em>.
                                </div>
                            @endunless

                            {{-- Destinatario principal + resumen del último envío --}}
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-4 py-3">
                                <div class="text-xs uppercase tracking-wide text-gray-400">Destinatario principal</div>
                                <div class="font-medium text-gray-800 break-all">{{ $correoDefault ?: 'Sin correo configurado en el cliente/sala' }}</div>
                                {{-- Copia a contabilidad (BCC) según Configuración > Contabilidad. Solo indicador. --}}
                                @if (! empty($copiaContabilidad['activa']) && ! empty($copiaContabilidad['correo']))
                                    <div class="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-sky-50 ring-1 ring-sky-200 px-2.5 py-0.5 text-xs text-sky-700">
                                        📎 Copia a contabilidad (BCC): <span class="font-medium break-all">{{ $copiaContabilidad['correo'] }}</span>
                                    </div>
                                @else
                                    <div class="mt-1.5 text-xs text-gray-400">Copia a contabilidad: desactivada (Configuración → Contabilidad).</div>
                                @endif
                                @if ($ultimo)
                                    <div class="mt-1 text-xs text-gray-500 break-all">
                                        Último envío: {{ $ultimo->created_at->format('d/m/Y H:i') }} · {{ $destinatariosUltimo }}
                                        @if ($ultimo->error)
                                            <span class="{{ $ultimo->estado === 'simulado' ? 'text-violet-600' : 'text-rose-600' }}"> · {{ $ultimo->estado === 'simulado' ? 'Nota' : 'Error' }}: {{ \Illuminate\Support\Str::limit($ultimo->error, 120) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            {{-- Form compacto: textarea bajo + botón a la derecha --}}
                            <form method="POST" action="{{ route('facturacion.correo.enviar', $dte) }}" class="space-y-1.5">
                                @csrf
                                <label for="destinatarios" class="block text-sm text-gray-600">Destinatarios <span class="text-gray-400">(uno por línea o separados por coma)</span></label>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <textarea id="destinatarios" name="destinatarios" rows="2" required
                                              placeholder="cliente@correo.com, contabilidad@empresa.com"
                                              class="flex-1 rounded-md border-gray-300 text-sm">{{ old('destinatarios', $correoDefault) }}</textarea>
                                    <button type="submit" class="shrink-0 self-start sm:self-stretch inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        {{ $yaEnviado ? 'Reenviar y abrir PDF' : 'Enviar correo y abrir PDF' }}
                                    </button>
                                </div>
                                @error('destinatarios')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                                <p class="text-xs text-gray-400">Adjunta el PDF{{ $dte->json_generado_path ? ' y el JSON oficial' : '' }}. El correo queda <strong>en cola</strong> (no espera al SMTP) y se abre el PDF para imprimir.</p>
                            </form>
                        @endunless

                        {{-- Historial compacto y plegable (visible aunque no esté aceptado, es solo lectura) --}}
                        @if ($envios->isNotEmpty())
                            <details class="group">
                                <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-400 hover:text-gray-600 select-none">
                                    Historial de envíos ({{ $envios->count() }})
                                </summary>
                                <div class="mt-2 overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-xs uppercase text-gray-400 border-b border-gray-100">
                                                <th class="py-1.5 pr-3">Fecha</th>
                                                <th class="py-1.5 pr-3">Destinatarios</th>
                                                <th class="py-1.5 pr-3">Estado</th>
                                                <th class="py-1.5 pr-3">Adjuntos</th>
                                                <th class="py-1.5 pr-3">Por</th>
                                                <th class="py-1.5 pr-3"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            @foreach ($envios as $e)
                                                <tr>
                                                    <td class="py-1.5 pr-3 whitespace-nowrap text-gray-600">{{ $e->created_at->format('d/m/Y H:i') }}</td>
                                                    <td class="py-1.5 pr-3 break-all text-gray-600">{{ $e->destinatariosTexto() }}</td>
                                                    <td class="py-1.5 pr-3 whitespace-nowrap">
                                                        @php
                                                            $clase = ['enviado' => 'bg-green-100 text-green-700', 'simulado' => 'bg-violet-100 text-violet-700', 'pendiente' => 'bg-amber-100 text-amber-700', 'error' => 'bg-rose-100 text-rose-700'][$e->estado] ?? 'bg-gray-100 text-gray-600';
                                                            $etiqueta = ['enviado' => 'Enviado', 'simulado' => 'Simulado', 'pendiente' => 'Pendiente', 'error' => 'Fallido'][$e->estado] ?? ucfirst($e->estado);
                                                        @endphp
                                                        <span class="inline-block rounded px-2 py-0.5 text-xs {{ $clase }}">{{ $etiqueta }}</span>
                                                        @if ($e->error)<span class="ml-1 cursor-help {{ $e->estado === 'simulado' ? 'text-violet-500' : 'text-rose-500' }}" title="{{ $e->error }}">ⓘ</span>@endif
                                                    </td>
                                                    <td class="py-1.5 pr-3 text-gray-500">{{ $e->adjuntos ?? '—' }}</td>
                                                    <td class="py-1.5 pr-3 text-gray-500">{{ $e->usuario?->name ?? 'Automático' }}</td>
                                                    <td class="py-1.5 pr-3 text-right">
                                                        @if ($dteAceptado)
                                                            <form method="POST" action="{{ route('facturacion.correo.reenviar', [$dte, $e]) }}">
                                                                @csrf
                                                                <button class="text-indigo-600 hover:underline text-xs">Reenviar</button>
                                                            </form>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
                    </div>
                </div>
            @endcan

            {{-- Crear nota de crédito SOLO desde un CCF ACEPTADO por Hacienda (regla de
                 negocio): nunca desde generado/firmado, para no vincular una NC a un
                 documento que aún no existe oficialmente ante el MH. --}}
            @if ($dte->tipo_dte === \App\Enums\TipoDte::CreditoFiscal
                && $dte->estado === \App\Enums\EstadoDte::Aceptado)
                @can('create', App\Models\Dte::class)
                    <div class="bg-white shadow sm:rounded-lg p-4">
                        <h3 class="font-semibold text-gray-700 mb-2">Crear nota de crédito</h3>
                        <form method="POST" action="{{ route('facturacion.nota-credito.store', $dte) }}"
                              class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end"
                              onsubmit="return confirm('¿Crear una nota de crédito para este CCF?');">
                            @csrf
                            <div>
                                <x-input-label for="tipo_nc" value="Tipo de nota de crédito *" />
                                <select id="tipo_nc" name="tipo" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                    <option value="">— Seleccione —</option>
                                    @foreach (\App\Enums\TipoNotaCredito::opciones() as $valor => $label)
                                        <option value="{{ $valor }}" @selected(old('tipo') === $valor)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('tipo')" class="mt-1" />
                                <p class="mt-1 text-xs text-gray-400">Seleccione el tipo de nota de crédito. Devolución y faltante usan las líneas de este CCF. Avería permite otros productos. Pronto pago y ajustes usan conceptos manuales.</p>
                            </div>
                            <div class="md:col-span-2">
                                <x-input-label for="motivo" value="Motivo / observaciones (opcional)" />
                                <x-text-input id="motivo" name="motivo" type="text" class="mt-1 block w-full"
                                              placeholder="Ej. Devolución parcial de mercadería" :value="old('motivo')" />
                            </div>
                            <div class="md:col-span-3">
                                <button class="inline-flex items-center px-4 py-2 bg-rose-600 text-white text-sm rounded-md hover:bg-rose-700">
                                    Crear nota de crédito
                                </button>
                            </div>
                        </form>
                    </div>
                @endcan
            @endif

            {{-- Acción REAL de PRODUCCIÓN, explícita y separada: "Generar y transmitir producción".
                 Preflight de seguridad + barrera anti-Conta + frase EMITIR PRODUCCION. No envía correo.
                 Es la acción PRINCIPAL guiada cuando el CCF está listo para emitir: se queda VISIBLE
                 (no colapsada), a diferencia del panel manual paso a paso de más abajo. --}}
            @if (! empty($emisionProduccion))
                @php
                    $pf = $emisionProduccion['preflight'];
                    $rp = $emisionProduccion['resumen'];
                @endphp
                <div x-data="{ open: false, enviando: false }" class="bg-white shadow sm:rounded-lg p-6 border-l-4 border-indigo-600">
                    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                        <div>
                            <h3 class="font-semibold text-gray-800 text-lg">Emitir a Hacienda</h3>
                            <p class="text-xs text-gray-500">Genera, firma y transmite en producción — en un solo paso.</p>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $pf['puede'] ? 'bg-green-100 text-green-700' : 'bg-rose-100 text-rose-700' }}">
                            {{ $pf['puede'] ? 'Preflight OK · listo' : 'Preflight bloqueado' }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500">
                        El correo al cliente queda <strong>separado</strong> (se envía después de aceptado). El botón normal
                        “Generar” es solo local y no transmite.
                    </p>

                    {{-- Checklist del preflight (cada precondición) --}}
                    <ul class="mt-3 space-y-1 text-sm">
                        @foreach ($pf['checks'] as $c)
                            <li class="flex items-start gap-2">
                                <span class="mt-0.5 inline-block h-4 w-4 shrink-0 rounded-full text-center text-xs leading-4 {{ $c['ok'] ? 'bg-green-100 text-green-700' : 'bg-rose-100 text-rose-700' }}">{{ $c['ok'] ? '✓' : '✗' }}</span>
                                <span class="{{ $c['ok'] ? 'text-gray-600' : 'text-rose-700 font-medium' }}">{{ $c['label'] }} <span class="text-xs text-gray-400">— {{ $c['detalle'] }}</span></span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-4">
                        @if ($pf['puede'])
                            <button type="button" @click="open = true"
                                    class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md font-semibold">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3.105 2.289a.75.75 0 00-.826.95l1.414 4.925A1.5 1.5 0 005.135 9.25h6.115a.75.75 0 010 1.5H5.135a1.5 1.5 0 00-1.442 1.086l-1.414 4.926a.75.75 0 00.826.95 28.897 28.897 0 0015.293-7.155.75.75 0 000-1.114A28.897 28.897 0 003.105 2.289z"/></svg>
                                Emitir a Hacienda
                            </button>
                        @else
                            <button type="button" disabled title="Faltan precondiciones del preflight"
                                    class="inline-flex items-center px-5 py-2.5 bg-gray-100 text-gray-400 text-sm rounded-md font-semibold cursor-not-allowed">
                                Emitir a Hacienda
                            </button>
                            <p class="mt-1 text-xs text-rose-600">Falta: {{ implode('; ', $pf['faltantes']) }}.</p>
                        @endif
                    </div>

                    {{-- Modal de confirmación: resumen + barrera + frase EMITIR PRODUCCION --}}
                    @if ($pf['puede'])
                        <div x-show="open" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
                            <div class="flex min-h-full items-center justify-center p-4">
                                <div class="fixed inset-0 bg-gray-900/50" @click="open = false"></div>
                                <div class="relative bg-white rounded-xl shadow-xl ring-1 ring-gray-200 w-full max-w-lg p-6">
                                    <h3 class="text-lg font-semibold text-rose-700">Confirmar emisión a PRODUCCIÓN</h3>

                                    {{-- Número del documento a emitir + contexto: distingue el documento actual
                                         (ya reservado si el CCF ya fue generado) del último real de Conta y del
                                         próximo que quedará libre DESPUÉS de aceptar este. --}}
                                    <div class="mt-3 rounded-lg bg-indigo-50 ring-1 ring-indigo-200 p-4">
                                        <div class="text-center">
                                            <p class="text-xs uppercase tracking-wide text-indigo-500">Documento actual a emitir</p>
                                            <p class="mt-0.5 text-4xl font-bold tabular-nums text-indigo-700">{{ $rp['documento_actual'] }}</p>
                                        </div>
                                        <dl class="mt-3 grid grid-cols-2 gap-2 border-t border-indigo-100 pt-3 text-xs text-gray-600">
                                            <div><dt class="text-gray-400">Último real externo/Conta</dt><dd class="font-semibold">{{ $rp['externo_ultimo'] }}</dd></div>
                                            <div><dt class="text-gray-400">Próximo tras aceptar este</dt><dd class="font-semibold">{{ $rp['proximo_futuro'] }}</dd></div>
                                        </dl>
                                    </div>

                                    <div class="mt-3 rounded-md bg-rose-600 p-3 text-sm text-white font-semibold text-center">
                                        ⚠ Esto se enviará a Hacienda <span class="underline">de verdad</span> (producción). No se deshace fácilmente.
                                    </div>

                                    <dl class="mt-3 divide-y divide-gray-100 text-sm">
                                        <div class="flex justify-between py-1.5"><dt class="text-gray-500">Cliente</dt><dd class="font-medium text-gray-900">{{ $rp['cliente'] ?? '—' }}</dd></div>
                                        <div class="flex justify-between py-1.5"><dt class="text-gray-500">Sala</dt><dd class="font-medium text-gray-900">{{ $rp['sala'] ?? '—' }}</dd></div>
                                        <div class="flex justify-between py-1.5"><dt class="text-gray-500">Orden de compra</dt><dd class="font-medium text-gray-900">{{ $rp['oc'] ?? '—' }}</dd></div>
                                        <div class="flex justify-between py-1.5"><dt class="text-gray-500">Total gravado</dt><dd class="font-medium text-gray-900">${{ number_format($rp['total_gravado'], 2) }}</dd></div>
                                        <div class="flex justify-between py-1.5"><dt class="text-gray-500">IVA</dt><dd class="font-medium text-gray-900">${{ number_format($rp['iva'], 2) }}</dd></div>
                                        @if ($rp['aplica_retencion'])
                                            <div class="flex justify-between py-1.5"><dt class="text-gray-500">Retención IVA</dt><dd class="font-medium text-gray-900">-${{ number_format($rp['retencion'], 2) }}</dd></div>
                                        @endif
                                        <div class="flex justify-between py-1.5"><dt class="text-gray-500">Total a pagar</dt><dd class="font-semibold text-gray-900">${{ number_format($rp['total_pagar'], 2) }}</dd></div>
                                        <div class="flex justify-between py-1.5"><dt class="text-gray-500">Correo destino</dt><dd class="font-medium text-gray-900">{{ $rp['correo_destino'] ?? '(sin correo — se enviará aparte)' }}</dd></div>
                                    </dl>

                                    {{-- Valida (frase/barrera/confirm) y solo si pasa marca "enviando" (deshabilita el botón
                                         y evita doble submit). Si no pasa, previene el envío y deja el botón activo. --}}
                                    <form method="POST" action="{{ route('facturacion.generar-transmitir-produccion', $dte) }}" class="mt-4"
                                          @submit="dteConfirmarProduccion($event.target) ? (enviando = true) : $event.preventDefault()">
                                        @csrf
                                        <label class="flex items-start gap-2 text-sm text-gray-700">
                                            <input type="checkbox" name="barrera_conta" value="1" class="mt-0.5 rounded border-gray-300">
                                            <span>Confirmo que Conta Portable quedó detenido/alineado. Documento actual a emitir: {{ $rp['documento_actual'] }}. Último real externo/Conta: {{ $rp['externo_ultimo'] }}. Próximo después de aceptar este documento: {{ $rp['proximo_futuro'] }}.</span>
                                        </label>
                                        <label class="mt-3 block text-xs font-semibold text-rose-700">Escribí exactamente: <span class="font-mono">EMITIR PRODUCCION</span></label>
                                        <input type="text" name="confirmacion_emision" autocomplete="off" spellcheck="false" placeholder="EMITIR PRODUCCION"
                                               class="mt-1 w-full rounded-md border-rose-300 text-sm font-mono focus:border-rose-500 focus:ring-rose-500">
                                        <div class="mt-5 flex items-center justify-end gap-3">
                                            <button type="button" @click="open = false" :disabled="enviando" class="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:opacity-50">Cancelar</button>
                                            <button type="submit" :disabled="enviando"
                                                    class="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 disabled:opacity-60 disabled:cursor-not-allowed">
                                                <span x-show="!enviando">Emitir a Hacienda ahora</span>
                                                <span x-show="enviando" x-cloak>Procesando…</span>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <script>
                    function dteConfirmarProduccion(form) {
                        if (!form.barrera_conta.checked) {
                            alert('Marcá la confirmación de Conta Portable (barrera) antes de emitir.');
                            return false;
                        }
                        var val = ((form.confirmacion_emision && form.confirmacion_emision.value) || '').trim();
                        if (val !== 'EMITIR PRODUCCION') {
                            alert('Para emitir a producción debés escribir exactamente: EMITIR PRODUCCION');
                            return false;
                        }
                        return confirm('¿GENERAR, FIRMAR Y TRANSMITIR a Hacienda producción? Esto es real y no se deshace fácilmente.');
                    }
                </script>
            @endif

            {{-- Acción MANUAL única: firmar y transmitir (gestores; estado generado/firmado, sin sello).
                 SIEMPRE colapsada bajo "Avanzado": el camino principal es "Emitir a Hacienda" de arriba
                 (cuando aplica); esto es para pruebas o para hacerlo a mano paso a paso. --}}
            @can('firmarTransmitir', $dte)
                @php
                    $modoMock = (bool) config('dte.firma.mock') || (bool) config('dte.transmision.mock');
                    $yaFirmado = $dte->estado === \App\Enums\EstadoDte::Firmado;
                    $etiquetaBoton = $yaFirmado ? 'Reintentar transmisión' : 'Firmar y transmitir';
                    // ¿Puede emitir REAL a producción ahora? (candados abiertos + ambiente producción).
                    // En modo seguro es false y solo aplica la doble confirmación normal.
                    $emisionReal = ! empty($modoDte['transmision_real_posible']);
                @endphp
                <details class="group rounded-lg border border-dashed border-gray-300 bg-gray-50/60">
                    <summary class="cursor-pointer select-none px-5 py-3 text-sm font-semibold text-gray-600 hover:text-gray-800">
                        Avanzado · emisión manual paso a paso
                        <span class="ml-1 font-normal text-gray-400">(Generar / Firmar y transmitir)</span>
                        <p class="mt-0.5 text-xs font-normal text-gray-400">
                            Para pruebas o si necesitás hacerlo por pasos. Normalmente usá
                            <span class="font-semibold text-gray-500">“Emitir a Hacienda”</span> de arriba.
                        </p>
                    </summary>
                    <div class="px-5 pb-5 pt-1">
                        <div class="bg-white shadow sm:rounded-lg p-6 border-l-4 {{ $emisionReal ? 'border-rose-600' : ($modoMock ? 'border-amber-400' : 'border-emerald-500') }}">
                            <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                                <h3 class="font-semibold text-gray-700">Firmar y transmitir</h3>
                                @if ($emisionReal)
                                    <span class="inline-flex items-center rounded-full bg-rose-600 px-2.5 py-0.5 text-xs font-bold text-white animate-pulse">
                                        EMISIÓN REAL A PRODUCCIÓN
                                    </span>
                                @elseif ($modoMock)
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                                        MODO PRUEBA (MOCK)
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">
                                        MODO SEGURO — no emite producción
                                    </span>
                                @endif
                            </div>
                            <p class="text-sm text-gray-500">
                                @if ($yaFirmado)
                                    El documento ya está <strong>firmado localmente</strong>. Esta acción solo reintenta la
                                    transmisión (no vuelve a firmar ni consume correlativo).
                                @else
                                    Acción manual: genera el JSON si falta, firma el documento y lo transmite en un solo paso.
                                    Es <strong>idempotente</strong>: no re-firma si ya hay firma ni retransmite si ya fue aceptado.
                                @endif
                            </p>

                            @if ($emisionReal)
                                <div class="mt-3 bg-rose-50 border border-rose-300 rounded-md p-3 text-xs text-rose-800 font-semibold">
                                    ⚠ EMISIÓN REAL A PRODUCCIÓN HABILITADA
                                    <p class="mt-1 font-normal">
                                        Los candados están abiertos y el ambiente es de <strong>producción</strong>: esta acción
                                        transmite el documento a Hacienda <strong>de verdad</strong>. Para continuar, escribí la
                                        frase exacta <span class="font-mono font-bold">EMITIR PRODUCCION</span>.
                                    </p>
                                </div>
                            @elseif ($modoMock)
                                <div class="mt-3 bg-amber-50 border border-amber-300 rounded-md p-3 text-xs text-amber-800 font-semibold">
                                    SIMULACIÓN (MOCK) — NO EMITE A HACIENDA
                                    <p class="mt-1 font-normal">
                                        La firma y la aceptación son <strong>simuladas</strong> (sin firmador real ni envío a Hacienda),
                                        para validar el flujo en local. El sello será ficticio (<span class="font-mono">MOCK-SIMULADO-…</span>).
                                    </p>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('facturacion.firmar-transmitir', $dte) }}" class="mt-4"
                                  onsubmit="return dteConfirmarEmision(this, {{ $emisionReal ? 'true' : 'false' }});">
                                @csrf
                                @if ($emisionReal)
                                    <div class="mb-3">
                                        <label class="block text-xs font-semibold text-rose-700 mb-1">
                                            Escribí exactamente para emitir real: <span class="font-mono">EMITIR PRODUCCION</span>
                                        </label>
                                        <input type="text" name="confirmacion_emision" autocomplete="off" spellcheck="false"
                                               placeholder="EMITIR PRODUCCION"
                                               class="w-72 rounded-md border-rose-300 text-sm font-mono focus:border-rose-500 focus:ring-rose-500">
                                    </div>
                                @endif
                                <button class="inline-flex items-center px-4 py-2 {{ $emisionReal ? 'bg-rose-600 hover:bg-rose-700' : ($modoMock ? 'bg-amber-600 hover:bg-amber-700' : 'bg-emerald-600 hover:bg-emerald-700') }} text-white text-sm rounded-md font-medium">
                                    {{ $emisionReal ? 'EMITIR A PRODUCCIÓN' : $etiquetaBoton }}{{ (! $emisionReal && $modoMock) ? ' (prueba)' : '' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </details>

                {{-- Guardia de UI (defensa en profundidad; el servidor RE-VALIDA la frase y los candados).
                     Modo seguro: doble confirmación. Producción real: frase exacta + confirmación. --}}
                <script>
                    function dteConfirmarEmision(form, esReal) {
                        if (esReal) {
                            var val = ((form.confirmacion_emision && form.confirmacion_emision.value) || '').trim();
                            if (val !== 'EMITIR PRODUCCION') {
                                alert('Para EMITIR REAL debés escribir exactamente la frase: EMITIR PRODUCCION');
                                return false;
                            }
                            return confirm('¿EMITIR REAL A PRODUCCIÓN? Esto transmite el documento a Hacienda de verdad y no se deshace fácilmente.');
                        }
                        if (!confirm('¿Firmar y transmitir este documento? (SIMULACIÓN — no se envía nada real a Hacienda).')) {
                            return false;
                        }
                        return confirm('Confirmá una vez más: ejecutar firmar/transmitir en MODO SEGURO.');
                    }
                </script>
            @endcan

            {{-- JSON y firma (detalles técnicos): acordeón colapsado. Agrupa el backfill de JSON
                 pendiente, el JSON oficial preliminar y el JWS firmado localmente — información de
                 diagnóstico que no hace falta ver de un vistazo. --}}
            @php
                $jsonPendiente = ! $dte->json_generado_path
                    && $dte->estado === \App\Enums\EstadoDte::Generado
                    && auth()->user()?->can('generarJson', $dte);
                $hayJsonOFirma = $jsonPendiente || $dte->json_generado_path || $dte->json_firmado_path;
            @endphp
            @if ($hayJsonOFirma)
                <details class="group rounded-lg border border-dashed border-gray-300 bg-gray-50/60">
                    <summary class="cursor-pointer select-none px-5 py-3 text-sm font-semibold text-gray-600 hover:text-gray-800">
                        JSON y firma
                        <span class="ml-1 font-normal text-gray-400">(detalles técnicos)</span>
                    </summary>
                    <div class="px-5 pb-5 pt-1 space-y-6">
                        {{-- Regenerar JSON oficial: herramienta de diagnóstico/backfill. Normalmente el JSON
                             se crea solo al generar el documento; este bloque solo aparece si quedó sin JSON
                             (documento viejo o generación previa sin JSON). Solo generado y solo gestores. --}}
                        @if ($jsonPendiente)
                            <div class="bg-white shadow sm:rounded-lg p-6 border-l-4 border-amber-400">
                                <h3 class="font-semibold text-gray-700 mb-2">JSON oficial pendiente</h3>
                                <p class="text-sm text-gray-500">
                                    Este documento quedó <strong>sin JSON oficial</strong> (lo normal es que se genere automáticamente al
                                    generar el documento). Generalo acá para poder enviarlo por correo. Se valida contra el schema del MH y
                                    se guarda localmente. <strong>No firma, no transmite ni envía nada a Hacienda.</strong>
                                </p>
                                <form method="POST" action="{{ route('facturacion.json.generar', $dte) }}" class="mt-3"
                                      onsubmit="return confirm('¿Generar el JSON oficial? No se firma ni se transmite.');">
                                    @csrf
                                    <button class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                        Generar JSON oficial
                                    </button>
                                </form>
                            </div>
                        @endif

                        {{-- JSON oficial preliminar generado localmente (solo si existe el archivo) --}}
                        @if ($dte->json_generado_path)
                            <div class="bg-white shadow sm:rounded-lg p-6">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="font-semibold text-gray-700">JSON oficial (preliminar)</h3>
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
                                        JSON generado localmente
                                    </span>
                                </div>
                                <dl class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <dt class="text-gray-500">Número de control</dt>
                                        <dd class="font-mono break-all">{{ $dte->numero_control ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">Código de generación</dt>
                                        <dd class="font-mono break-all">{{ $dte->codigo_generacion ?? '—' }}</dd>
                                    </div>
                                    @can('verJson', $dte)
                                        <div>
                                            <dt class="text-gray-500">Archivo JSON</dt>
                                            <dd class="font-mono break-all text-gray-600">{{ $dte->json_generado_path }}</dd>
                                        </div>
                                    @endcan
                                </dl>

                                <div class="mt-4 bg-amber-50 border border-amber-300 rounded-md p-3 text-xs text-amber-800 font-semibold">
                                    SIN FIRMA / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA
                                    <p class="mt-1 font-normal">
                                        Es un JSON preliminar local. No equivale a un DTE emitido ante Hacienda hasta
                                        completar firma, transmisión, recepción/sello y PDF definitivo.
                                    </p>
                                </div>

                                @can('verJson', $dte)
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <a href="{{ route('facturacion.json', $dte) }}" target="_blank"
                                           class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                            Ver JSON generado
                                        </a>
                                        <a href="{{ route('facturacion.json.descargar', $dte) }}"
                                           class="inline-flex items-center px-4 py-2 bg-gray-700 text-white text-sm rounded-md hover:bg-gray-800">
                                            Descargar JSON generado
                                        </a>
                                    </div>
                                @endcan
                            </div>
                        @endif

                        {{-- DTE firmado localmente (solo si existe el JWS firmado) --}}
                        @if ($dte->json_firmado_path)
                            <div class="bg-white shadow sm:rounded-lg p-6">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="font-semibold text-gray-700">DTE firmado localmente</h3>
                                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                                        Firmado localmente
                                    </span>
                                </div>
                                <dl class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <dt class="text-gray-500">Número de control</dt>
                                        <dd class="font-mono break-all">{{ $dte->numero_control ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">Código de generación</dt>
                                        <dd class="font-mono break-all">{{ $dte->codigo_generacion ?? '—' }}</dd>
                                    </div>
                                    @can('verJsonFirmado', $dte)
                                        <div>
                                            <dt class="text-gray-500">Archivo firmado (JWS)</dt>
                                            <dd class="font-mono break-all text-gray-600">{{ $dte->json_firmado_path }}</dd>
                                        </div>
                                    @endcan
                                </dl>

                                <div class="mt-4 bg-amber-50 border border-amber-300 rounded-md p-3 text-xs text-amber-800 font-semibold">
                                    FIRMADO LOCALMENTE / SIN TRANSMISIÓN / NO ENVIADO A HACIENDA
                                    <p class="mt-1 font-normal">
                                        El documento está firmado en local. No equivale a un DTE emitido ante Hacienda
                                        hasta completar la transmisión, la recepción/sello y el PDF definitivo.
                                    </p>
                                </div>

                                @can('verJsonFirmado', $dte)
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <a href="{{ route('facturacion.firmado', $dte) }}" target="_blank"
                                           class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                            Ver JWS firmado
                                        </a>
                                        <a href="{{ route('facturacion.firmado.descargar', $dte) }}"
                                           class="inline-flex items-center px-4 py-2 bg-gray-700 text-white text-sm rounded-md hover:bg-gray-800">
                                            Descargar JWS firmado
                                        </a>
                                    </div>
                                @endcan
                            </div>
                        @endif
                    </div>
                </details>
            @endif

            {{-- Estado técnico DTE + Preflight de transmisión (solo gestores; diagnóstico).
                 Acordeón colapsado: es información técnica, no hace falta verla de entrada. --}}
            @if ($tecnico)
                @php $f = $tecnico['candados']['flags']; @endphp
                <details class="group rounded-lg border border-dashed border-gray-300 bg-gray-50/60">
                    <summary class="cursor-pointer select-none px-5 py-3 text-sm font-semibold text-gray-600 hover:text-gray-800">
                        Estado técnico y preflight
                        <span class="ml-1 font-normal text-gray-400">(diagnóstico de transmisión)</span>
                    </summary>
                    <div class="px-5 pb-5 pt-1">
                        <div class="bg-white shadow sm:rounded-lg p-6">
                            <h3 class="font-semibold text-gray-700 mb-3">Estado técnico DTE</h3>
                            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div><dt class="text-gray-500">Tipo DTE</dt><dd>{{ $dte->tipo_dte->value }} — {{ $dte->tipo_dte->label() }}</dd></div>
                                <div><dt class="text-gray-500">Estado interno</dt><dd>{{ $dte->estado->label() }}</dd></div>
                                <div><dt class="text-gray-500">numeroControl</dt><dd class="font-mono break-all">{{ $dte->numero_control ?? '—' }}</dd></div>
                                <div><dt class="text-gray-500">codigoGeneracion</dt><dd class="font-mono break-all">{{ $dte->codigo_generacion ?? '—' }}</dd></div>
                                <div><dt class="text-gray-500">JSON generado</dt><dd>{{ $dte->json_generado_path ? 'sí' : 'no' }}</dd></div>
                                <div class="md:col-span-3"><dt class="text-gray-500">Ruta JSON generado</dt><dd class="font-mono break-all text-gray-600">{{ $dte->json_generado_path ?? '—' }}</dd></div>
                                <div><dt class="text-gray-500">JWS firmado</dt><dd>{{ $dte->json_firmado_path ? 'sí' : 'no' }}</dd></div>
                                <div class="md:col-span-3"><dt class="text-gray-500">Ruta JWS firmado</dt><dd class="font-mono break-all text-gray-600">{{ $dte->json_firmado_path ?? '—' }}</dd></div>
                                <div><dt class="text-gray-500">Sello de recepción</dt><dd>{{ $dte->sello_recepcion ? 'sí' : 'no' }}</dd></div>
                                <div><dt class="text-gray-500">Estado aceptado</dt><dd>{{ $dte->estado === \App\Enums\EstadoDte::Aceptado ? 'sí' : 'no' }}</dd></div>
                                <div><dt class="text-gray-500">Invalidado / anulado</dt><dd>{{ $dte->esAnulado() ? 'sí' : 'no' }}</dd></div>
                            </dl>

                            <h3 class="font-semibold text-gray-700 mt-6 mb-3">Preflight de transmisión</h3>
                            <dl class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                                <div><dt class="text-gray-500">DTE_TRANSMISION_ENABLED</dt><dd>{{ $f['enabled'] ? 'habilitado' : 'bloqueado' }}</dd></div>
                                <div><dt class="text-gray-500">DTE_TRANSMISION_DRY_RUN</dt><dd>{{ $f['dry_run'] ? 'activo' : 'inactivo' }}</dd></div>
                                <div><dt class="text-gray-500">DTE_TRANSMISION_REAL_CONFIRMATION</dt><dd>{{ $f['real_confirmation'] ? 'sí' : 'no' }}</dd></div>
                                <div><dt class="text-gray-500">DTE_TRANSMISION_ALLOW_PRODUCTION</dt><dd>{{ $f['allow_production'] ? 'sí' : 'no' }}</dd></div>
                                <div><dt class="text-gray-500">DTE_MODO_OPERACION</dt><dd>{{ $f['modo_operacion'] }}</dd></div>
                                <div><dt class="text-gray-500">DTE_SISTEMA_ACTUAL_ACTIVO</dt><dd>{{ $f['sistema_actual_activo'] ? 'sí' : 'no' }}</dd></div>
                            </dl>

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                <span class="text-sm text-gray-600">Resultado:</span>
                                @if ($tecnico['resultado'] === 'LISTO PARA TRANSMISIÓN')
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">LISTO PARA TRANSMISIÓN</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">BLOQUEADO</span>
                                @endif
                                @if ($tecnico['dry_run_disponible'])
                                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">DRY-RUN DISPONIBLE</span>
                                @endif
                            </div>

                            @if (! empty($tecnico['candados']['razones']))
                                <ul class="mt-3 text-xs text-gray-500 list-disc list-inside space-y-1">
                                    @foreach ($tecnico['candados']['razones'] as $razon)
                                        <li>{{ $razon }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Botones seguros (gestores). NO hay botón de transmitir real. --}}
                            <div class="mt-4 flex flex-wrap gap-3">
                                @if ($dte->json_generado_path)
                                    @can('verJson', $dte)
                                        <a href="{{ route('facturacion.json', $dte) }}" target="_blank" class="inline-flex items-center px-3 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Ver JSON generado</a>
                                        <a href="{{ route('facturacion.json.descargar', $dte) }}" class="inline-flex items-center px-3 py-2 bg-gray-700 text-white text-sm rounded-md hover:bg-gray-800">Descargar JSON</a>
                                    @endcan
                                @endif
                                @if ($dte->json_firmado_path)
                                    @can('verJsonFirmado', $dte)
                                        <a href="{{ route('facturacion.firmado', $dte) }}" target="_blank" class="inline-flex items-center px-3 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Ver JWS firmado</a>
                                        <a href="{{ route('facturacion.firmado.descargar', $dte) }}" class="inline-flex items-center px-3 py-2 bg-gray-700 text-white text-sm rounded-md hover:bg-gray-800">Descargar JWS</a>
                                    @endcan
                                @endif
                                @if ($tecnico['dry_run_disponible'])
                                    <form method="POST" action="{{ route('facturacion.dry-run', $dte) }}"
                                          onsubmit="return confirm('¿Ejecutar dry-run? Solo diagnóstico: no transmite, no guarda sello, no cambia estado.');">
                                        @csrf
                                        <button class="inline-flex items-center px-3 py-2 bg-amber-600 text-white text-sm rounded-md hover:bg-amber-700">Ejecutar dry-run visual</button>
                                    </form>
                                @endif
                            </div>

                            <p class="mt-4 text-xs text-amber-800 font-semibold">SOLO DIAGNÓSTICO — NO TRANSMITE / NO AUTENTICA / NO GUARDA SELLO / NO CAMBIA ESTADO</p>

                            {{-- Resumen del último dry-run (seguro: sin token, sin contraseña, sin JWS completo) --}}
                            @if ($tecnico['dry_run'])
                                @php $dr = $tecnico['dry_run']; @endphp
                                <div class="mt-4 bg-gray-50 border border-gray-200 rounded-md p-4 text-sm">
                                    <h4 class="font-semibold text-gray-700 mb-2">Resultado del dry-run (payload preparado, no transmitido)</h4>
                                    <dl class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                        <div><dt class="text-gray-500">tipoDte</dt><dd>{{ $dr['tipoDte'] }}</dd></div>
                                        <div><dt class="text-gray-500">ambiente (MH)</dt><dd>{{ $dr['ambiente'] }}</dd></div>
                                        <div><dt class="text-gray-500">ambiente (transmisión)</dt><dd>{{ $dr['ambiente_transmision'] }}</dd></div>
                                        <div><dt class="text-gray-500">version</dt><dd>{{ $dr['version'] }}</dd></div>
                                        <div><dt class="text-gray-500">codigoGeneracion</dt><dd class="font-mono break-all">{{ $dr['codigoGeneracion'] }}</dd></div>
                                        <div><dt class="text-gray-500">JWS firmado</dt><dd>{{ $dr['tiene_jws'] ? 'sí' : 'no' }} ({{ $dr['jws_preview'] }})</dd></div>
                                        <div><dt class="text-gray-500">endpoint</dt><dd class="font-mono break-all">{{ $dr['endpoint'] }}</dd></div>
                                        <div><dt class="text-gray-500">auth configurado</dt><dd>{{ $dr['auth_configurado'] ? 'sí' : 'no' }}</dd></div>
                                    </dl>
                                    <p class="mt-2 text-xs text-gray-500">El JWS se muestra solo como vista previa truncada; el token y la contraseña nunca se muestran.</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </details>
            @endif

            {{-- Zona peligrosa: invalidación oficial + anulación interna. Acordeón colapsado por
                 defecto, pero AUTO-ABIERTO si el documento ya está anulado/invalidado (para no
                 esconder esa información crítica). --}}
            @php
                $puedeAnular = auth()->user()?->can('anular', $dte);
                $hayZonaPeligrosa = ($invalidacion ?? false) || $dte->esAnulado() || $puedeAnular;
            @endphp
            @if ($hayZonaPeligrosa)
                <details class="group rounded-lg border border-rose-200 bg-rose-50/40" @if ($dte->esAnulado()) open @endif>
                    <summary class="cursor-pointer select-none px-5 py-3 text-sm font-semibold text-rose-700 hover:text-rose-800">
                        ⚠ Zona peligrosa
                        <span class="ml-1 font-normal text-rose-400">(anulación / invalidación)</span>
                    </summary>
                    <div class="px-5 pb-5 pt-1 space-y-6">
                        {{-- Invalidación oficial (evento anulardte): SOLO mock + dry-run visual (gestores). --}}
                        @if ($invalidacion ?? false)
                            @include('facturacion.partials.invalidacion', ['dte' => $dte, 'invalidacion' => $invalidacion])
                        @endif

                        {{-- Aviso de anulación interna --}}
                        @if ($dte->esAnulado())
                            <div class="bg-rose-50 border border-rose-300 rounded-lg p-4 text-sm text-rose-800">
                                <p class="font-bold">DOCUMENTO ANULADO / INVALIDADO INTERNAMENTE</p>
                                <p class="mt-1">Motivo: <strong>{{ $dte->motivo_anulacion?->label() ?? '—' }}</strong>
                                    @if ($dte->fecha_anulacion) · {{ $dte->fecha_anulacion->format('d/m/Y H:i') }} @endif
                                    @if ($dte->anuladoPor) · por {{ $dte->anuladoPor->name }} @endif
                                </p>
                                @if ($dte->observacion_anulacion)<p class="mt-1">Observación: {{ $dte->observacion_anulacion }}</p>@endif
                                <p class="mt-1 text-xs">Anulación interna/preliminar. Pendiente del evento oficial de invalidación ante el MH.</p>
                            </div>
                        @endif

                        {{-- Anular (solo gestor y documento generado) --}}
                        @if ($puedeAnular)
                            <div class="bg-white shadow sm:rounded-lg p-4">
                                <h3 class="font-semibold text-gray-700 mb-2">Anular documento</h3>
                                <form method="POST" action="{{ route('facturacion.anular', $dte) }}"
                                      class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end"
                                      onsubmit="return confirm('¿Anular este documento? No podrá revertirse.');">
                                    @csrf
                                    <div>
                                        <x-input-label for="motivo_anulacion" value="Motivo de anulación *" />
                                        <select id="motivo_anulacion" name="motivo_anulacion" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                            @foreach (\App\Enums\MotivoAnulacion::opciones() as $valor => $label)
                                                <option value="{{ $valor }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="md:col-span-2">
                                        <x-input-label for="observacion_anulacion" value="Observación (opcional)" />
                                        <x-text-input id="observacion_anulacion" name="observacion_anulacion" type="text" class="mt-1 block w-full" />
                                    </div>
                                    <div class="md:col-span-3">
                                        <button class="px-4 py-2 bg-rose-600 text-white text-sm rounded-md hover:bg-rose-700">Anular documento</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                    </div>
                </details>
            @endif

            <div>
                <a href="{{ route('facturacion.index') }}" class="text-sm text-gray-500 hover:underline">← Volver al listado</a>
            </div>
        </div>
    </div>

    {{-- Al volver con "Atrás" desde el PDF (bfcache / history back), recargar la ficha para
         mostrar el estado real del correo (evita ver "No enviado" cacheado). Solo en esta vista.
         No toca backend, envío ni cola: solo refresca la página. --}}
    <script data-show-back-reload>
        window.addEventListener('pageshow', function (event) {
            var nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null;
            if (event.persisted || (nav && nav.type === 'back_forward')) {
                window.location.reload();
            }
        });
    </script>
</x-app-layout>
