{{--
    Tabla de documentos (DTE) reutilizable por el listado principal de Facturación y por
    el listado de "documentos de prueba/simulación" del panel de Auditoría. Solo presentación.
    Espera: $dtes (paginador). Las acciones se muestran según la policy (@can), igual en ambos.
--}}
@php
    // Estado del último envío de correo (mismos colores del card "Correo del cliente").
    // Clases literales para que el JIT de Tailwind las incluya (no interpolar).
    $correoClases = [
        'enviado' => 'bg-green-100 text-green-700',
        'simulado' => 'bg-violet-100 text-violet-700',
        'pendiente' => 'bg-amber-100 text-amber-700',
        'error' => 'bg-rose-100 text-rose-700',
    ];
    $correoEtiquetas = [
        'enviado' => 'Enviado',
        'simulado' => 'Simulado',
        'pendiente' => 'Pendiente',
        'error' => 'Fallido',
    ];
    // Jefatura (12/11/2026): solo lo que Hacienda ya procesó (lo filtra DteController::index)
    // y solo lo que le sirve: fecha, tipo, cliente y sala, total y el PDF. Nada técnico.
    $soloPdf = (bool) auth()->user()?->abreDocumentosComoPdf();
@endphp

@if ($soloPdf)
<div class="hidden md:block overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead>
            <tr class="text-left text-gray-500">
                <th class="px-3 py-2">Fecha</th>
                <th class="px-3 py-2">Tipo</th>
                <th class="px-3 py-2">Cliente / sala</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-right"><span class="sr-only">PDF</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($dtes as $dte)
                <tr>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $dte->fecha_emision?->format('d/m/Y') }}</td>
                    <td class="px-3 py-2">
                        {{ $dte->tipo_dte->label() }}
                        @if ($dte->estado === \App\Enums\EstadoDte::Invalidado)
                            <span class="ml-1 inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Anulado</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        @if ($dte->cliente)
                            <div class="font-medium text-gray-800">{{ $dte->cliente->nombre }}</div>
                            @if ($dte->clienteSucursal)
                                <div class="text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}</div>
                            @endif
                        @else
                            <span class="font-medium text-gray-800">Consumidor final</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right font-mono">${{ number_format($dte->total_pagar, 2) }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <a href="{{ route('facturacion.pdf', $dte) }}" target="_blank" class="text-indigo-600 hover:underline">Ver PDF</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">No hay documentos con esos filtros.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Jefatura en el celular: una tarjeta por documento con lo mismo que su tabla
     (fecha, tipo, cliente y sala, total) y «Ver PDF» como botón de 44 px. --}}
<ul class="md:hidden divide-y divide-gray-100">
    @forelse ($dtes as $dte)
        <li class="py-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900">
                        {{ $dte->tipo_dte->label() }}
                        @if ($dte->estado === \App\Enums\EstadoDte::Invalidado)
                            <span class="ml-1 inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Anulado</span>
                        @endif
                    </p>
                    <p class="break-words font-medium text-gray-800">{{ $dte->cliente?->nombre ?? 'Consumidor final' }}</p>
                    @if ($dte->cliente && $dte->clienteSucursal)
                        <p class="text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}</p>
                    @endif
                </div>
                <span class="shrink-0 font-mono font-semibold text-gray-900">${{ number_format($dte->total_pagar, 2) }}</span>
            </div>
            <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                <span class="text-gray-600">{{ $dte->fecha_emision?->format('d/m/Y') }}</span>
                <a href="{{ route('facturacion.pdf', $dte) }}" target="_blank"
                   class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-indigo-700 hover:bg-gray-50">Ver PDF</a>
            </div>
        </li>
    @empty
        <li class="py-6 text-center text-gray-400">No hay documentos con esos filtros.</li>
    @endforelse
