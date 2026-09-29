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

    // En NC no se auto-vincula albarán por OC (la comparte con el CCF): la nota usa el
    // SUYO, guardado al emitirla, y solo cae a captura manual si no lo tiene. Los DOS
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

    // ALBARÁN PROPIO DE LA NC. Ya está guardado en `dte_albaranes` desde que se capturó
    // para poder emitir la nota, con su número canónico, su fecha y su total. NO es el
    // albarán de ENTREGA del CCF (AC01) ni se deduce de la OC: es el AC02/AC04 que
    // originó esta nota. Se muestra para no volver a pedirlo; al agregar la NC al lote
    // el servidor lo relee de la base y no confía en lo que venga del formulario. El
    // criterio (sin datos / completo / parcial / inválido) es el mismo que aplica el
    // alta; usa la relación ya precargada por la búsqueda y no consulta por fila.
    $albaranPropioNc = $esNcLocal ? app(\App\Services\Ppq\AlbaranPropioNc::class)->evaluar($dte) : null;
    if ($albaranPropioNc !== null && $albaranPropioNc['estado'] === \App\Services\Ppq\AlbaranPropioNc::SIN_DATOS) {
        $albaranPropioNc = null; // sin datos: captura manual de siempre
    }

    $r = [
        'origen' => 'local',
        'esNc' => $esNcLocal,
        'fuente' => 'Sistema',
        // Por qué este documento NO se puede cobrar por PPQ (null si sí se puede). Se
        // muestra igual —esconderlo sería mentir sobre lo que existe—, pero sin botones
        // para agregarlo.
        //
        // Es exactamente la misma pregunta que hace el controlador al guardar, o la
        // pantalla ofrecería un botón que el backend rechaza.
        'motivoNoElegible' => \App\Support\PpqElegibilidad::motivo($dte),
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
        // Datos del albarán de la NC tal como quedaron guardados. Van en su propia clave
        // —y no en `albaranNumero`/`albaranMonto`— para no tocar el panel de conciliación
        // del CCF, que compara contra el albarán de entrega y responde otra pregunta.
        'albaranPropio' => $albaranPropioNc,
        // CCF relacionado EXPLÍCITO (`dte_relacionado_id`): el documento que esta nota
        // acredita, elegido al emitirla. No es una coincidencia de orden de compra —una
        // misma OC ampara varios CCF—, así que la pantalla no lo llama «sugerencia».
        'ccfRelacionado' => $esNcLocal ? $dte->dteRelacionado?->numero_control : null,
        'ccfRelacionadoFuente' => 'vinculo',
        'yaEn' => $yaUsados[$dte->id] ?? null,
    ];
@endphp

@include('ppq.partials.resultado', ['r' => $r])
