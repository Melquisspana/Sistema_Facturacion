{{--
    Partial ÚNICO de Totales del DTE (presentación, 3 bloques: Ventas ·
    Descuentos e impuestos · Total final).

    SOLO LECTURA: muestra valores ya calculados por la CalculadoraDte. No
    recalcula impuestos; aquí solo hay labels/formato.

    Parámetros:
      - $dte (requerido)
      - $esAgenteRetencion (opcional): para precisar la nota de retención no
        aplicada. null = desconocido (la nota se muestra igual); false = no es
        agente (se omite la nota de umbral).
--}}
@php
    $esFactura = $dte->tipo_dte === \App\Enums\TipoDte::Factura;
    $esFex = $dte->tipo_dte === \App\Enums\TipoDte::FacturaExportacion;
    $esNc = $dte->tipo_dte === \App\Enums\TipoDte::NotaCredito;
    $esCcf = ! $esFactura && ! $esFex && ! $esNc;

    $pctDescuento = (float) ($dte->descuento_porcentaje_aplicado ?? 0);
    $pctDescuentoLabel = rtrim(rtrim(number_format($pctDescuento, 2), '0'), '.');
    $montoDescuento = (float) $dte->total_descuento;

    $aplicaRet = (bool) $dte->aplica_retencion_iva;
    $umbral = number_format((float) config('dte.retencion_iva_umbral', 100), 2, '.', '');
    $esAgente = $esAgenteRetencion ?? null; // null = desconocido
    $baseNetaGravada = max(0, (float) $dte->total_gravado - (float) $dte->descuento_gravado);

    $totalLabel = $esNc ? 'Total a acreditar' : 'Total a pagar';
@endphp

<div class="bg-white shadow sm:rounded-lg p-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="font-semibold text-gray-700">Totales</h3>
        @if ($dte->esAnulado())
            <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-rose-100 text-rose-700">
                Documento anulado / invalidado internamente
            </span>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">

        {{-- Bloque 1: Resumen de ventas --}}
        <div class="rounded-lg border border-gray-200 p-4">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Resumen de ventas</h4>
            <dl class="space-y-2">
                @if ($esFex)
                    <div class="flex justify-between"><dt class="text-gray-500">Venta exportación</dt><dd class="font-mono">${{ number_format($dte->total_exportacion, 2) }}</dd></div>
                @else
                    <div class="flex justify-between"><dt class="text-gray-500">Venta gravada</dt><dd class="font-mono">${{ number_format($dte->total_gravado, 2) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-gray-500">Venta exenta</dt><dd class="font-mono">${{ number_format($dte->total_exento, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">No sujeto</dt><dd class="font-mono">${{ number_format($dte->total_no_sujeto, 2) }}</dd></div>
                <div class="flex justify-between border-t border-gray-100 pt-2 font-medium text-gray-700">
                    <dt>Subtotal bruto</dt><dd class="font-mono">${{ number_format($dte->subtotal, 2) }}</dd>
                </div>
            </dl>
        </div>

        {{-- Bloque 2: Descuentos e impuestos --}}
        <div class="rounded-lg border border-gray-200 p-4">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Descuentos e impuestos</h4>
            <dl class="space-y-2">
                {{-- Descuento (ámbar, con signo negativo) --}}
                <div class="flex justify-between text-amber-700">
                    <dt>Descuento aplicado @if ($pctDescuento > 0)<span class="text-amber-600">({{ $pctDescuentoLabel }}%)</span>@endif</dt>
                    <dd class="font-mono">-${{ number_format($montoDescuento, 2) }}</dd>
                </div>

                @if ($esFex)
                    <div class="flex justify-between"><dt class="text-gray-500">IVA</dt><dd class="font-mono">0% — $0.00</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Flete</dt><dd class="font-mono">${{ number_format($dte->flete, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Seguro</dt><dd class="font-mono">${{ number_format($dte->seguro, 2) }}</dd></div>
                    <p class="text-xs text-gray-400 pt-1">Exportación con IVA 0%.</p>
                @else
                    <div class="flex justify-between border-t border-gray-100 pt-2">
                        <dt class="text-gray-500">Base gravada neta</dt><dd class="font-mono">${{ number_format($baseNetaGravada, 2) }}</dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-gray-500">IVA 13%</dt><dd class="font-mono">${{ number_format($dte->iva, 2) }}</dd></div>

                    @if ($esFactura)
                        <p class="text-xs text-gray-400 pt-1">Factura consumidor final: el precio ya incluye IVA (base e IVA mostrados son informativos; no se suman dos veces).</p>
                    @elseif ($esCcf)
                        {{-- Retención IVA: gris si no aplica, ámbar si aplica (solo CCF) --}}
                        @if ($aplicaRet)
                            <div class="flex justify-between text-amber-700">
                                <dt>Retención IVA 1%</dt><dd class="font-mono">-${{ number_format($dte->iva_retenido, 2) }}</dd>
                            </div>
                        @else
                            <div class="flex justify-between text-gray-400">
                                <dt>Retención IVA 1%</dt><dd class="font-mono">$0.00</dd>
                            </div>
                            @if ($baseNetaGravada > 0 && $esAgente !== false)
                                <p class="text-xs text-gray-400">No aplica: la base gravada neta no supera ${{ $umbral }}.</p>
                            @endif
                        @endif
                    @endif
                @endif
            </dl>
        </div>

        {{-- Bloque 3: Total final (destacado) --}}
        <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-4 flex flex-col justify-between">
            <dl class="space-y-2">
                @if ($esNc)
                    @if ($dte->tipo_nota_credito)
                        <div class="flex justify-between text-gray-600"><dt>Tipo de nota</dt><dd>{{ $dte->tipo_nota_credito->label() }}</dd></div>
                    @endif
                    @if ($dte->dte_relacionado_id && (int) $dte->dte_relacionado_id !== (int) $dte->id)
                        <div class="flex justify-between text-gray-600">
                            <dt>Documento relacionado</dt>
                            <dd class="font-mono">{{ $dte->dteRelacionado?->numero_control ?? $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}</dd>
                        </div>
                    @else
                        <div class="flex justify-between text-gray-400"><dt>Documento relacionado</dt><dd>Sin documento relacionado interno</dd></div>
                    @endif
                    @if ($dte->numero_orden_compra)
                        <div class="flex justify-between text-gray-600"><dt>Orden de compra</dt><dd class="font-mono">{{ $dte->numero_orden_compra }}</dd></div>
                    @endif
                    @if ($dte->motivo)
                        <div class="text-gray-600"><dt class="text-xs uppercase tracking-wide text-gray-400">Motivo</dt><dd>{{ $dte->motivo }}</dd></div>
                    @endif
                @elseif ($esCcf)
                    <div class="flex justify-between text-gray-600">
                        <dt>Total antes de retención</dt><dd class="font-mono">${{ number_format($dte->total_antes_retencion, 2) }}</dd>
                    </div>
                @endif
            </dl>
            <div class="mt-3 border-t border-indigo-200 pt-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-indigo-500">{{ $totalLabel }}</div>
                <div class="mt-1 text-3xl font-bold font-mono text-indigo-900">${{ number_format($dte->total_pagar, 2) }}</div>
            </div>
        </div>

    </div>
</div>
