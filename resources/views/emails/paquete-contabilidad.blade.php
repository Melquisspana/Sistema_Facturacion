<x-mail::message>
# Paquete de contabilidad {{ $etiqueta }}

En Google Drive está el paquete de documentos para contabilidad correspondiente al periodo
**{{ $resumen['desde'] }}** a **{{ $resumen['hasta'] }}**.

@if ($resumen['incluir_compras'])
- **Compras (recibidos):** {{ number_format($resumen['compras_cantidad']) }} documentos — total ${{ number_format($resumen['compras_total'], 2) }}
@endif
@if ($resumen['incluir_ventas'])
- **Ventas (emitidos):** {{ number_format($resumen['ventas_cantidad']) }} documentos — total ${{ number_format($resumen['ventas_total'], 2) }}
@endif

El detalle completo está en el ZIP de Drive (Excel de compras y ventas, y los PDF/JSON de cada documento).

<x-mail::button :url="$enlaceDrive">Abrir paquete en Drive</x-mail::button>

El acceso está autorizado solo para este correo de contabilidad. Abrí el enlace con esa cuenta de Google.

Gracias,
Dulces La Negrita
</x-mail::message>
