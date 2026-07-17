@php
    use App\Enums\TipoDte;
    use App\Enums\EstadoDte;

    // Solo presentación: estas variables pueden no venir si se renderiza la vista directo.
    $logoSrc = $logoSrc ?? null;
    $qrDataUri = $qrDataUri ?? null;
    $datosExportacion = $datosExportacion ?? null;
    $datosReceptor = $datosReceptor ?? null;

    // Datos del emisor: empresa real → respaldo config/company → '—'. No inventa.
    $cfgCo = config('company');
    $emRazon     = $emisor?->razon_social     ?: ($cfgCo['nombre'] ?? null);
    $emComercial = $emisor?->nombre_comercial ?: ($cfgCo['nombre_comercial'] ?? null);
    $emNit       = $emisor?->nit              ?: ($cfgCo['nit'] ?? null);
    $emNrc       = $emisor?->nrc              ?: ($cfgCo['nrc'] ?? null);
    $emDir       = $emisor?->direccion        ?: ($cfgCo['direccion']['complemento'] ?? null);
    $emTel       = $emisor?->telefono         ?: ($cfgCo['contacto']['telefono'] ?? null);
    $emCorreo    = $emisor?->correo           ?: ($cfgCo['contacto']['correo'] ?? null);
    // Actividad económica del emisor (dato real; respaldo a config; si no existe, no se muestra).
    $emActividad = $emisor?->actividadEconomica?->nombre ?: ($cfgCo['actividad_economica']['descripcion'] ?? null);

    // Encabezado: la MARCA (nombre comercial) va grande; el nombre LEGAL (razón social)
    // como sub-línea. El dato legal correcto para el JSON sigue en razon_social.
    $emMarca = $emComercial ?: $emRazon;
    $emLegal = ($emComercial && $emRazon && trim($emComercial) !== trim((string) $emRazon)) ? $emRazon : null;

    $esFex = $dte->tipo_dte === TipoDte::FacturaExportacion;
    $esNc = $dte->tipo_dte === TipoDte::NotaCredito;
    $esFactura = $dte->tipo_dte === TipoDte::Factura;
    $baseLabel = $esFex ? 'Exportación' : 'Gravado';

    $hayExento   = (float) $dte->total_exento > 0;
    $hayNoSujeto = (float) $dte->total_no_sujeto > 0;

    $ubic = function ($m) {
        $partes = array_filter([$m?->municipio?->nombre, $m?->departamento?->nombre]);
        return $partes ? implode(', ', $partes) : null;
    };
    $cli = $dte->cliente;
    $suc = $dte->clienteSucursal;
    $ubicSala = function ($s) use ($ubic) {
        if ($s?->distrito) {
            $partes = array_filter([$s->distrito->nombre, $s->distrito->municipio, $s->distrito->departamento?->nombre]);
            return $partes ? implode(', ', $partes) : null;
        }
        return $ubic($s);
    };
    // Ubicación del emisor en 3 niveles y ORDEN Departamento, Municipio, Distrito
    // (mismo criterio que el receptor). Si algún nivel no existe, se omite.
    $emUbic = implode(', ', array_filter([$emisor?->departamento?->nombre, $emisor?->municipio?->nombre, $emisor?->distrito?->nombre])) ?: null;

    // Ubicación administrativa de la sala, por campos (división 2024 con respaldo al esquema previo).
    $salaDepto = $suc?->distrito?->departamento?->nombre ?? $suc?->departamento?->nombre;
    $salaMuni  = $suc?->distrito?->municipio ?? $suc?->municipio?->nombre;
    $salaDist  = $suc?->distrito?->nombre;
    $salaUbic = implode('  |  ', array_filter([
        $salaDepto ? 'Departamento: '.$salaDepto : null,
        $salaMuni  ? 'Municipio: '.$salaMuni : null,
        $salaDist  ? 'Distrito: '.$salaDist : null,
    ]));

    // Ubicación del RECEPTOR en 3 niveles y ORDEN Departamento, Municipio, Distrito.
    // Para un cliente con sala (p. ej. Calleja) es la ubicación de la SALA (la que va al
    // JSON fiscal); sin sala, la del propio cliente. Solo presentación; no cambia datos.
    $recUbic = $suc
        ? (implode(', ', array_filter([$salaDepto, $salaMuni, $salaDist])) ?: null)
        : (implode(', ', array_filter([$cli?->departamento?->nombre, $cli?->municipio?->nombre, $cli?->distrito?->nombre])) ?: null);

    // Valor en letras: mismo helper que el JSON (NumeroALetras), con respaldo al campo persistido.
    $valorLetras = $dte->total_letras ?: \App\Support\Dte\NumeroALetras::convertir($dte->total_pagar ?? 0);

    // Bloque inferior de totales (TODO de campos ya calculados/persistidos; sin recálculo):
    //  - Sumas de ventas = total_gravado/exento/no_sujeto (netas de descuento por línea) → su suma = subtotal.
    //  - Descuento global = total_descuento (el global prorrateado realmente aplicado).
    //  - Descuento ítem y global = Σ descuentos por línea + descuento global (informativo).
    //  - Sub-total = subtotal − descuento global (base imponible).
    //  - IVA = iva ; Monto total operación = monto_total_operacion ; Total a pagar = total_pagar.
    $descItems = (float) $dte->lineas->sum(fn ($l) => (float) $l->descuento_monto);
    $descGlobal = (float) $dte->total_descuento;
    $descItemYGlobal = $descItems + $descGlobal;
    $subTotalNeto = (float) $dte->subtotal - $descGlobal;

    $requiereOc = $dte->requiereOrdenCompra();
    $etiquetaOc = $cli?->etiqueta_orden_compra ?: 'Orden de compra';
    $tieneOc = filled($dte->numero_orden_compra);
    $hayApendice = $tieneOc || $requiereOc || ($esNc && $dte->dte_relacionado_id);

    $esCcf = $dte->tipo_dte === TipoDte::CreditoFiscal;
    // Nombre comercial: solo si difiere de la razón social (evita duplicados tipo "Calleja / Calleja").
    $cliComercial = ($cli?->nombre_comercial && trim($cli->nombre_comercial) !== trim((string) $cli->nombre))
        ? $cli->nombre_comercial : null;

    $esBorrador = $dte->estado === EstadoDte::Borrador;
    $tieneSello = filled($dte->sello_recepcion);
    $firmadoLocal = filled($dte->json_firmado_path);
    $jsonGenerado = filled($dte->json_generado_path);

    $estadoHacienda = $tieneSello
        ? 'aceptado'
        : ($dte->estado === EstadoDte::Rechazado ? 'rechazado' : 'no transmitido');
    $preliminar = ! $tieneSello;

    $colspan = 10 + ($hayNoSujeto ? 1 : 0) + ($hayExento ? 1 : 0);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $preliminar ? 'Representación gráfica PRELIMINAR' : 'DTE' }} — {{ $dte->tipo_dte->label() }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 32px 34px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #20242C; font-size: 9.3px; margin: 0; line-height: 1.3; }
        table { width: 100%; border-collapse: collapse; }
        .mono { font-family: "DejaVu Sans Mono", monospace; }
        .muted { color: #6B7280; }
        .tiny { font-size: 7.5px; }
        .accent { color: #6E2142; }
        .pend { color: #9AA0A8; font-style: italic; }

        /* Cinta de estado (una línea, mínima altura) */
        .st { padding: 2.5px 9px; font-size: 8px; border: 1px solid #D7C7CE; border-left-width: 3px; margin-bottom: 6px; border-radius: 3px; }
        .st b { letter-spacing: .2px; }
        .st-warn { background: #FBF6EC; color: #7A4A0C; border-color: #E7C98D; border-left-color: #C8881E; }
        .st-info { background: #F3F4F6; color: #3A4150; border-color: #D4D8DE; border-left-color: #6B7280; }
        .st-live { background: #EEF5F0; color: #235C42; border-color: #BFE0CD; border-left-color: #2F6B4E; }
        .st-void { background: #FBEDF0; color: #8A2440; border-color: #E3B7C2; border-left-color: #B0334F; }
        /* Aviso PRELIMINAR: discreto, una sola línea pequeña, sin barra grande. */
        .st-prelim { font-size: 7px; letter-spacing: .4px; color: #9AA0A8; text-transform: uppercase; margin-bottom: 4px; }
        /* Ambiente de pruebas (ambiente=00): SIEMPRE visible, sin importar sello/estado. */
        .st-testing { background: #FEF3C7; color: #7A4A0C; border: 1.5px solid #D97706; border-radius: 3px;
            padding: 4px 9px; margin-bottom: 6px; font-size: 8.5px; font-weight: bold; text-align: center;
            letter-spacing: .3px; text-transform: uppercase; }
        .st-testing .sub { display: block; font-size: 7px; font-weight: normal; text-transform: none; letter-spacing: 0; margin-top: 1px; }

        /* Encabezado */
        .topline { border-top: 2px solid #6E2142; margin-bottom: 11px; }
        .head td { vertical-align: top; padding: 0; }
        /* Logo transparente (retrato 2:3): más grande y sin marco. */
        .logo { width: 64px; height: 96px; }
        .logo-fb { width: 60px; height: 60px; border-radius: 8px; background: #20242C; color: #fff; text-align: center; font-size: 26px; font-weight: bold; padding-top: 16px; }
        .razon { font-size: 16.5px; font-weight: bold; color: #20242C; line-height: 1.12; }
        .comercial { color: #6E2142; font-weight: bold; font-size: 10.5px; }
        .emi { font-size: 8.8px; color: #4A4F58; margin-top: 3px; line-height: 1.34; }
        .emi .k { color: #9AA0A8; }

        /* Caja de datos del DTE (derecha) — mismo lenguaje que las demás secciones */
        .dbox { border: 1px solid #C9CDD4; border-radius: 5px; overflow: hidden; }
        .dbox-h { background: #F2F3F5; border-bottom: 1px solid #D4D8DE; padding: 4px 8px; }
        .dbox-h .lbl { font-size: 6.5px; letter-spacing: 1.2px; text-transform: uppercase; color: #9AA0A8; display: block; }
        .dbox-h .ttl { font-size: 11.5px; font-weight: bold; color: #6E2142; line-height: 1.12; }
        .dbox-t td { padding: 3px 8px; font-size: 8.6px; border-bottom: 1px solid #EEF0F2; }
        .dbox-t tr:last-child td { border-bottom: 0; }
        .dbox-t .k { color: #6B7280; width: 60px; }
        .dbox-t .v { text-align: right; font-weight: bold; color: #20242C; }
        .dbox-t tr.key td { background: #FAFBFC; }
        .dbox-t tr.key .v { font-size: 9.2px; }
        .dbox-t .v.live { color: #235C42; }
        /* Sello de recepción: 40 chars sin guiones; se muestra COMPLETO y se parte en
           varias líneas (word-break) para no cortarse ni salirse de la caja. */
        .dbox-t .v.sello { word-break: break-all; }
        .qrcell { text-align: center; padding-left: 8px; }
        .qrcell img { width: 74px; height: 74px; }
        /* Caja QR con el mismo lenguaje que la caja del DTE (cabecera gris + cuerpo). */
        .qrbox { border: 1px solid #C9CDD4; border-radius: 5px; overflow: hidden; }
        .qrbox .qh { background: #F2F3F5; border-bottom: 1px solid #D4D8DE; padding: 4px 6px; font-size: 6.5px; letter-spacing: 1px; text-transform: uppercase; color: #9AA0A8; font-weight: bold; }
        .qrbox .qb { padding: 12px 6px; color: #6B7280; font-size: 7.5px; line-height: 1.35; }

        /* Secciones (un solo lenguaje: borde fino + cabecera gris + cuerpo) */
        .sec { border: 1px solid #C9CDD4; border-radius: 5px; margin-bottom: 10px; }
        .sec.rec { margin-top: 13px; }
        .sec-h { background: #F2F3F5; border-bottom: 1px solid #D4D8DE; padding: 4px 9px; font-size: 7px; letter-spacing: 1px; text-transform: uppercase; color: #6B7280; font-weight: bold; }
        .sec-b { padding: 10px 10px; }
        .sec-b td { vertical-align: top; font-size: 9px; padding: 2px 0; }
        .rec-name { font-size: 12.5px; font-weight: bold; color: #20242C; }
        .rec-com { color: #6E2142; font-weight: bold; font-size: 9px; }
        .f { font-size: 9px; }
        .f .k { color: #9AA0A8; }
        .sala { margin-top: 6px; padding-top: 6px; border-top: 1px dotted #D4D8DE; font-size: 9.3px; }
        .sala .k { font-size: 6.5px; letter-spacing: .8px; text-transform: uppercase; color: #6B7280; }
        .sala .nm { font-weight: bold; color: #20242C; font-size: 10px; }

        /* Franja inferior de la sección (condición / apéndice en línea) */
        .strip { border-top: 1px solid #D4D8DE; background: #F8F9FA; padding: 3px 8px; font-size: 8px; color: #4A4F58; }
        .strip .k { color: #9AA0A8; }
        .strip .sep { color: #C9CDD4; }
        .strip .oc { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; color: #6E2142; }
        .strip .miss { font-style: italic; font-weight: bold; color: #9A5B0E; }

        /* Tabla de productos */
        .items thead th { background: #20242C; color: #F3F4F6; font-size: 7.3px; text-transform: uppercase; letter-spacing: .3px; padding: 4px 6px; text-align: left; }
        .items thead th.r { text-align: right; }
        .items tbody td { padding: 3.4px 6px; border-bottom: 1px solid #EAECEF; font-size: 9px; vertical-align: top; line-height: 1.22; }
        .items tbody tr:nth-child(even) td { background: #F8F9FA; }
        .items .r { text-align: right; }
        .items .ci { color: #9AA0A8; width: 16px; }
        .items .cod { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; font-size: 8.6px; color: #20242C; }
        .items .cod2 { display: block; font-family: "DejaVu Sans Mono", monospace; font-size: 7.6px; color: #8A9099; }
        .items .pn { font-weight: bold; color: #20242C; }
        .items .num { font-family: "DejaVu Sans Mono", monospace; }
        .dash { color: #C9CDD4; }
        /* Regla de máximo 10 líneas por página: cada bloque de 10 va en su página.
           El 2º bloque en adelante empieza con salto de página; no se parte internamente. */
        .items-cont { page-break-before: always; }
        .items-blk { page-break-inside: avoid; }
        .cont-hdr { margin: 0 0 6px; padding: 3px 8px; background: #F2F3F5; border: 1px solid #D4D8DE; border-radius: 4px; font-size: 7px; letter-spacing: .6px; text-transform: uppercase; color: #6B7280; }

        /* Totales */
        .botwrap { margin-top: 9px; }
        .botwrap td { vertical-align: top; }
        .letras { border: 1px solid #C9CDD4; border-radius: 5px; padding: 5px 9px; font-size: 8.6px; }
        .letras .k { font-size: 6.5px; letter-spacing: .8px; text-transform: uppercase; color: #9AA0A8; }
        .cond-line { font-size: 8.6px; margin-top: 4px; }
        .cond-line .k { color: #8A9099; }
        .firmas2 { margin-top: 9px; }
        .firmas2 td { vertical-align: top; }
        .firmas2 .gap2 { width: 14px; }
        .fl { margin-top: 7px; }
        .fl .flk { font-size: 7px; text-transform: uppercase; letter-spacing: .4px; color: #8A9099; }
        .fl .fline { display: block; border-bottom: 1px solid #B6BBC2; height: 10px; }
        .tot td { padding: 3px 10px; font-size: 9.3px; }
        .tot tr.sub td { font-weight: bold; color: #20242C; }
        .tot .k { color: #4A4F58; }
        .tot .v { text-align: right; font-family: "DejaVu Sans Mono", monospace; font-weight: bold; color: #20242C; }
        .tot .minus .v { color: #9A5B0E; }
        .tot .zero td { color: #9AA0A8; }
        .tot .rule td { border-top: 1px solid #D4D8DE; }
        .totbox { border: 1px solid #C9CDD4; border-radius: 5px; overflow: hidden; }
        .grand td { background: #6E2142; color: #fff; padding: 6px 10px; }
        .grand .gl { font-size: 7.5px; letter-spacing: 1px; text-transform: uppercase; color: #E7C6D2; }
        .grand .gv { text-align: right; font-family: "DejaVu Sans Mono", monospace; font-weight: bold; font-size: 14.5px; }

        /* Estado técnico (una línea) */
        .tech { border: 1px solid #E5E8EC; border-radius: 4px; padding: 4px 8px; background: #F8F9FA; color: #6B7280; font-size: 7px; margin-top: 8px; }
        .tech .th { text-transform: uppercase; letter-spacing: .6px; color: #9AA0A8; font-weight: bold; }
        .tech strong { color: #3A4150; }

        .pie { margin-top: 5px; font-size: 7px; color: #9AA0A8; text-align: center; }
        .nobreak { page-break-inside: avoid; }
    </style>
</head>
<body>

    {{-- AMBIENTE DE PRUEBAS: única fuente de verdad es $dte->ambiente, NUNCA el sello.
         Debe verse aunque el documento esté aceptado/firmado/generado/rechazado. --}}
    @if ($dte->ambiente?->value === '00')
        <div class="st-testing">AMBIENTE DE PRUEBAS<span class="sub">Documento sin validez fiscal en producción</span></div>
    @endif

    {{-- CINTA DE ESTADO --}}
    @if ($dte->estado === EstadoDte::Invalidado)
        <div class="st st-void"><b>DOCUMENTO ANULADO / INVALIDADO INTERNAMENTE</b>@if ($dte->motivo_anulacion) · {{ $dte->motivo_anulacion->label() }}@endif</div>
    @elseif ($tieneSello)
        {{-- Aceptado por Hacienda: PDF LIMPIO para entregar/imprimir (el sello y el QR ya lo acreditan). Sin cinta de estado. --}}
    @elseif ($dte->estado === EstadoDte::Rechazado)
        @php $motivoRechazo = data_get($dte->respuesta_mh, 'descripcionMsg'); @endphp
        <div class="st st-void"><b>RECHAZADO POR HACIENDA</b>@if ($motivoRechazo) · {{ \Illuminate\Support\Str::limit($motivoRechazo, 90) }}@endif</div>
    @else
        {{-- Borrador / Generado / Firmado, aún NO aceptado: aviso pequeño y discreto (una línea). --}}
        <div class="st-prelim">Preliminar · no válido fiscalmente</div>
    @endif

    <div class="topline"></div>

    {{-- ENCABEZADO: emisor · datos del DTE · QR (compacto) --}}
    <table class="head nobreak">
        <tr>
            <td style="width: 50%; padding-right: 12px;">
                <table><tr>
                    <td style="width: 70px;">
                        @if ($logoSrc)<img class="logo" src="{{ $logoSrc }}" alt="logo">@else<div class="logo-fb">{{ mb_substr($emMarca ?? 'D', 0, 1) }}</div>@endif
                    </td>
                    <td>
                        <div class="razon">{{ $emMarca ?? '—' }}</div>
                        @if ($emLegal)<div class="comercial">{{ $emLegal }}</div>@endif
                        <div class="emi"><span class="k">NIT</span> {{ $emNit ?? '—' }} · <span class="k">NRC</span> {{ $emNrc ?? '—' }}</div>
                        @if ($emActividad)<div class="emi"><span class="k">Actividad</span> {{ $emActividad }}</div>@endif
                        @if ($emUbic || $emDir)<div class="emi">@if($emUbic){{ $emUbic }}@endif @if($emDir)· {{ $emDir }}@endif</div>@endif
                        <div class="emi">Estab.: {{ $dte->establecimiento?->nombre ?? '—' }}@if($dte->establecimiento?->codigo) ({{ $dte->establecimiento->codigo }})@endif · PV: {{ $dte->puntoVenta?->nombre ?? '—' }}@if($dte->puntoVenta?->codigo) ({{ $dte->puntoVenta->codigo }})@endif</div>
                        @if ($emTel || $emCorreo)<div class="emi">@if($emTel){{ $emTel }}@endif@if($emCorreo) · {{ $emCorreo }}@endif</div>@endif
                    </td>
                </tr></table>
            </td>
            <td style="width: 34%;">
                <div class="dbox">
                    <div class="dbox-h">
                        <span class="lbl">Documento Tributario Electrónico</span>
                        <span class="ttl">{{ $dte->tipo_dte->label() }} · {{ $dte->tipo_dte->value }}</span>
                    </div>
                    <table class="dbox-t">
                        <tr><td class="k">N° control</td><td class="v mono">@if($dte->numero_control){{ $dte->numero_control }}@else<span class="pend">pendiente</span>@endif</td></tr>
                        <tr><td class="k">Cód. gen.</td><td class="v mono">@if($dte->codigo_generacion){{ $dte->codigo_generacion }}@else<span class="pend">pendiente</span>@endif</td></tr>
                        <tr class="key"><td class="k">N° interno</td><td class="v mono">{{ $dte->numero_interno ?? '—' }}</td></tr>
                        <tr class="key"><td class="k">Fecha</td><td class="v">{{ $dte->fecha_emision?->format('d/m/Y') }}@if($dte->hora_emision) {{ $dte->hora_emision }}@endif</td></tr>
                        @if ($tieneSello)
                            <tr class="key"><td class="k">Sello rec.</td><td class="v live mono sello">{{ $dte->sello_recepcion }}</td></tr>
                        @else
                            <tr class="key"><td class="k">Estado</td><td class="v">{{ $dte->estado->label() }}</td></tr>
                        @endif
                    </table>
                </div>
            </td>
            <td class="qrcell" style="width: 16%; padding-left: 10px;">
                <div class="qrbox">
                    <div class="qh">Verificación</div>
                    <div class="qb">
                        @if ($qrDataUri)
                            <img src="{{ $qrDataUri }}" alt="QR oficial"><br>Consulta MH
                        @else
                            QR oficial pendiente<br><span class="tiny">sin sello no se genera QR oficial</span>
                        @endif
                    </div>
                </div>
            </td>
        </tr>
    </table>

    {{-- RECEPTOR (+ condición integrada en franja inferior) --}}
    <div class="sec rec nobreak">
        <div class="sec-h">Receptor</div>
        <div class="sec-b">
            <span class="rec-name">{{ $cli?->nombre ?? 'Consumidor final' }}</span>@if ($cliComercial) <span class="rec-com">· {{ $cliComercial }}</span>@endif@if (! $cli) <span class="tiny muted">— Consumidor final sin identificar.</span>@endif
            <table style="margin-top:3px;">
                <tr>
                    <td style="width: 50%; padding-right: 10px;">
                        @if ($esFex && ($datosReceptor['destino'] ?? null))<div class="f"><span class="k">Destino:</span> {{ $datosReceptor['destino'] }}</div>@endif
                        @if ($cli?->num_documento)<div class="f"><span class="k">Documento:</span> <span class="mono">{{ $cli->num_documento }}</span>@if($cli?->nrc) · <span class="k">NRC:</span> <span class="mono">{{ $cli->nrc }}</span>@endif</div>@elseif($cli?->nrc)<div class="f"><span class="k">NRC:</span> <span class="mono">{{ $cli->nrc }}</span></div>@endif
                        @if ($cli?->actividadEconomica?->nombre)<div class="f"><span class="k">Actividad:</span> {{ $cli->actividadEconomica->nombre }}</div>@endif
                    </td>
                    <td style="width: 50%;">
                        @if ($esFex && ($datosReceptor['correo'] ?? null))<div class="f"><span class="k">Correo:</span> {{ $datosReceptor['correo'] }}</div>@endif
                        @if ($recUbic)<div class="f"><span class="k">Ubicación:</span> {{ $recUbic }}</div>@endif
                        @if ($cli?->direccion)<div class="f"><span class="k">Dirección:</span> {{ $cli->direccion }}@if($cli->complemento_direccion) — {{ $cli->complemento_direccion }}@endif</div>@endif
                        @if ($esFex && ($datosReceptor['telefono'] ?? null))<div class="f"><span class="k">Teléfono:</span> {{ $datosReceptor['telefono'] }}</div>@endif
                    </td>
                </tr>
            </table>
            @if ($suc)
                <div class="sala">
                    <span class="k">Establecimiento / Sala de entrega:</span> <span class="nm">{{ $suc->nombre }}</span>
                    @if ($salaUbic)<div style="margin-top:1px;">{{ $salaUbic }}</div>@endif
                    @if ($suc->direccion)<div><span class="k">Dirección:</span> {{ $suc->direccion }}</div>@endif
                </div>
            @endif
        </div>
        <div class="strip">
            <span class="k">Operación:</span> <strong>{{ $dte->condicion_operacion?->label() ?? '—' }}</strong>
            <span class="sep">|</span> <span class="k">Moneda:</span> <strong>{{ $dte->moneda }}</strong>
            <span class="sep">|</span> <span class="k">Fecha:</span> <strong>{{ $dte->fecha_emision?->format('d/m/Y') ?? '—' }}</strong>
            @if ($esNc && $dte->tipo_nota_credito)<span class="sep">|</span> <span class="k">Tipo NC:</span> <strong>{{ $dte->tipo_nota_credito->label() }}</strong>@endif
            <span class="sep">|</span> <span class="k">Estado:</span> <strong>{{ $dte->estado->label() }}</strong>
        </div>
    </div>

    {{-- APÉNDICE / DATOS ADICIONALES (una línea compacta) --}}
    @if ($hayApendice)
        <div class="sec nobreak" style="margin-bottom:6px;">
            <div class="strip" style="border-top:0;">
                <span class="k" style="text-transform:uppercase;letter-spacing:.8px;font-size:6.5px;">Apéndice · Datos adicionales</span>
                <span class="sep">|</span>
                @if ($tieneOc || $requiereOc)
                    <span class="k">{{ $etiquetaOc }}:</span>
                    @if ($tieneOc)<span class="oc">{{ $dte->numero_orden_compra }}</span>@else<span class="miss">Requerida — pendiente</span>@endif
                @endif
                @if ($esNc && $dte->dte_relacionado_id)<span class="sep">|</span> <span class="k">Documento original:</span> <span class="mono">{{ $dte->dteRelacionado?->numero_control ?? $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}</span>@endif
            </div>
        </div>
    @endif

    {{-- DATOS DE EXPORTACIÓN (solo FEX tipo 11): recinto fiscal, régimen e incoterm ya
         guardados en el DTE. Solo presentación; no afecta a CCF/NC/Factura consumidor final. --}}
    @if ($esFex && $datosExportacion)
        <div class="sec nobreak" style="margin-bottom:6px;">
            <div class="strip" style="border-top:0;">
                <span class="k" style="text-transform:uppercase;letter-spacing:.8px;font-size:6.5px;">Datos de exportación</span>
                <span class="sep">|</span>
                <span class="k">Tipo ítem:</span> {{ $datosExportacion['tipo_item'] }}
                <span class="sep">|</span>
                <span class="k">Recinto fiscal:</span> {{ $datosExportacion['recinto_fiscal'] ?? '—' }}
                <span class="sep">|</span>
                <span class="k">Tipo régimen:</span> {{ $datosExportacion['tipo_regimen'] ?? '—' }}
                <span class="sep">|</span>
                <span class="k">Régimen:</span> {{ $datosExportacion['regimen'] ?? '—' }}
                <span class="sep">|</span>
                <span class="k">Incoterm:</span> {{ $datosExportacion['incoterm'] ?? '—' }}
            </div>
        </div>
    @endif

    {{-- PRODUCTOS — regla fija: MÁXIMO 10 líneas por página. Cada bloque de 10 va en su
         propia página (con su encabezado de columnas); el 11º producto en adelante salta
         a la página siguiente. Solo maquetación: no cambia montos ni textos fiscales. --}}
    @php $bloquesLineas = $dte->lineas->chunk(10); @endphp
    @forelse ($bloquesLineas as $iBloque => $bloque)
        @if ($iBloque > 0)
            <div class="cont-hdr items-cont">Continuación · {{ $dte->numero_control ?: $dte->numero_interno }} · líneas {{ $iBloque * 10 + 1 }}–{{ $iBloque * 10 + $bloque->count() }}</div>
        @endif
        <table class="items {{ $iBloque > 0 ? 'items-blk' : '' }}">
            <thead>
                <tr>
                    <th class="ci">#</th>
                    <th>Código</th>
                    <th>Producto / descripción</th>
                    <th class="r">Cant.</th>
                    <th>Present.</th>
                    <th class="r">Precio</th>
                    <th class="r">Desc.</th>
                    @if ($hayNoSujeto)<th class="r">No suj.</th>@endif
                    @if ($hayExento)<th class="r">Exento</th>@endif
                    <th class="r">{{ $baseLabel }}</th>
                    <th class="r">IVA</th>
                    <th class="r">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bloque as $linea)
                    @php
                        $cb = trim((string) $linea->codigo_barra);
                        $cc = trim((string) $linea->codigo);
                        $pres = \App\Support\Dte\PresentacionUnidadLinea::etiqueta($linea, $dte);
                    @endphp
                    <tr>
                        <td class="ci">{{ $linea->numero_linea }}</td>
                        <td>
                            {{-- Solo el código de barras; el código interno solo como respaldo si no hay barras. --}}
                            @if ($cb !== '')
                                <span class="cod">{{ $cb }}</span>
                            @elseif ($cc !== '')
                                <span class="cod">{{ $cc }}</span>
                            @else
                                <span class="dash">—</span>
                            @endif
                        </td>
                        <td><span class="pn">{{ $linea->descripcion }}</span></td>
                        <td class="r num">{{ rtrim(rtrim($linea->cantidad, '0'), '.') }}</td>
                        <td>{{ $pres !== '' ? $pres : '—' }}</td>
                        <td class="r num">${{ number_format($linea->precio_unitario, 2) }}</td>
                        <td class="r num">@if((float)$linea->descuento_monto > 0)-${{ number_format($linea->descuento_monto, 2) }}@else<span class="dash">—</span>@endif</td>
                        @if ($hayNoSujeto)<td class="r num">${{ number_format($linea->venta_no_sujeta, 2) }}</td>@endif
                        @if ($hayExento)<td class="r num">${{ number_format($linea->venta_exenta, 2) }}</td>@endif
                        <td class="r num">${{ number_format($esFex ? $linea->venta_exportacion : $linea->venta_gravada, 2) }}</td>
                        <td class="r num">${{ number_format($linea->iva_linea, 2) }}</td>
                        <td class="r num"><strong>${{ number_format($linea->total_linea, 2) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <table class="items">
            <thead>
                <tr>
                    <th class="ci">#</th><th>Código</th><th>Producto / descripción</th><th class="r">Cant.</th><th>Present.</th>
                    <th class="r">Precio</th><th class="r">Desc.</th>@if ($hayNoSujeto)<th class="r">No suj.</th>@endif@if ($hayExento)<th class="r">Exento</th>@endif<th class="r">{{ $baseLabel }}</th><th class="r">IVA</th><th class="r">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="{{ $colspan }}" style="text-align:center;color:#9AA0A8;padding:10px;">Sin líneas.</td></tr>
            </tbody>
        </table>
    @endforelse

    {{-- BLOQUE INFERIOR: letras / condición / firmas  +  totales fiscales --}}
    <table class="botwrap nobreak">
        <tr>
            <td style="width: 53%; padding-right: 12px;">
                <div class="letras"><span class="k">Valor en letras</span><br>{{ $valorLetras }}</div>
                @if ($dte->observaciones || $dte->motivo)
                    <div class="cond-line">@if($dte->motivo)<span class="k">Motivo:</span> {{ $dte->motivo }} @endif@if($dte->observaciones)<span class="k">Observaciones:</span> {{ $dte->observaciones }}@endif</div>
                @endif
                <div class="cond-line"><span class="k">Condición de la operación:</span> <strong>{{ $dte->condicion_operacion?->label() ?? '—' }}</strong></div>
                <table class="firmas2">
                    <tr>
                        <td>
                            <div class="fl"><span class="flk">Entregado por</span><span class="fline"></span></div>
                            <div class="fl"><span class="flk">N° de documento</span><span class="fline"></span></div>
                            <div class="fl"><span class="flk">Firma</span><span class="fline"></span></div>
                        </td>
                        <td class="gap2"></td>
                        <td>
                            <div class="fl"><span class="flk">Recibido por</span><span class="fline"></span></div>
                            <div class="fl"><span class="flk">N° de documento</span><span class="fline"></span></div>
                            <div class="fl"><span class="flk">Firma</span><span class="fline"></span></div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 47%;">
                <div class="totbox">
                    <table class="tot">
                        {{-- Sumas de ventas --}}
                        @if ($esFex)
                            <tr><td class="k">Ventas exportación</td><td class="v">${{ number_format($dte->total_exportacion, 2) }}</td></tr>
                        @else
                            <tr><td class="k">Ventas gravadas</td><td class="v">${{ number_format($dte->total_gravado, 2) }}</td></tr>
                        @endif
                        @if ($hayExento)<tr><td class="k">Ventas exentas</td><td class="v">${{ number_format($dte->total_exento, 2) }}</td></tr>@endif
                        @if ($hayNoSujeto)<tr><td class="k">Ventas no sujetas</td><td class="v">${{ number_format($dte->total_no_sujeto, 2) }}</td></tr>@endif
                        <tr class="sub"><td class="k">Sumas de ventas</td><td class="v">${{ number_format($dte->subtotal, 2) }}</td></tr>
                        {{-- Descuentos. El % del cliente se muestra en la etiqueta para que sea claro. --}}
                        @php($pctDesc = rtrim(rtrim(number_format((float) $dte->descuento_porcentaje_aplicado, 2), '0'), '.'))
                        <tr class="minus"><td class="k">Descuento global @if((float)$dte->descuento_porcentaje_aplicado > 0)({{ $pctDesc }}%)@endif</td><td class="v">-${{ number_format($descGlobal, 2) }}</td></tr>
                        @if ($descItems > 0)
                            <tr class="minus"><td class="k">Descuento por ítem y global</td><td class="v">-${{ number_format($descItemYGlobal, 2) }}</td></tr>
                        @endif
                        <tr class="rule sub"><td class="k">Sub-total</td><td class="v">${{ number_format($subTotalNeto, 2) }}</td></tr>
                        {{-- Impuestos --}}
                        @if ($esFex)
                            <tr><td class="k">IVA (0%)</td><td class="v">${{ number_format($dte->iva, 2) }}</td></tr>
                            <tr><td class="k">Flete</td><td class="v">${{ number_format($dte->flete, 2) }}</td></tr>
                            <tr><td class="k">Seguro</td><td class="v">${{ number_format($dte->seguro, 2) }}</td></tr>
                        @else
                            <tr><td class="k">Impuesto al Valor Agregado 13% (IVA)</td><td class="v">${{ number_format($dte->iva, 2) }}</td></tr>
                        @endif
                        @if ($esFactura)
                            <tr><td colspan="2" class="tiny muted" style="font-style:italic;padding-top:2px;">Precios con IVA incluido.</td></tr>
                        @endif
                        <tr class="sub"><td class="k">Monto total de la operación</td><td class="v">${{ number_format($dte->monto_total_operacion, 2) }}</td></tr>
                        {{-- Retenciones --}}
                        @if ((float)$dte->iva_retenido > 0)
                            <tr class="minus"><td class="k">IVA retenido</td><td class="v">-${{ number_format($dte->iva_retenido, 2) }}</td></tr>
                        @elseif ($esCcf)
                            <tr class="zero"><td class="k">IVA retenido</td><td class="v">$0.00</td></tr>
                        @endif
                        @if ((float)$dte->retencion_renta > 0)
                            <tr class="minus"><td class="k">Retención renta</td><td class="v">-${{ number_format($dte->retencion_renta, 2) }}</td></tr>
                        @elseif ($esCcf)
                            <tr class="zero"><td class="k">Retención renta</td><td class="v">$0.00</td></tr>
                        @endif
                    </table>
                    <table class="grand">
                        <tr><td class="gl">{{ $esNc ? 'Total a acreditar' : 'Total a pagar' }}</td><td class="gv">${{ number_format($dte->total_pagar, 2) }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    {{-- ESTADO TÉCNICO (una línea) --}}
    <div class="tech">
        <span class="th">Estado técnico (interno):</span>
        JSON generado: <strong>{{ $jsonGenerado ? 'sí' : 'no' }}</strong> ·
        Firmado localmente: <strong>{{ $firmadoLocal ? 'sí' : 'no' }}</strong> ·
        JWS firmado: <strong>{{ $firmadoLocal ? 'sí' : 'no' }}</strong> ·
        Sello de recepción: <strong>{{ $tieneSello ? 'sí' : 'no' }}</strong> ·
        Estado Hacienda: <strong>{{ $estadoHacienda }}</strong>
    </div>

    <div class="pie">
        Representación gráfica generada el {{ now()->format('d/m/Y H:i') }}.
        @if ($preliminar) Documento PRELIMINAR — no equivale a un DTE emitido ante Hacienda hasta completar transmisión y sello de recepción. @endif
    </div>
</body>
</html>
