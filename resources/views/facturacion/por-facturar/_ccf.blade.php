{{-- El CCF de un pedido o de un envío de la fábrica: «Borrador abierto» (al editor, para
     retomarlo) o «Facturado en CCF n.º …1234» (a la ficha), con los últimos números del
     número de control como en Rutas. Lo usan la tarjeta de «Llegó de la fábrica» y el detalle
     del pedido. Sin permiso para abrirlo, solo el texto.

     Variables: $dte (un CCF que sigue facturado: ni borrado, ni invalidado, ni rechazado). --}}
@php
    // Jefatura (13/11/2026): sin el número de control en el globo y, en vez de la ficha, el PDF
    // (solo de lo que Hacienda ya procesó: DtePolicy::view).
    $soloPdf = (bool) auth()->user()?->abreDocumentosComoPdf();
    $esBorrador = $dte->estado === \App\Enums\EstadoDte::Borrador;
    $textoCcf = $esBorrador
        ? 'Borrador abierto'
        : 'Facturado en CCF n.º '.($dte->numero_control ? '…'.\App\Support\OrdenCompra::ultimosDigitos($dte->numero_control) : $dte->etiquetaNumeroSistema());
    $urlCcf = match (true) {
        $esBorrador && (bool) auth()->user()?->can('update', $dte) => route('facturacion.edit', $dte),
        (bool) auth()->user()?->can('view', $dte) => route($soloPdf ? 'facturacion.pdf' : 'facturacion.show', $dte),
        default => null,
    };
@endphp
@if ($urlCcf)
    <a href="{{ $urlCcf }}" class="text-indigo-600 underline" @if ($dte->numero_control && ! $soloPdf) title="{{ $dte->numero_control }}" @endif @if ($soloPdf) target="_blank" @endif>{{ $textoCcf }}</a>
@else
    <span>{{ $textoCcf }}</span>
@endif
