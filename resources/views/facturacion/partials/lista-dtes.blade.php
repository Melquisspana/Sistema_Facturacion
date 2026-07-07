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
@endphp

<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead>
            <tr class="text-left text-gray-500">
                <th class="px-3 py-2">Tipo</th>
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
                <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">No hay documentos con esos filtros.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $dtes->links() }}
</div>
