<x-mail::message>
# Paquete de contabilidad {{ $etiqueta }}

Adjunto el paquete de documentos para contabilidad correspondiente al periodo
**{{ $resumen['desde'] }}** a **{{ $resumen['hasta'] }}**.

@if ($resumen['incluir_compras'])
- **Compras (recibidos):** {{ number_format($resumen['compras_cantidad']) }} documentos — total ${{ number_format($resumen['compras_total'], 2) }}
@endif
@if ($resumen['incluir_ventas'])
- **Ventas (emitidos):** {{ number_format($resumen['ventas_cantidad']) }} documentos — total ${{ number_format($resumen['ventas_total'], 2) }}
@endif

El detalle completo va en el archivo ZIP adjunto (Excel de compras y ventas, y los
PDF/JSON de compras).

Gracias,
Dulces La Negrita
</x-mail::message>
