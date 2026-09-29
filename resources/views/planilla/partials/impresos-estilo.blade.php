{{-- Estilo de los impresos de planilla.

     Reglas que lo gobiernan, todas nacidas de que esto termina en papel:

     1. MEDIDAS EN MILÍMETROS. Es papel, no una pantalla. Carta con márgenes de
        10 mm deja 196 × 259.4 mm útiles.

     2. NADA DEPENDE DEL COLOR. Se imprime en blanco y negro sobre papel barato.
        Un pago parcial se distingue por un recuadro y una palabra, nunca por un
        tono. El único color de la hoja es el del logo.

     3. LAS TABLAS FIJAN SU COLOR. Sin doctype —o en cualquier modo quirks— una
        tabla no hereda el color de su contenedor y cae al del documento: sobre
        papel blanco, texto gris ilegible. Ya pasó una vez.

     4. NADA SE PARTE. Ni un recibo ni un renglón de firma pueden quedar a caballo
        entre dos páginas: la cabecera se repite y las filas se mantienen enteras.

     Diez personas caben en una carta porque se comprimió el ENTORNO —logo de
     13 mm, márgenes de 10, pie de firmas en una fila baja— antes que la letra. --}}
<style>
    .hoja-impresa {
        background: #fff; color: #141414;
        font-family: "Public Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        font-size: 10.5pt; line-height: 1.42;
        padding: 10mm; margin: 0 auto 1rem; max-width: 216mm;
        border: 1px solid #d4d4d8; border-radius: 4px;
    }
    .hoja-impresa .mono { font-family: ui-monospace, "SFMono-Regular", "IBM Plex Mono", Menlo, Consolas, monospace; }

    .hi-membrete { display: flex; align-items: center; gap: 6mm; padding-bottom: 2.6mm; border-bottom: 1.2pt solid #141414; }
    .hi-logo { width: 8.6mm; height: 13mm; flex: none; object-fit: contain; }
    .hi-identidad { flex: 1; min-width: 0; }
    .hi-negocio { font-weight: 700; font-size: 13.5pt; letter-spacing: .03em; line-height: 1.05; }
    .hi-empleadora { font-size: 8.4pt; line-height: 1.25; margin-top: .6mm; }
    .hi-empleadora .et { font-size: 6.4pt; letter-spacing: .14em; text-transform: uppercase; color: #5c5c5c; }
    .hi-empleadora .nom { font-weight: 600; }
    .hi-tipo { text-align: right; flex: none; }
    .hi-doc { font-weight: 600; font-size: 10.5pt; letter-spacing: .1em; text-transform: uppercase; line-height: 1.1; }
    .hi-folio { font-size: 7.6pt; color: #4a4a4a; margin-top: 1mm; line-height: 1.35; }

    table.hi-grupal { width: 100%; border-collapse: collapse; margin-top: 3.5mm; color: #141414; }
    table.hi-grupal thead th {
        font-size: 6.4pt; letter-spacing: .1em; text-transform: uppercase; color: #3a3a3a;
        font-weight: 600; padding: 0 1.6mm 1.4mm; border-bottom: 1.2pt solid #141414;
        text-align: right; line-height: 1.25;
    }
    table.hi-grupal thead th.izq { text-align: left; }
    table.hi-grupal tbody td { padding: 1.8mm 1.6mm; border-bottom: .6pt solid #c4c4c4; vertical-align: top; height: 17mm; }
    table.hi-grupal td.n { font-size: 8pt; color: #5c5c5c; }
    table.hi-grupal td.imp { text-align: right; font-variant-numeric: tabular-nums; font-size: 9.5pt; white-space: nowrap; }
    table.hi-grupal .nomg { font-weight: 600; font-size: 9.6pt; line-height: 1.2; }
    table.hi-grupal .duig { font-size: 7.4pt; color: #4a4a4a; margin-top: .4mm; }
    table.hi-grupal .perg { font-size: 8.2pt; white-space: nowrap; }
    table.hi-grupal .marca {
        display: block; margin-top: 1mm; border: 1pt solid #141414; padding: .6mm 1mm;
        font-weight: 700; font-size: 5.9pt; letter-spacing: .05em; text-transform: uppercase;
        line-height: 1.25; text-align: center;
    }
    table.hi-grupal .marca em { display: block; font-style: normal; font-weight: 500; letter-spacing: 0; }
    table.hi-grupal .aparte { display: block; margin-top: .8mm; font-size: 6.6pt; color: #3a3a3a; }
    table.hi-grupal tfoot td { padding: 2.2mm 1.6mm; border-top: 1.2pt solid #141414; font-weight: 700; border-bottom: none; height: auto; font-size: 9.6pt; }
    table.hi-grupal tfoot td.imp { text-align: right; font-variant-numeric: tabular-nums; }

    .hi-pie { display: flex; gap: 10mm; margin-top: 6mm; }
    .hi-pie .bloque { flex: 1; }
    .hi-pie .linea { border-bottom: .9pt solid #141414; height: 11mm; }
    .hi-pie .txt { font-size: 7.2pt; color: #4a4a4a; margin-top: 1.2mm; line-height: 1.35; }
    .hi-legal { margin-top: 4mm; padding-top: 1.8mm; border-top: .5pt solid #d2d2d2; font-size: 7pt; color: #5c5c5c; line-height: 1.35; }

    .hi-persona { margin-top: 5mm; }
    .hi-persona .nombre { font-size: 14pt; font-weight: 600; line-height: 1.15; }
    .hi-persona .dui { font-size: 9pt; color: #333; margin-top: 1mm; }
    .hi-fechas { margin-top: 4mm; border: .8pt solid #c4c4c4; border-radius: 1mm; display: flex; }
    .hi-fechas > div { padding: 2.4mm 4mm; flex: 1; }
    .hi-fechas > div + div { border-left: .8pt solid #c4c4c4; }
    .hi-fechas .et { font-size: 6.4pt; letter-spacing: .14em; text-transform: uppercase; color: #5c5c5c; display: block; margin-bottom: .7mm; }
    .hi-fechas .val { font-size: 10pt; font-variant-numeric: tabular-nums; }
    .hi-fechas .apunte { font-size: 7.8pt; color: #4a4a4a; display: block; margin-top: .5mm; }

    table.hi-desglose { width: 100%; border-collapse: collapse; margin-top: 5mm; color: #141414; }
    table.hi-desglose td { padding: 1.8mm 0; }
    table.hi-desglose td.imp { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; font-size: 10pt; }
    table.hi-desglose td.sangria { padding-left: 6mm; }
    table.hi-desglose tr.apartado td { padding-top: 3.5mm; padding-bottom: 1mm; font-size: 6.6pt; letter-spacing: .14em; text-transform: uppercase; color: #5c5c5c; border-top: .8pt solid #d2d2d2; }
    table.hi-desglose tr.total td { border-top: 1.2pt solid #141414; padding-top: 2.6mm; font-weight: 700; font-size: 12.5pt; }
    table.hi-desglose tr.total td.imp { font-size: 13.5pt; }

    .hi-parcial { margin-top: 4mm; border: 1.6pt solid #141414; border-radius: 1mm; padding: 2.6mm 4mm; display: flex; justify-content: space-between; align-items: baseline; gap: 4mm; flex-wrap: wrap; }
    .hi-parcial .et { font-weight: 700; font-size: 9pt; letter-spacing: .14em; text-transform: uppercase; }
    .hi-parcial .saldo { font-size: 9.5pt; font-variant-numeric: tabular-nums; }
    .hi-completo { margin-top: 3.5mm; font-size: 7.8pt; letter-spacing: .12em; text-transform: uppercase; color: #3a3a3a; text-align: right; }

    .hi-firmas { display: flex; gap: 10mm; margin-top: 9mm; }
    .hi-firmas .bloque { flex: 1; }
    .hi-firmas .linea { border-bottom: .9pt solid #141414; height: 13mm; }
    .hi-firmas .txt { font-size: 7.4pt; color: #4a4a4a; margin-top: 1.4mm; line-height: 1.35; }
    .hi-firmas .quien { font-size: 8pt; margin-top: .5mm; }
    .hi-firmas .fuente { font-size: 6.4pt; color: #6a6a6a; margin-top: .7mm; font-style: italic; }

    .hi-monto { margin-top: 5mm; border: 1.6pt solid #141414; border-radius: 1mm; padding: 4mm 5mm; display: flex; justify-content: space-between; align-items: baseline; gap: 6mm; flex-wrap: wrap; }
    .hi-monto .et { font-weight: 700; font-size: 9.5pt; letter-spacing: .12em; text-transform: uppercase; }
    .hi-monto .cifra { font-weight: 600; font-size: 20pt; font-variant-numeric: tabular-nums; line-height: 1; }
    .hi-saldo { margin-top: 3mm; border: .8pt solid #c4c4c4; border-radius: 1mm; display: flex; }
    .hi-saldo > div { padding: 2.2mm 3mm; flex: 1; }
    .hi-saldo > div + div { border-left: .8pt solid #c4c4c4; }
    .hi-saldo .et { font-size: 6.2pt; letter-spacing: .12em; text-transform: uppercase; color: #5c5c5c; display: block; margin-bottom: .6mm; }
    .hi-saldo .val { font-size: 9.5pt; font-variant-numeric: tabular-nums; }
    .hi-condicion { margin-top: 4mm; font-size: 9pt; line-height: 1.45; }
    .hi-tijera { margin-top: 6mm; border-top: .8pt dashed #9a9a9a; position: relative; height: 0; }
    .hi-tijera span { position: absolute; top: -1.9mm; left: 50%; transform: translateX(-50%); background: #fff; padding: 0 3mm; font-size: 6.4pt; letter-spacing: .12em; text-transform: uppercase; color: #6a6a6a; }

    .hi-sello {
        float: right; border: 2.5pt solid #141414; padding: 2mm 3mm; margin-left: 4mm;
        font-weight: 700; font-size: 8.5pt; letter-spacing: .09em; text-transform: uppercase;
        text-align: center; line-height: 1.25; max-width: 44mm;
    }
    .hi-sello small { display: block; font-weight: 500; font-size: 6.6pt; letter-spacing: .03em; text-transform: none; margin-top: .6mm; }

    @media print {
        @page { size: letter; margin: 10mm; }
        body { background: #fff !important; }
        /* Solo el papel: fuera menú, cabecera de la app y avisos de pantalla. */
        body * { visibility: hidden; }
        .hoja-impresa, .hoja-impresa * { visibility: visible; }
        .hoja-impresa {
            position: static; width: auto; max-width: none;
            margin: 0; padding: 0; border: none; border-radius: 0;
            break-inside: avoid; page-break-inside: avoid;
        }
        .hoja-impresa + .hoja-impresa { break-before: page; page-break-before: always; }
        .no-imprimir { display: none !important; }
        /* Con más de diez personas: cabecera repetida y ninguna fila partida. */
        table.hi-grupal thead { display: table-header-group; }
        table.hi-grupal tfoot { display: table-footer-group; }
        table.hi-grupal tr { break-inside: avoid; page-break-inside: avoid; }
        .hi-firmas, .hi-pie { break-inside: avoid; page-break-inside: avoid; }
    }
</style>
