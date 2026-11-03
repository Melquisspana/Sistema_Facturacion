{{--
    Tarjeta «Datos del documento» de la ficha del DTE: cliente, sala, estado en Hacienda,
    numeración, fecha y orden de compra. SOLO LECTURA.

    La usan la ficha de Facturación y la de Cobros, para que un CCF se lea igual en las
    dos. Los enlaces a Facturación solo salen a quien puede ver el DTE (DtePolicy::view):
    en Cobros entra quien tiene `ppq.ver`, que no siempre tiene `dte.ver`.

    Parámetros: $dte (con cliente, clienteSucursal y dteRelacionado cargados).
--}}
            <div class="bg-white shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <h3 class="font-semibold text-gray-700">Datos del documento</h3>
                    {{-- Este encabezado ya NO es una barra de acciones: es la cabecera de los
                         DATOS. Queda ÚNICAMENTE «Imprimir», el gesto inmediato sobre el
                         documento que se está mirando.

                         Se retiraron de acá, y ahora viven en «Acciones del documento» más
                         abajo: Ver PDF, Descargar PDF, Duplicar y Editar. Los tres primeros
                         estaban duplicados en las dos secciones, con etiquetas distintas para
                         la misma ruta («Ver PDF oficial» arriba, «Ver PDF» abajo), lo que
                         hacía dudar si eran o no la misma cosa; Editar es una acción sobre el
                         documento, no un dato suyo.

                         También se retiró la insignia «Enviado por correo»: la tarjeta de
                         ESTADO, tres líneas más arriba, ya dice «Correo enviado / Correo
                         falló / Pendiente de correo» sobre el mismo dato. --}}
                    @can('view', $dte)
                        <div class="flex items-center gap-3 flex-wrap">
                            <a href="{{ route('facturacion.imprimir', $dte) }}" target="_blank" class="text-gray-600 hover:underline text-sm">Imprimir</a>
                        </div>
                    @endcan
                </div>
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-gray-500">Cliente</dt><dd>{{ $dte->cliente?->nombre ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Sala / sucursal</dt><dd>{{ $dte->clienteSucursal?->nombre ?? '—' }}</dd></div>
                    @if ($dte->tipo_nota_credito)
                        <div><dt class="text-gray-500">Tipo de NC</dt><dd>{{ $dte->tipo_nota_credito->label() }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">Estado</dt><dd><x-estado-dte-badge :estado="$dte->estado" /></dd></div>
                    {{-- Numeración COMERCIAL visible del sistema (compartida por todos los
                         tipos). El id de la fila NO es un número comercial: se muestra
                         aparte y etiquetado como técnico, solo para soporte/auditoría. --}}
                    <div>
                        <dt class="text-gray-500">N.º sistema</dt>
                        <dd class="font-semibold">{{ $dte->etiquetaNumeroSistema() }}</dd>
                    </div>
                    <div><dt class="text-gray-500">Número interno</dt><dd class="font-mono">{{ $dte->numero_interno ?? '—' }}</dd></div>
                    @if ($dte->numero_control)
                        <div><dt class="text-gray-500">Número de control</dt><dd class="font-mono break-all">{{ $dte->numero_control }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">Fecha</dt><dd>{{ $dte->fecha_emision?->format('d/m/Y') }}</dd></div>
                    <div><dt class="text-gray-500">Orden de compra</dt><dd>{{ $dte->numero_orden_compra ?? '—' }}</dd></div>
                    {{-- Identificador TÉCNICO de la fila: solo para soporte y auditoría,
                         nunca como número de documento frente al cliente. --}}
                    <div><dt class="text-gray-500">ID técnico (soporte)</dt><dd class="font-mono text-xs text-gray-400">{{ $dte->id }}</dd></div>
                    @if ($dte->dte_relacionado_id)
                        <div>
                            <dt class="text-gray-500">Documento original</dt>
                            @can('view', $dte)
                                <dd><a href="{{ route('facturacion.show', $dte->dteRelacionado) }}" class="text-indigo-600 hover:underline font-mono">
                                    {{ $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}
                                </a></dd>
                            @else
                                <dd class="font-mono">{{ $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}</dd>
                            @endcan
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