</ul>
@else
<div class="hidden md:block overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead>
            <tr class="text-left text-gray-500">
                <th class="px-3 py-2">Tipo</th>
                <th class="px-3 py-2">N.º sistema</th>
                <th class="px-3 py-2">Número</th>
                <th class="px-3 py-2">Relacionado</th>
                <th class="px-3 py-2">Cliente / sala</th>
                <th class="px-3 py-2">Estado</th>
                <th class="px-3 py-2">Correo</th>
                <th class="px-3 py-2">Fecha</th>
                <th class="px-3 py-2">Orden compra</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-right">Acciones</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($dtes as $dte)
                @php $esNc = $dte->tipo_dte === \App\Enums\TipoDte::NotaCredito; @endphp
                <tr>
                    <td class="px-3 py-2">{{ $dte->tipo_dte->label() }}</td>
                    {{-- Numeración comercial visible del sistema (nunca el id de la fila) --}}
                    <td class="px-3 py-2 font-semibold whitespace-nowrap">
                        @if ($dte->tieneNumeroSistema())
                            {{ $dte->numero_sistema }}
                        @else
                            <span class="text-gray-400">{{ $dte->etiquetaNumeroSistema() }}</span>
                        @endif
                    </td>
                    {{-- Número: control oficial si existe; si no, el interno/generado --}}
                    <td class="px-3 py-2 font-mono text-xs">{{ $dte->numero_control ?? $dte->numero_interno ?? '—' }}</td>
                    {{-- Relacionado: solo NC con CCF original (nunca a sí misma) --}}
                    <td class="px-3 py-2 font-mono text-xs">
                        @if ($esNc && $dte->dte_relacionado_id && (int) $dte->dte_relacionado_id !== (int) $dte->id)
                            <a href="{{ route('facturacion.show', $dte->dteRelacionado) }}" class="text-indigo-600 hover:underline">
                                {{ $dte->dteRelacionado?->numero_control ?? $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}
                            </a>
                        @else
                            —
                        @endif
                    </td>
                    {{-- Cliente fiscal + sala/sucursal --}}
                    <td class="px-3 py-2">
                        @if ($dte->cliente)
                            <div class="font-medium text-gray-800">{{ $dte->cliente->nombre }}</div>
                            @if ($dte->clienteSucursal)
                                <div class="text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}</div>
                            @endif
                        @else
                            <span class="font-medium text-gray-800">Consumidor final</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <x-estado-dte-badge :estado="$dte->estado" />
                    </td>
                    {{-- Último envío de correo (subquery ultimo_envio_estado; solo lectura) --}}
                    <td class="px-3 py-2">
                        @if ($dte->ultimo_envio_estado)
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $correoClases[$dte->ultimo_envio_estado] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $correoEtiquetas[$dte->ultimo_envio_estado] ?? ucfirst($dte->ultimo_envio_estado) }}
                            </span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $dte->fecha_emision?->format('d/m/Y') }}</td>
                    <td class="px-3 py-2">{{ $dte->numero_orden_compra ?? '—' }}</td>
                    <td class="px-3 py-2 text-right font-mono">${{ number_format($dte->total_pagar, 2) }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <a href="{{ route('facturacion.show', $dte) }}" class="text-gray-600 hover:underline">Ver</a>
                        @can('update', $dte)
                            <a href="{{ route('facturacion.edit', $dte) }}" class="text-indigo-600 hover:underline ml-2">Editar</a>
                        @endcan
                        @can('delete', $dte)
                            <form method="POST" action="{{ route('facturacion.destroy', $dte) }}" class="inline"
                                  onsubmit="return confirm('¿Eliminar este borrador?');">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline ml-2">Eliminar</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="px-3 py-6 text-center text-gray-400">No hay documentos con esos filtros.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Celular (debajo de md): una tarjeta por documento con los mismos datos y acciones.
     Arriba el tipo con su número y el cliente; debajo fecha, estado y total; las
     acciones son botones de 44 px. Desde md se ve la tabla de arriba, igual que siempre. --}}
<ul class="md:hidden divide-y divide-gray-100">
    @forelse ($dtes as $dte)
        @php $esNc = $dte->tipo_dte === \App\Enums\TipoDte::NotaCredito; @endphp
        <li class="py-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900">
                        {{ $dte->tipo_dte->label() }}
                        @if ($dte->tieneNumeroSistema())
                            <span class="whitespace-nowrap">· {{ $dte->numero_sistema }}</span>
                        @endif
                    </p>
                    <p class="break-words font-medium text-gray-800">{{ $dte->cliente?->nombre ?? 'Consumidor final' }}</p>
                    @if ($dte->cliente && $dte->clienteSucursal)
                        <p class="text-xs text-gray-500">{{ $dte->clienteSucursal->nombre }}</p>
                    @endif
                </div>
                <x-estado-dte-badge :estado="$dte->estado" class="shrink-0" />
            </div>

            <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                <span class="text-gray-600">{{ $dte->fecha_emision?->format('d/m/Y') ?? 'Sin fecha' }}</span>
                <span class="font-mono font-semibold text-gray-900">${{ number_format($dte->total_pagar, 2) }}</span>
            </div>

            <dl class="mt-1 space-y-1 text-xs text-gray-600">
                @if ($dte->numero_control ?? $dte->numero_interno)
                    <div class="break-all"><dt class="inline text-gray-500">Número:</dt> <dd class="inline font-mono">{{ $dte->numero_control ?? $dte->numero_interno }}</dd></div>
                @endif
                @if ($esNc && $dte->dte_relacionado_id && (int) $dte->dte_relacionado_id !== (int) $dte->id)
                    <div class="break-all">
                        <dt class="inline text-gray-500">Relacionado:</dt>
                        <dd class="inline font-mono">
                            <a href="{{ route('facturacion.show', $dte->dteRelacionado) }}" class="text-indigo-600 hover:underline">
                                {{ $dte->dteRelacionado?->numero_control ?? $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}
                            </a>
                        </dd>
                    </div>
                @endif
                @if ($dte->numero_orden_compra)
                    <div><dt class="inline text-gray-500">Orden de compra:</dt> <dd class="inline">{{ $dte->numero_orden_compra }}</dd></div>
                @endif
                @if ($dte->ultimo_envio_estado)
                    <div>
                        <dt class="inline text-gray-500">Correo:</dt>
                        <dd class="inline">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $correoClases[$dte->ultimo_envio_estado] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $correoEtiquetas[$dte->ultimo_envio_estado] ?? ucfirst($dte->ultimo_envio_estado) }}
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>

            <div class="mt-3 grid grid-cols-3 gap-2">
                <a href="{{ route('facturacion.show', $dte) }}"
                   class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Ver</a>
                @can('update', $dte)
                    <a href="{{ route('facturacion.edit', $dte) }}"
                       class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-indigo-700 hover:bg-gray-50">Editar</a>
                @endcan
                @can('delete', $dte)
                    <form method="POST" action="{{ route('facturacion.destroy', $dte) }}"
                          onsubmit="return confirm('¿Eliminar este borrador?');">
                        @csrf @method('DELETE')
                        <button class="inline-flex min-h-11 w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-red-700 hover:bg-gray-50">Eliminar</button>
                    </form>
                @endcan
            </div>
        </li>
    @empty
        <li class="py-6 text-center text-gray-400">No hay documentos con esos filtros.</li>
    @endforelse
</ul>
@endif

<div class="mt-4">
    {{ $dtes->links() }}
</div>
