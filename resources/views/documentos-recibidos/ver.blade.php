{{-- «Ver» una compra: la factura armada desde el JSON del DTE que mandó el proveedor.
     No se guarda ningún PDF ni archivo: se arma cada vez que se abre.

     Todo lo que sale del JSON es de terceros y se escapa con {{ }}; nunca {!! !!}.

     La hoja es papel: va siempre en blanco y negro, también en modo oscuro, como los
     impresos de Planilla. Por eso usa sus propios estilos (clases fr-*) y no las de
     Tailwind, que app.css oscurecería. Se imprime con el navegador. --}}
<x-app-layout>
    @php
        $titulo = $dte['titulo'] ?? ($documento->tipo_documento ? $documento->tipoLabel() : 'Compra');
        $numero = $dte['numero_control'] ?? $documento->numero_control;
        // Jefatura (13/11/2026): número corto, sin código de generación ni sello.
        $soloNegocio = (bool) auth()->user()?->abreDocumentosComoPdf();
        if ($soloNegocio && $numero) {
            $numero = 'n.º …'.\App\Support\OrdenCompra::ultimosDigitos($numero);
        }
        $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
    @endphp

    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">{{ $titulo }}{{ $numero ? ' '.$numero : '' }}</h1>
            <div class="flex flex-wrap items-center gap-2">
                @if ($dte)
                    <button type="button" onclick="window.print()" class="{{ $boton }}">Imprimir</button>
                @endif
                @if ($tienePdf)
                    <a href="{{ route('documentos-recibidos.archivo', [$documento, 'pdf']) }}" target="_blank" rel="noopener" class="{{ $boton }}">Abrir PDF</a>
                @endif
                <a href="{{ route('documentos-recibidos.index') }}" class="{{ $boton }}">Volver a Compras</a>
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-6 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-4">
            @if ($aviso)
                <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
                    {{ $aviso }}
                    @if ($tienePdf)
                        Podés abrir el PDF que mandó el proveedor.
                    @endif
                </div>
            @endif

            @if ($dte)
                <style>
                    .fr-hoja {
                        background: #fff; color: #141414;
                        font-family: "Public Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
                        font-size: 10pt; line-height: 1.4;
                        padding: 10mm; margin: 0 auto; max-width: 216mm;
                        border: 1px solid #d4d4d8; border-radius: 4px;
                    }
                    .fr-hoja .fr-mono { font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; word-break: break-all; }
                    .fr-et { display: block; font-size: 7pt; letter-spacing: .12em; text-transform: uppercase; color: #5c5c5c; margin-bottom: .5mm; }
                    .fr-cabecera { display: flex; flex-wrap: wrap; gap: 6mm; justify-content: space-between; padding-bottom: 3mm; border-bottom: 1.2pt solid #141414; }
                    .fr-emisor { flex: 1 1 260px; min-width: 0; }
                    .fr-emisor .fr-nombre { font-weight: 700; font-size: 13pt; line-height: 1.15; }
                    .fr-emisor p, .fr-parte p { margin: .4mm 0 0; }
                    .fr-tipo { flex: 0 1 240px; text-align: right; }
                    .fr-tipo .fr-doc { font-weight: 700; font-size: 11pt; letter-spacing: .06em; text-transform: uppercase; line-height: 1.2; }
                    .fr-tipo p { margin: .8mm 0 0; }
                    .fr-ident { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2mm 6mm; margin-top: 3mm; padding: 2.5mm 3mm; border: .8pt solid #c4c4c4; border-radius: 1mm; }
                    .fr-parte { margin-top: 4mm; }
                    .fr-parte .fr-nombre { font-weight: 600; font-size: 11pt; }
                    .fr-relacion { margin-top: 3mm; font-size: 9pt; }
                    .fr-tabla-wrap { overflow-x: auto; margin-top: 4mm; }
                    table.fr-tabla { width: 100%; border-collapse: collapse; color: #141414; font-size: 9pt; }
                    table.fr-tabla th { font-size: 7pt; letter-spacing: .08em; text-transform: uppercase; color: #3a3a3a; font-weight: 600; text-align: left; padding: 0 1.5mm 1.4mm; border-bottom: 1.2pt solid #141414; }
                    table.fr-tabla td { padding: 1.6mm 1.5mm; border-bottom: .6pt solid #d2d2d2; vertical-align: top; }
                    table.fr-tabla .fr-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
                    table.fr-tabla tfoot td { border-top: 1.2pt solid #141414; border-bottom: none; font-weight: 700; }
                    .fr-cierre { display: flex; flex-wrap: wrap; gap: 6mm; justify-content: space-between; margin-top: 4mm; }
                    .fr-letras { flex: 1 1 240px; font-size: 9pt; }
                    table.fr-totales { flex: 0 1 300px; border-collapse: collapse; color: #141414; font-size: 9.5pt; }
                    table.fr-totales td { padding: 1mm 0 1mm 4mm; }
                    table.fr-totales td.fr-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
                    table.fr-totales tr.fr-destacado td { border-top: 1.2pt solid #141414; padding-top: 2mm; font-weight: 700; font-size: 11.5pt; }
                    .fr-apendice { margin-top: 4mm; font-size: 9pt; }
                    .fr-pie { margin-top: 6mm; padding-top: 2mm; border-top: .5pt solid #d2d2d2; font-size: 7.5pt; color: #5c5c5c; }
                    .fr-cel { display: none; }
                    @media (max-width: 640px) {
                        .fr-hoja { padding: 4mm; }
                        .fr-tipo { text-align: left; }
                    }
                    /* Celular: las líneas se apilan (cada dato con su nombre) en vez de correrse
                       de lado. Solo en pantalla: la hoja impresa mide menos de 768 px y tiene que
                       seguir saliendo como tabla. */
                    @media screen and (max-width: 767px) {
                        .fr-tabla-wrap { overflow: visible; }
                        table.fr-tabla, table.fr-tabla tbody, table.fr-tabla tfoot, table.fr-tabla tr { display: block; }
                        table.fr-tabla thead { display: none; }
                        table.fr-tabla tr { padding: 1.4mm 0; border-bottom: .6pt solid #d2d2d2; }
                        table.fr-tabla td, table.fr-tabla tfoot td { display: flex; justify-content: space-between; gap: 4mm; padding: .5mm 0; border: none; text-align: right; }
                        table.fr-tabla td:empty { display: none; }
                        table.fr-tabla tfoot tr { border-top: 1.2pt solid #141414; border-bottom: none; }
                        .fr-cel { display: block; flex: none; text-align: left; font-size: 7pt; letter-spacing: .08em; text-transform: uppercase; color: #5c5c5c; font-weight: 600; }
                    }
                    @media print {
                        @page { size: letter; margin: 10mm; }
                        body { background: #fff !important; }
                        /* Solo el papel: fuera menú, cabecera de la app y avisos de pantalla. */
                        nav, aside, header { display: none !important; }
                        .pt-16 { padding-top: 0 !important; }
                        main > div { padding: 0 !important; }
                        .fr-hoja { max-width: none; margin: 0; padding: 0; border: none; border-radius: 0; }
                        .fr-tabla-wrap { overflow: visible; }
                        table.fr-tabla thead { display: table-header-group; }
                        table.fr-tabla tr, .fr-cierre { break-inside: avoid; page-break-inside: avoid; }
                    }
                </style>

                @php
                    $emisor = $dte['emisor'];
                    $receptor = $dte['receptor'];
                    $fechaHora = trim(($dte['fecha'] ?? '').' '.($dte['hora'] ?? ''));
                @endphp

                <article class="fr-hoja" aria-label="{{ $titulo }}">
                    <div class="fr-cabecera">
                        <div class="fr-emisor">
                            <span class="fr-et">Emisor</span>
                            <div class="fr-nombre">{{ $emisor['nombre'] ?? '—' }}</div>
                            @if ($emisor['nombre_comercial'] && $emisor['nombre_comercial'] !== $emisor['nombre'])
                                <p>{{ $emisor['nombre_comercial'] }}</p>
                            @endif
                            <p>
                                @if ($emisor['documento'] && ! $emisor['nit'])
                                    {{ $emisor['documento'] }} · NRC {{ $emisor['nrc'] ?? '—' }}
                                @else
                                    NIT {{ $emisor['nit'] ?? '—' }} · NRC {{ $emisor['nrc'] ?? '—' }}
                                @endif
                            </p>
                            <p>Actividad: {{ $emisor['actividad'] ?? '—' }}</p>
                            <p>Dirección: {{ $emisor['direccion'] ?? '—' }}</p>
                            @if ($emisor['telefono'] || $emisor['correo'])
                                <p>{{ implode(' · ', array_filter([$emisor['telefono'], $emisor['correo']])) }}</p>
                            @endif
                        </div>
                        <div class="fr-tipo">
                            <div class="fr-doc">{{ $dte['titulo'] }}</div>
                            <p>Documento tributario electrónico</p>
                            <p>Fecha: {{ $fechaHora !== '' ? $fechaHora : '—' }}</p>
                            @if ($dte['condicion'])
                                <p>Condición: {{ $dte['condicion'] }}</p>
                            @endif
                        </div>
                    </div>

                    @unless ($soloNegocio)
                    <div class="fr-ident">
                        <div><span class="fr-et">Número de control</span><span class="fr-mono">{{ $dte['numero_control'] ?? '—' }}</span></div>
                        <div><span class="fr-et">Código de generación</span><span class="fr-mono">{{ $dte['codigo_generacion'] ?? '—' }}</span></div>
                        <div><span class="fr-et">Sello de recepción de Hacienda</span><span class="fr-mono">{{ $dte['sello'] ?? '—' }}</span></div>
                    </div>
                    @endunless

                    <div class="fr-parte">
                        <span class="fr-et">{{ $dte['etiqueta_receptor'] }}</span>
                        <div class="fr-nombre">{{ $receptor['nombre'] ?? '—' }}</div>
                        <p>
                            @if ($receptor['documento'] && ! $receptor['nit'])
                                {{ $receptor['documento'] }}{{ $receptor['nrc'] ? ' · NRC '.$receptor['nrc'] : '' }}
                            @else
                                NIT {{ $receptor['nit'] ?? '—' }} · NRC {{ $receptor['nrc'] ?? '—' }}
                            @endif
                        </p>
                        @if ($receptor['actividad'])
                            <p>Actividad: {{ $receptor['actividad'] }}</p>
                        @endif
                        @if ($receptor['direccion'])
                            <p>Dirección: {{ $receptor['direccion'] }}</p>
                        @endif
                        @if ($receptor['correo'])
                            <p>{{ $receptor['correo'] }}</p>
                        @endif
                    </div>

                    @foreach ($dte['relacionados'] as $rel)
                        <p class="fr-relacion">
                            Documento relacionado: {{ $rel['tipo'] ?? '—' }}
                            <span class="fr-mono">{{ $rel['numero'] ?? '—' }}</span>
                            del {{ $rel['fecha'] ?? '—' }}
                        </p>
                    @endforeach

                    <div class="fr-tabla-wrap">
                        <table class="fr-tabla">
                            <thead>
                                <tr>
                                    @foreach ($dte['columnas'] as $col)
                                        <th scope="col" @class(['fr-num' => $col['num']])>{{ $col['etiqueta'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($dte['lineas'] as $linea)
                                    <tr>
                                        @foreach ($dte['columnas'] as $col)
                                            <td @class(['fr-num' => $col['num']])><span class="fr-cel">{{ $col['etiqueta'] }}</span>{{ $linea[$col['clave']] ?? '—' }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ count($dte['columnas']) }}">El JSON no trae líneas.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr>
                                    @foreach ($dte['columnas'] as $i => $col)
                                        @if ($i === 0)
                                            <td>Sumas</td>
                                        @elseif (array_key_exists($col['clave'], $dte['sumas']))
                                            <td class="fr-num"><span class="fr-cel">{{ $col['etiqueta'] }}</span>{{ $dte['sumas'][$col['clave']] ?? '—' }}</td>
                                        @else
                                            <td></td>
                                        @endif
                                    @endforeach
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="fr-cierre">
                        <div class="fr-letras">
                            @if ($dte['total_letras'])
                                <span class="fr-et">Son</span>
                                {{ $dte['total_letras'] }}
                            @endif
                        </div>
                        <table class="fr-totales">
                            <tbody>
                                @foreach ($dte['totales'] as $fila)
                                    <tr @class(['fr-destacado' => $fila['destacado']])>
                                        <td>{{ $fila['etiqueta'] }}</td>
                                        <td class="fr-num">{{ $fila['valor'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($dte['apendice'] !== [])
                        <div class="fr-apendice">
                            <span class="fr-et">Información adicional</span>
                            @foreach ($dte['apendice'] as $dato)
                                <div>{{ $dato['etiqueta'] }}: {{ $dato['valor'] }}</div>
                            @endforeach
                        </div>
                    @endif

                    <p class="fr-pie">
                        Armado en pantalla a partir del JSON que mandó el proveedor. El documento
                        válido ante Hacienda es el original.
                    </p>
                </article>
            @endif
        </div>
    </div>
</x-app-layout>
