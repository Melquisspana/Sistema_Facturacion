@php
    use App\Enums\TipoDte;
    use App\Enums\EstadoDte;

    $logoSrc = $logoSrc ?? null;
    $datosExportacion = $datosExportacion ?? null;

    $esFex = $dte->tipo_dte === TipoDte::FacturaExportacion;
    $esNc  = $dte->tipo_dte === TipoDte::NotaCredito;
    $esCcf = $dte->tipo_dte === TipoDte::CreditoFiscal;
    $esFactura = $dte->tipo_dte === TipoDte::Factura;
    $esBorrador = $dte->estado === EstadoDte::Borrador;
    $esAnulado  = $dte->estado === EstadoDte::Invalidado;

    $tieneSello   = filled($dte->sello_recepcion);
    $firmadoLocal = filled($dte->json_firmado_path);
    $jsonGenerado = filled($dte->json_generado_path);
    $preliminar   = ! $tieneSello;
    $estadoHacienda = $tieneSello ? 'aceptado' : ($dte->estado === EstadoDte::Rechazado ? 'rechazado' : 'no transmitido');

    $baseLabel = $esFex ? 'Exportación' : 'Gravado';

    $hayExento   = (float) $dte->total_exento > 0;
    $hayNoSujeto = (float) $dte->total_no_sujeto > 0;

    $cli = $dte->cliente;
    $suc = $dte->clienteSucursal;
    $requiereOc = $dte->requiereOrdenCompra();
    $etiquetaOc = $cli?->etiqueta_orden_compra ?: 'Orden de compra';
    $tieneOc = filled($dte->numero_orden_compra);
    $hayApendice = $tieneOc || $requiereOc || $dte->dte_relacionado_id;

    $cliComercial = ($cli?->nombre_comercial && trim($cli->nombre_comercial) !== trim((string) $cli->nombre))
        ? $cli->nombre_comercial : null;

    $ubic = function ($m) {
        $partes = array_filter([$m?->municipio?->nombre, $m?->departamento?->nombre]);
        return $partes ? implode(', ', $partes) : null;
    };
    // Ubicación de la sala con división 2024 (Distrito, Municipio, Departamento) si existe.
    $ubicSala = function ($s) use ($ubic) {
        if ($s?->distrito) {
            $partes = array_filter([$s->distrito->nombre, $s->distrito->municipio, $s->distrito->departamento?->nombre]);
            return $partes ? implode(', ', $partes) : null;
        }
        return $ubic($s);
    };
    $emUbic  = $ubic($emisor);
    $cliUbic = $ubic($cli);
    $sucUbic = $ubicSala($suc);

    // Ubicación administrativa de la sala, por campos (división 2024 con respaldo al esquema previo).
    $salaDepto = $suc?->distrito?->departamento?->nombre ?? $suc?->departamento?->nombre;
    $salaMuni  = $suc?->distrito?->municipio ?? $suc?->municipio?->nombre;
    $salaDist  = $suc?->distrito?->nombre;
    $salaUbic = implode('  |  ', array_filter([
        $salaDepto ? 'Departamento: '.$salaDepto : null,
        $salaMuni  ? 'Municipio: '.$salaMuni : null,
        $salaDist  ? 'Distrito: '.$salaDist : null,
    ]));

    // Valor en letras y totales del bloque inferior (de campos persistidos; sin recálculo).
    $valorLetras = $dte->total_letras ?: \App\Support\Dte\NumeroALetras::convertir($dte->total_pagar ?? 0);
    $descItems = (float) $dte->lineas->sum(fn ($l) => (float) $l->descuento_monto);
    $descGlobal = (float) $dte->total_descuento;
    $descItemYGlobal = $descItems + $descGlobal;
    $subTotalNeto = (float) $dte->subtotal - $descGlobal;

    $cfgCo = config('company');
    $emRazon     = $emisor?->razon_social     ?: ($cfgCo['nombre'] ?? null);
    $emComercial = $emisor?->nombre_comercial  ?: ($cfgCo['nombre_comercial'] ?? null);
    $emNit       = $emisor?->nit               ?: ($cfgCo['nit'] ?? null);
    $emNrc       = $emisor?->nrc               ?: ($cfgCo['nrc'] ?? null);
    $emDir       = $emisor?->direccion         ?: ($cfgCo['direccion']['complemento'] ?? null);
    $emTel       = $emisor?->telefono          ?: ($cfgCo['contacto']['telefono'] ?? null);
    $emCorreo    = $emisor?->correo            ?: ($cfgCo['contacto']['correo'] ?? null);

    // Encabezado: marca (nombre comercial) grande; nombre legal (razón social) como sub-línea.
    $emMarca = $emComercial ?: $emRazon;
    $emLegal = ($emComercial && $emRazon && trim($emComercial) !== trim((string) $emRazon)) ? $emRazon : null;

    $totalLabel = $esNc ? 'Total a acreditar' : 'Total a pagar';
    $cols = 10 + ($hayNoSujeto ? 1 : 0) + ($hayExento ? 1 : 0);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $dte->tipo_dte->label() }} · {{ $dte->numero_interno ?? ('#'.$dte->id) }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <style>
        :root{
            --ink:#20242C; --ink2:#3A4150; --muted:#6B7280;
            --line:#D4D8DE; --line2:#E7EAEE; --fill:#F2F3F5;
            --paper:#FFFFFF; --surround:#E9EAEC;
            --accent:#6E2142; --accent-2:#44162B; --accent-soft:#F3E9ED;
            --warn:#9A5B0E; --warn-dot:#C8881E;
            --ok:#2F6B4E; --ok-bg:#EEF5F0; --ok-line:#BFE0CD;
        }
        *{ box-sizing:border-box; }
        html,body{ margin:0; }
        body{ font-family:"IBM Plex Sans",-apple-system,Segoe UI,Roboto,sans-serif; color:var(--ink);
            background:var(--surround); font-size:12px; line-height:1.32; -webkit-font-smoothing:antialiased;
            padding:24px 16px 56px; }
        .mono{ font-family:"IBM Plex Mono",ui-monospace,monospace; font-feature-settings:"tnum" 1; }
        .muted{ color:var(--muted); }
        .tiny{ font-size:10px; }
        .shell{ max-width:900px; margin:0 auto; }

        /* Toolbar (no imprime) */
        .toolbar{ display:flex; gap:9px; align-items:center; margin-bottom:14px; }
        .toolbar .spacer{ flex:1; }
        .btn{ display:inline-flex; align-items:center; border:0; cursor:pointer;
            font-family:inherit; font-size:12.5px; font-weight:600; padding:8px 15px;
            border-radius:7px; text-decoration:none; }
        .btn:focus-visible{ outline:2px solid var(--accent); outline-offset:2px; }
        .btn-primary{ background:var(--accent); color:#fff; }
        .btn-primary:hover{ background:var(--accent-2); }
        .btn-ghost{ background:#fff; color:var(--ink2); border:1px solid var(--line); }
        .btn-ghost:hover{ border-color:var(--muted); }

        /* Hoja */
        .sheet{ background:var(--paper); border:1px solid var(--line); border-radius:8px;
            padding:22px 24px; box-shadow:0 14px 34px -22px rgba(32,36,44,.4); }

        /* Cinta de estado (una línea) */
        .st{ display:flex; align-items:center; gap:9px; padding:6px 11px; border:1px solid var(--line);
            border-left-width:3px; border-radius:5px; font-size:11px; margin-bottom:12px; }
        .st .dot{ width:7px; height:7px; border-radius:50%; flex:none; }
        .st b{ font-weight:600; }
        .st .x{ color:var(--muted); }
        .st.prelim{ border-left-color:var(--warn-dot); background:#FCF8F0; }
        .st.prelim .dot{ background:var(--warn-dot); }
        .st.live{ border-left-color:var(--ok); background:var(--ok-bg); border-color:var(--ok-line); }
        .st.live .dot{ background:var(--ok); }
        .st.void{ border-left-color:#B0334F; background:#FBEDF0; border-color:#E3B7C2; }
        .st.void .dot{ background:#B0334F; }

        /* Ambiente de pruebas (ambiente=00): SIEMPRE visible, sin importar sello/estado. */
        .st-testing{ background:#FEF3C7; color:#7A4A0C; border:1.5px solid #D97706; border-radius:5px;
            padding:6px 11px; margin-bottom:12px; font-size:11px; font-weight:bold; text-align:center;
            letter-spacing:.3px; text-transform:uppercase; }
        .st-testing .sub{ display:block; font-size:9px; font-weight:normal; text-transform:none; letter-spacing:0; margin-top:2px; }

        .topline{ border-top:2px solid var(--accent); margin-bottom:13px; }

        /* Encabezado */
        .head{ display:flex; gap:16px; align-items:flex-start; }
        .brand{ display:flex; gap:12px; flex:1; min-width:0; }
        .logo,.logo-fb{ width:58px; height:58px; border-radius:11px; flex:none; }
        .logo{ object-fit:cover; border:1px solid var(--line); }
        .logo-fb{ background:var(--ink); color:#fff; display:flex; align-items:center; justify-content:center;
            font-size:25px; font-weight:700; }
        .brand-name{ font-size:19.5px; font-weight:700; color:var(--ink); line-height:1.12; letter-spacing:-.2px; }
        .brand-com{ color:var(--accent); font-weight:600; font-size:12px; margin-top:2px; }
        .brand-line{ font-size:10.5px; color:var(--ink2); margin-top:5px; line-height:1.5; }
        .brand-line .k{ color:var(--muted); }

        /* Caja de datos del DTE */
        .dbox{ width:248px; flex:none; border:1px solid var(--line); border-radius:7px; overflow:hidden; }
        .dbox-h{ background:var(--fill); border-bottom:1px solid var(--line); padding:6px 11px; }
        .dbox-h .lbl{ font-size:8px; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted); display:block; }
        .dbox-h .ttl{ font-size:14.5px; font-weight:700; color:var(--accent); line-height:1.12; }
        .dbox-row{ display:grid; grid-template-columns:64px 1fr; gap:9px; align-items:baseline;
            padding:5px 11px; border-bottom:1px solid var(--line2); }
        .dbox-row:last-child{ border-bottom:0; }
        .dbox-row.key{ background:#FAFBFC; }
        .dbox-row .k{ font-size:9px; text-transform:uppercase; letter-spacing:.4px; color:var(--muted); }
        .dbox-row .v{ font-size:11px; font-weight:600; color:var(--ink); text-align:right; overflow-wrap:anywhere; }
        .dbox-row.key .v{ font-size:11.5px; }
        .dbox-row.live .v{ color:var(--ok); }
        /* Sello de recepción completo (40 chars sin guiones): se parte en varias líneas. */
        .dbox-row .v.sello{ word-break:break-all; }
        .pend{ color:#9AA0A8; font-style:italic; font-weight:500; }
        .qr{ width:74px; flex:none; text-align:center; }
        .qr img{ width:74px; height:74px; border:1px solid var(--line); border-radius:6px; }
        .qr-box{ border:1px dashed var(--line); border-radius:6px; padding:10px 4px; color:var(--muted);
            font-size:9px; line-height:1.3; }

        /* Secciones uniformes */
        .sec{ border:1px solid var(--line); border-radius:7px; margin-top:13px; }
        .sec-h{ background:var(--fill); border-bottom:1px solid var(--line); padding:4.5px 11px;
            font-size:9px; letter-spacing:1px; text-transform:uppercase; color:var(--muted); font-weight:600; }
        .sec-b{ padding:11px 12px; }
        .rec-name{ font-size:15.5px; font-weight:700; color:var(--ink); }
        .rec-com{ color:var(--accent); font-weight:600; font-size:12px; }
        .rec-grid{ display:flex; gap:18px; margin-top:7px; }
        .rec-grid > div{ flex:1; min-width:0; }
        .f{ font-size:11.5px; margin-top:3px; }
        .f .k{ color:var(--muted); }
        .sala{ margin-top:9px; padding-top:9px; border-top:1px dotted var(--line); font-size:11.5px; }
        .sala .k{ font-size:8.5px; letter-spacing:.8px; text-transform:uppercase; color:var(--muted); }
        .sala .nm{ font-weight:700; color:var(--ink); font-size:12.5px; }
        .sala .sub{ font-size:11px; color:var(--ink2); margin-top:2px; }
        .sala .sub .k{ text-transform:none; letter-spacing:0; font-size:11px; }

        /* Franja inferior (condición / apéndice en línea) */
        .strip{ border-top:1px solid var(--line); background:#FAFBFC; padding:5px 11px; font-size:11px; color:var(--ink2);
            border-radius:0 0 7px 7px; }
        .strip .k{ color:var(--muted); }
        .strip .sep{ color:var(--line); margin:0 3px; }
        .strip .oc{ font-family:"IBM Plex Mono",monospace; font-weight:600; color:var(--accent); }
        .strip .miss{ font-style:italic; font-weight:600; color:var(--warn); }
        .strip .ap{ font-size:8.5px; letter-spacing:.8px; text-transform:uppercase; color:var(--muted); }

        /* Tabla */
        .table-wrap{ margin-top:11px; border:1px solid var(--line); border-radius:7px; overflow:hidden; }
        table.items{ width:100%; border-collapse:collapse; font-size:11.5px; }
        table.items thead th{ background:var(--ink); color:#EDEFF2; text-align:left; font-size:9.3px;
            letter-spacing:.4px; text-transform:uppercase; font-weight:600; padding:7px 9px; white-space:nowrap; }
        table.items thead th.r{ text-align:right; }
        table.items tbody td{ padding:7.5px 9px; border-bottom:1px solid var(--line2); vertical-align:middle; }
        table.items tbody tr:last-child td{ border-bottom:0; }
        table.items tbody tr:nth-child(even) td{ background:#FAFBFC; }
        table.items td.r{ text-align:right; font-family:"IBM Plex Mono",monospace; white-space:nowrap; }
        .ln{ color:var(--muted); font-family:"IBM Plex Mono",monospace; font-size:10px; }
        .code{ font-family:"IBM Plex Mono",monospace; font-weight:600; font-size:11.5px; color:var(--ink); }
        .code .alt{ display:block; font-size:10px; color:#8A9099; font-weight:500; margin-top:1px; }
        .pname{ font-weight:600; color:var(--ink); }
        .dash{ color:var(--line); }

        /* Pie: letras/firmas + totales */
        .bot{ display:flex; gap:18px; margin-top:14px; align-items:flex-start; }
        .bot-l{ flex:1; min-width:0; }
        .letras{ border:1px solid var(--line); border-radius:7px; padding:9px 12px; font-size:11.5px; }
        .letras .k{ font-size:8.5px; letter-spacing:.8px; text-transform:uppercase; color:var(--muted); }
        .obs{ margin-top:8px; font-size:10.5px; color:var(--ink2); }
        .signs{ display:flex; gap:16px; margin-top:26px; }
        .signs > div{ flex:1; border-top:1px solid var(--line); padding-top:4px; font-size:9.5px; color:var(--muted); }
        .signs2{ display:flex; gap:18px; margin-top:12px; }
        .signs2 > div{ flex:1; }
        .fl{ margin-top:9px; }
        .fl .flk{ font-size:8px; text-transform:uppercase; letter-spacing:.4px; color:var(--muted); display:block; }
        .fl .fline{ display:block; border-bottom:1px solid var(--hair-strong, #B6BBC2); height:15px; }
        .totals tr.sub td{ font-weight:700; color:var(--ink); }

        .totals{ width:312px; flex:none; border:1px solid var(--line); border-radius:7px; overflow:hidden; }
        .totals table{ width:100%; border-collapse:collapse; }
        .totals td{ padding:5.5px 12px; font-size:12px; }
        .totals td.k{ color:var(--ink2); }
        .totals td.v{ text-align:right; font-family:"IBM Plex Mono",monospace; font-weight:600; color:var(--ink); }
        .totals tr.minus td.v{ color:var(--warn); }
        .totals tr.zero td{ color:var(--muted); }
        .totals tr.rule td{ border-top:1px solid var(--line); }
        .grand td{ background:var(--accent); color:#fff; padding:10px 12px; }
        .grand .gl{ font-size:9.5px; letter-spacing:1px; text-transform:uppercase; color:#E7C6D2; }
        .grand .gv{ text-align:right; font-family:"IBM Plex Mono",monospace; font-weight:700; font-size:18.5px; }

        /* Estado técnico (una línea) */
        .tech{ margin-top:11px; border:1px solid var(--line2); border-radius:6px; background:#FAFBFC;
            padding:5px 11px; font-size:9.5px; color:var(--muted); }
        .tech .th{ text-transform:uppercase; letter-spacing:.6px; color:var(--muted); font-weight:600; }
        .tech b{ color:var(--ink2); font-weight:600; }
        .pie{ margin-top:8px; font-size:9.5px; color:var(--muted); text-align:center; }

        @media (max-width:740px){
            .head{ flex-direction:column; } .dbox{ width:100%; } .qr{ width:100%; }
            .rec-grid,.bot,.signs{ flex-direction:column; } .totals{ width:100%; }
        }

        /* Impresión */
        @page{ size:letter; margin:12mm; }
        @media print{
            body{ background:#fff; padding:0; font-size:10.5px; }
            .toolbar{ display:none !important; }
            .sheet{ box-shadow:none; border:0; border-radius:0; padding:0; }
            .shell{ max-width:none; }
            .table-wrap{ overflow:visible; }
            .bot,.head,.sec,.totals{ page-break-inside:avoid; }
            *{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
    </style>
</head>
<body>
<div class="shell">

    <div class="toolbar">
        <button class="btn btn-primary" onclick="window.print()">Imprimir / Guardar PDF</button>
        <a class="btn btn-ghost" href="{{ route('facturacion.show', $dte) }}">Volver</a>
        <span class="spacer"></span>
        <a class="btn btn-ghost" href="{{ route('facturacion.pdf', $dte) }}" target="_blank">Ver PDF preliminar</a>
    </div>

    <div class="sheet">

        {{-- AMBIENTE DE PRUEBAS: única fuente de verdad es $dte->ambiente, NUNCA el sello.
             Debe verse aunque el documento esté aceptado/firmado/generado/rechazado. --}}
        @if ($dte->ambiente?->value === '00')
            <div class="st-testing">AMBIENTE DE PRUEBAS<span class="sub">Documento sin validez fiscal en producción</span></div>
        @endif

        {{-- CINTA DE ESTADO --}}
        @if ($esAnulado)
            <div class="st void"><span class="dot"></span><span><b>DOCUMENTO ANULADO / INVALIDADO INTERNAMENTE</b>@if ($dte->motivo_anulacion) <span class="x">· {{ $dte->motivo_anulacion->label() }}</span>@endif</span></div>
        @elseif ($tieneSello)
            <div class="st live"><span class="dot"></span><span><b>Aceptado por Hacienda</b> <span class="x">· Sello de recepción registrado · representación gráfica del DTE emitido.</span></span></div>
        @else
            <div class="st prelim"><span class="dot"></span><span><b>Representación gráfica preliminar</b> <span class="x">· sin sello de recepción · no equivale a un DTE emitido.</span>@if($firmadoLocal)<span class="x"> · firmado localmente</span>@endif@if($esBorrador) <b>· BORRADOR</b>@endif</span></div>
        @endif

        <div class="topline"></div>

        {{-- ENCABEZADO --}}
        <div class="head">
            <div class="brand">
                @if ($logoSrc)
                    <img class="logo" src="{{ $logoSrc }}" alt="Logo {{ $emMarca }}">
                @else
                    <div class="logo-fb">{{ mb_substr($emMarca ?? 'D', 0, 1) }}</div>
                @endif
                <div style="min-width:0;">
                    <div class="brand-name">{{ $emMarca ?? '—' }}</div>
                    @if ($emLegal)<div class="brand-com">{{ $emLegal }}</div>@endif
                    <div class="brand-line"><span class="k">NIT</span> <span class="mono">{{ $emNit ?? '—' }}</span> · <span class="k">NRC</span> <span class="mono">{{ $emNrc ?? '—' }}</span></div>
                    @if ($emUbic || $emDir)<div class="brand-line" style="margin-top:0;">@if($emUbic){{ $emUbic }}@endif @if($emDir)· {{ $emDir }}@endif</div>@endif
                    <div class="brand-line" style="margin-top:0;">Estab.: {{ $dte->establecimiento?->nombre ?? '—' }}@if($dte->establecimiento?->codigo) ({{ $dte->establecimiento->codigo }})@endif · PV: {{ $dte->puntoVenta?->nombre ?? '—' }}@if($dte->puntoVenta?->codigo) ({{ $dte->puntoVenta->codigo }})@endif</div>
                    @if ($emTel || $emCorreo)<div class="brand-line" style="margin-top:0;">@if($emTel){{ $emTel }}@endif@if($emCorreo) · {{ $emCorreo }}@endif</div>@endif
                </div>
            </div>

            <div class="dbox">
                <div class="dbox-h">
                    <span class="lbl">Documento Tributario Electrónico</span>
                    <span class="ttl">{{ $dte->tipo_dte->label() }} · {{ $dte->tipo_dte->value }}</span>
                </div>
                <div class="dbox-row"><span class="k">N° control</span><span class="v mono">@if($dte->numero_control){{ $dte->numero_control }}@else<span class="pend">pendiente</span>@endif</span></div>
                <div class="dbox-row"><span class="k">Cód. gen.</span><span class="v mono">@if($dte->codigo_generacion){{ $dte->codigo_generacion }}@else<span class="pend">pendiente</span>@endif</span></div>
                <div class="dbox-row key"><span class="k">N° interno</span><span class="v mono">{{ $dte->numero_interno ?? '—' }}</span></div>
                <div class="dbox-row key"><span class="k">Fecha</span><span class="v">{{ $dte->fecha_emision?->format('d/m/Y') }}@if($dte->hora_emision) {{ $dte->hora_emision }}@endif</span></div>
                @if ($tieneSello)
                    <div class="dbox-row key live"><span class="k">Sello rec.</span><span class="v mono sello">{{ $dte->sello_recepcion }}</span></div>
                @else
                    <div class="dbox-row key"><span class="k">Estado</span><span class="v">{{ $dte->estado->label() }}</span></div>
                @endif
            </div>

            <div class="qr">
                @if (! $tieneSello)
                    <div class="qr-box">QR oficial<br>pendiente<br><span class="tiny">sin sello</span></div>
                @endif
            </div>
        </div>

        {{-- RECEPTOR (+ condición integrada) --}}
        <div class="sec">
            <div class="sec-h">Receptor</div>
            <div class="sec-b">
                <span class="rec-name">{{ $cli?->nombre ?? 'Consumidor final' }}</span>@if ($cliComercial) <span class="rec-com">· {{ $cliComercial }}</span>@endif@if (! $cli) <span class="tiny muted">— Consumidor final sin identificar.</span>@endif
                <div class="rec-grid">
                    <div>
                        @if ($cli?->num_documento)<div class="f"><span class="k">Documento:</span> <span class="mono">{{ $cli->num_documento }}</span>@if($cli?->nrc) · <span class="k">NRC:</span> <span class="mono">{{ $cli->nrc }}</span>@endif</div>@elseif($cli?->nrc)<div class="f"><span class="k">NRC:</span> <span class="mono">{{ $cli->nrc }}</span></div>@endif
                        @if ($cli?->actividadEconomica?->nombre)<div class="f"><span class="k">Actividad:</span> {{ $cli->actividadEconomica->nombre }}</div>@endif
                    </div>
                    <div>
                        @if ($cliUbic)<div class="f"><span class="k">Ubicación:</span> {{ $cliUbic }}</div>@endif
                        @if ($cli?->direccion)<div class="f"><span class="k">Dirección:</span> {{ $cli->direccion }}@if($cli->complemento_direccion) — {{ $cli->complemento_direccion }}@endif</div>@endif
                    </div>
                </div>
                @if ($suc)
                    <div class="sala">
                        <span class="k">Establecimiento / Sala de entrega:</span> <span class="nm">{{ $suc->nombre }}</span>
                        @if ($salaUbic)<div class="sub">{{ $salaUbic }}</div>@endif
                        @if ($suc->direccion)<div class="sub"><span class="k">Dirección:</span> {{ $suc->direccion }}</div>@endif
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

        {{-- APÉNDICE (línea compacta) --}}
        @if ($hayApendice)
            <div class="sec">
                <div class="strip" style="border-top:0;border-radius:7px;">
                    <span class="ap">Apéndice · Datos adicionales</span>
                    <span class="sep">|</span>
                    @if ($tieneOc || $requiereOc)
                        <span class="k">{{ $etiquetaOc }}:</span>
                        @if ($tieneOc)<span class="oc">{{ $dte->numero_orden_compra }}</span>@else<span class="miss">Requerida — pendiente</span>@endif
                    @endif
                    @if ($dte->dte_relacionado_id)<span class="sep">|</span> <span class="k">Documento original:</span> <span class="mono">{{ $dte->dteRelacionado?->numero_control ?? $dte->dteRelacionado?->numero_interno ?? ('#'.$dte->dte_relacionado_id) }}</span>@endif
                </div>
            </div>
        @endif

        {{-- DATOS DE EXPORTACIÓN (solo FEX tipo 11). Solo presentación. --}}
        @if ($esFex && $datosExportacion)
            <div class="sec">
                <div class="strip" style="border-top:0;border-radius:7px;">
                    <span class="ap">Datos de exportación</span>
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

        {{-- PRODUCTOS --}}
        <div class="table-wrap">
            <table class="items">
                <thead>
                    <tr>
                        <th>#</th>
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
                    @forelse ($dte->lineas as $linea)
                        @php
                            $cb = trim((string) $linea->codigo_barra);
                            $cc = trim((string) $linea->codigo);
                            $pres = trim((string) ($linea->unidad_nombre ?: $linea->unidad_codigo));
                        @endphp
                        <tr>
                            <td class="ln">{{ $linea->numero_linea }}</td>
                            <td>
                                {{-- Solo el código de barras; el interno solo como respaldo si no hay barras. --}}
                                @if ($cb !== '')
                                    <span class="code">{{ $cb }}</span>
                                @elseif ($cc !== '')
                                    <span class="code">{{ $cc }}</span>
                                @else
                                    <span class="dash">—</span>
                                @endif
                            </td>
                            <td><span class="pname">{{ $linea->descripcion }}</span></td>
                            <td class="r">{{ rtrim(rtrim($linea->cantidad, '0'), '.') }}</td>
                            <td>{{ $pres !== '' ? $pres : '—' }}</td>
                            <td class="r">${{ number_format($linea->precio_unitario, 2) }}</td>
                            <td class="r">@if((float)$linea->descuento_monto > 0)-${{ number_format($linea->descuento_monto, 2) }}@else<span class="dash">—</span>@endif</td>
                            @if ($hayNoSujeto)<td class="r">${{ number_format($linea->venta_no_sujeta, 2) }}</td>@endif
                            @if ($hayExento)<td class="r">${{ number_format($linea->venta_exenta, 2) }}</td>@endif
                            <td class="r">${{ number_format($esFex ? $linea->venta_exportacion : $linea->venta_gravada, 2) }}</td>
                            <td class="r">${{ number_format($linea->iva_linea, 2) }}</td>
                            <td class="r"><strong>${{ number_format($linea->total_linea, 2) }}</strong></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $cols }}" style="text-align:center;color:var(--muted);padding:14px;">Sin líneas registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- BLOQUE INFERIOR: letras / condición / firmas + totales fiscales --}}
        <div class="bot">
            <div class="bot-l">
                <div class="letras"><span class="k">Valor en letras</span><br>{{ $valorLetras }}</div>
                @if ($dte->motivo || $dte->observaciones)
                    <div class="obs">@if($dte->motivo)<span class="muted">Motivo:</span> {{ $dte->motivo }} @endif@if($dte->observaciones)<span class="muted">Observaciones:</span> {{ $dte->observaciones }}@endif</div>
                @endif
                <div class="obs"><span class="muted">Condición de la operación:</span> <strong>{{ $dte->condicion_operacion?->label() ?? '—' }}</strong></div>
                <div class="signs2">
                    <div>
                        <div class="fl"><span class="flk">Entregado por</span><span class="fline"></span></div>
                        <div class="fl"><span class="flk">N° de documento</span><span class="fline"></span></div>
                        <div class="fl"><span class="flk">Firma</span><span class="fline"></span></div>
                    </div>
                    <div>
                        <div class="fl"><span class="flk">Recibido por</span><span class="fline"></span></div>
                        <div class="fl"><span class="flk">N° de documento</span><span class="fline"></span></div>
                        <div class="fl"><span class="flk">Firma</span><span class="fline"></span></div>
                    </div>
                </div>
            </div>
            <div class="totals">
                <table>
                    @if ($esFex)
                        <tr><td class="k">Ventas exportación</td><td class="v">${{ number_format($dte->total_exportacion, 2) }}</td></tr>
                    @else
                        <tr><td class="k">Ventas gravadas</td><td class="v">${{ number_format($dte->total_gravado, 2) }}</td></tr>
                    @endif
                    @if ($hayExento)<tr><td class="k">Ventas exentas</td><td class="v">${{ number_format($dte->total_exento, 2) }}</td></tr>@endif
                    @if ($hayNoSujeto)<tr><td class="k">Ventas no sujetas</td><td class="v">${{ number_format($dte->total_no_sujeto, 2) }}</td></tr>@endif
                    <tr class="sub"><td class="k">Sumas de ventas</td><td class="v">${{ number_format($dte->subtotal, 2) }}</td></tr>
                    @php($pctDesc = rtrim(rtrim(number_format((float) $dte->descuento_porcentaje_aplicado, 2), '0'), '.'))
                    <tr class="minus"><td class="k">Descuento global @if((float)$dte->descuento_porcentaje_aplicado > 0)({{ $pctDesc }}%)@endif</td><td class="v">-${{ number_format($descGlobal, 2) }}</td></tr>
                    @if ($descItems > 0)<tr class="minus"><td class="k">Descuento por ítem y global</td><td class="v">-${{ number_format($descItemYGlobal, 2) }}</td></tr>@endif
                    <tr class="rule sub"><td class="k">Sub-total</td><td class="v">${{ number_format($subTotalNeto, 2) }}</td></tr>
                    @if ($esFex)
                        <tr><td class="k">IVA (0%)</td><td class="v">${{ number_format($dte->iva, 2) }}</td></tr>
                        <tr><td class="k">Flete</td><td class="v">${{ number_format($dte->flete, 2) }}</td></tr>
                        <tr><td class="k">Seguro</td><td class="v">${{ number_format($dte->seguro, 2) }}</td></tr>
                    @else
                        <tr><td class="k">IVA 13%</td><td class="v">${{ number_format($dte->iva, 2) }}</td></tr>
                    @endif
                    @if ($esFactura)
                        <tr><td colspan="2" class="tiny muted" style="font-style:italic;padding-top:2px;">Precios con IVA incluido.</td></tr>
                    @endif
                    <tr class="sub"><td class="k">Monto total de la operación</td><td class="v">${{ number_format($dte->monto_total_operacion, 2) }}</td></tr>
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
                    <tr class="grand"><td class="gl">{{ $totalLabel }}</td><td class="gv">${{ number_format($dte->total_pagar, 2) }}</td></tr>
                </table>
            </div>
        </div>

        {{-- ESTADO TÉCNICO (una línea) --}}
        <div class="tech">
            <span class="th">Estado técnico (interno):</span>
            JSON: <b>{{ $jsonGenerado ? 'sí' : 'no' }}</b> ·
            Firmado: <b>{{ $firmadoLocal ? 'sí' : 'no' }}</b> ·
            JWS: <b>{{ $firmadoLocal ? 'sí' : 'no' }}</b> ·
            Sello recepción: <b>{{ $tieneSello ? 'sí' : 'no' }}</b> ·
            Hacienda: <b>{{ $estadoHacienda }}</b>
        </div>

        <div class="pie">
            Generado el {{ now()->format('d/m/Y H:i') }} ·
            @if ($tieneSello) representación gráfica del DTE emitido ante Hacienda. @else no equivale a un DTE emitido ante Hacienda. @endif
            @if ($esNc && ! $tieneSello) Pendiente validación contra esquema oficial MH. @endif
        </div>

    </div>{{-- /sheet --}}
</div>{{-- /shell --}}
</body>
</html>
