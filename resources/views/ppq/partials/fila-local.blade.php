{{--
    Un resultado LOCAL de PPQ, listo para `ppq.partials.resultado`.

    Existe para que el buscador exacto y la búsqueda avanzada dibujen el documento
    EXACTAMENTE igual. Antes este mapeo vivía dentro del bucle de resultados; al aparecer
    la ficha del resultado exacto habría que haberlo copiado, y dos copias de veinte
    campos —incluidos el motivo de bloqueo y el estado de conciliación— terminan diciendo
    cosas distintas. La regla de qué se puede cobrar tiene que ser una sola en pantalla.

    Parámetros: $dte, $albaranesPorDte, $albaranesPorOc, $yaUsados.
--}}
@php
    $esNcLocal = $dte->tipo_dte->value === '05';

    // En NC no se auto-vincula albarán (comparte OC con el CCF; es manual). Los DOS
    // índices guardan una RESOLUCIÓN, no un albarán suelto: una misma OC —y también un
    // mismo documento— puede tener el albarán de entrega y el de crédito de la NC, y solo
    // cuenta el de entrega cuando es único. El vínculo explícito manda sobre la OC.
    $resolucionAlb = $esNcLocal
        ? null
        : ($albaranesPorDte[$dte->id] ?? ($albaranesPorOc[$dte->numero_orden_compra] ?? null));
    $alb = $resolucionAlb?->albaran;
    $albGmail = $esNcLocal ? null : ($albaranesGmailPorDte[$dte->id] ?? null);
    $hayAlb = $alb !== null || $albGmail !== null;
    $albMonto = $alb?->monto_albaran ?? ($albGmail['monto'] ?? null);

    $r = [
        'origen' => 'local',
        'esNc' => $esNcLocal,
        'fuente' => 'Sistema',
        // Por qué este documento NO se puede cobrar por PPQ (null si sí se puede). Se
        // muestra igual —esconderlo sería mentir sobre lo que existe—, pero sin botones
        // para agregarlo.
        //
        // Es `motivoParaCobrar` y no `motivo`: la primera cubre además el CCF físico que
        // el cliente exige de vuelta. Tienen que ser exactamente la misma pregunta que
        // hace el controlador al guardar, o la pantalla ofrecería un botón que el backend
        // rechaza.
        'motivoNoElegible' => \App\Support\PpqElegibilidad::motivoParaCobrar($dte),
        // Aviso que NO impide cobrar (modo «advertir» del perfil).
        'advertenciaCobro' => \App\Support\PpqElegibilidad::advertenciaParaCobrar($dte),
        // La base manda; el resultado exacto solo cae a Gmail cuando el AC01 todavía
        // no fue sincronizado.
        'albaranFuente' => $alb !== null
            ? 'Albarán sincronizado'
            : ($albGmail !== null ? 'Consultado en Gmail' : null),
        'tipoDte' => $dte->tipo_dte->value,
        'numeroControl' => $dte->numero_control,
        'codigoGeneracion' => $dte->codigo_generacion,
        'sello' => $dte->sello_recepcion,
        'fecha' => optional($dte->fecha_emision)->format('Y-m-d'),
        'monto' => $dte->total_pagar,
        'ordenCompra' => $dte->numero_orden_compra,
        'sala' => \App\Support\OrdenCompra::salaDesde($dte->numero_orden_compra),
        'salaNombre' => $dte->clienteSucursal?->nombre, // nombre comercial vía la relación del CCF

        'albaranNumero' => \App\Support\Albaran::numeroLimpio($alb?->numero_albaran ?? ($albGmail['numero_albaran'] ?? null)),
        'albaranFecha' => optional($alb?->fecha_albaran)->format('Y-m-d') ?? ($albGmail['fecha'] ?? null),
        'albaranMonto' => $albMonto,
        'salaAlbaran' => \App\Support\Albaran::salaDesdeNumero($alb?->numero_albaran ?? ($albGmail['numero_albaran'] ?? null)),
        'diferencia' => $albMonto !== null ? round((float) $dte->total_pagar - (float) $albMonto, 2) : null,
        'estado' => \App\Support\PpqConciliacion::estado($dte->total_pagar, $albMonto, $hayAlb),
        'dteId' => $dte->id,
        'albaranId' => $alb?->id,
        'gmailMessageId' => $alb?->gmail_message_id ?? ($albGmail['gmail_message_id'] ?? null),
        'ccfRelacionado' => null,
        'yaEn' => $yaUsados[$dte->id] ?? null,
    ];
@endphp

@include('ppq.partials.resultado', ['r' => $r])
