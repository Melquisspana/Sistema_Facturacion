<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Control de asistencia · {{ $fecha->format('d/m/Y') }}</title>
    {{--
        La hoja diaria de asistencia de Producción (issue #87, etapa 2): el mismo formato que
        se llenaba a mano, con las horas del lector y las firmas en blanco. Lista a quien
        marca con el lector; quien no marcó sale con las horas vacías para anotarlas a mano.
        No marca llegadas tarde: eso es un aviso suave en «Marcas de hoy».

        Documento aparte, sus propios estilos, sin el bundle ni modo oscuro, se lee igual en
        blanco y negro y entra en hoja carta vertical.

        Forma (13/11/2026, pedido del dueño): la de los documentos impresos (docs/GUIA_PANTALLAS.md,
        «Documentos impresos»), como el PDF del CCF, con el acento TERRACOTA y «HOJA DE ASISTENCIA»
        con «Documento interno» en el recuadro (es un control interno de Producción; «no es
        factura» no viene al caso). Lo común sale de components/documento.

        Variables: $fecha (CarbonImmutable, el día local) y $filas
        [{nombre, entrada, salida}] con las horas ya en hora local («H:i» o null).
    --}}
    <style>
        <x-documento.estilos acento="terracota" />

        @page { size: letter portrait; margin: 12mm; }
        body { padding: 24px 16px; font-size: 13px; line-height: 1.4; }
        .hoja { max-width: 820px; margin: 0 auto; }

        .tabla-wrap { overflow-x: auto; margin-top: 1.2em; }
        .items th, .items td { vertical-align: middle; }
        .items tbody td { height: 40px; border-left: 1px solid #EAECEF; }
        .items tbody td:first-child { border-left: 0; }
        .items tbody tr:nth-child(even) td { background: #F8F9FA; }
        td.n, th.n { width: 2.5em; text-align: right; font-variant-numeric: tabular-nums; }
        td.n { color: #6B7280; }
        td.nombre { font-weight: 600; }
        td.hora, th.hora { width: 5.5em; text-align: center; font-variant-numeric: tabular-nums; white-space: nowrap; }
        td.hora { font-size: 16px; font-weight: 700; }
        td.firma, th.firma { width: 22%; }
        .items th.n { text-align: right; }
        .items th.hora { text-align: center; }
        .vacia { padding: 24px 8px; text-align: center; color: #6B7280; }

        .leyenda { margin: 8px 0 0; font-size: 11px; color: #6B7280; }

        .no-print { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px; max-width: 820px; margin: 0 auto 16px; }
        .no-print form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-right: auto; }
        .no-print label { font-weight: 600; color: #20242C; }
        .no-print input {
            min-height: 44px; padding: 0 10px; border: 1px solid #C9CDD4; border-radius: 6px; font: inherit; color: #20242C;
        }
        .no-print a, .no-print button {
            display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px;
            border: 1px solid #C9CDD4; background: #fff; color: #20242C; font: inherit; font-weight: 600;
            text-decoration: none; border-radius: 6px; cursor: pointer;
        }
        .no-print a:hover, .no-print button:hover { background: #F8F9FA; }
        .no-print a:focus-visible, .no-print button:focus-visible, .no-print input:focus-visible { outline: 2px solid #A5512F; outline-offset: 2px; }
        .no-print .error { flex-basis: 100%; margin: 0; color: #991b1b; font-weight: 600; }

        /* En el celular el recuadro baja debajo de la empresa (solo pantalla; al imprimir, al lado). */
        @media screen and (max-width: 640px) {
            td.head-emisor, td.head-dbox { display: block; width: auto; padding: 0 0 14px; }
            td.firma, th.firma { width: auto; min-width: 6em; }
        }
        @media print {
            body { padding: 0; }
            .hoja { max-width: none; }
            .head { page-break-inside: avoid; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
@php($empresa = \App\Support\NombreEmpresa::actual())

<div class="no-print">
    <form method="GET" action="{{ route('asistencia.hoja') }}">
        <label for="fecha">Día</label>
        <input id="fecha" type="date" name="fecha" value="{{ $fecha->format('Y-m-d') }}" required>
        <button type="submit">Ver hoja</button>
        @error('fecha')
            <p class="error" role="alert">{{ $message }}</p>
        @enderror
    </form>
    <a href="{{ route('asistencia.dashboard') }}">Volver a «Marcas de hoy»</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<main class="hoja">
    <x-documento.encabezado :empresa="$empresa" :logo="asset('images/dte/logo-pdf.png')" detalle="Producción · horas del lector y firmas"
        etiqueta="Control de asistencia" titulo="HOJA DE ASISTENCIA" leyenda="Documento interno">
        <tr class="key"><td class="k">Día</td><td class="v">{{ ucfirst($fecha->locale('es')->dayName) }} {{ $fecha->format('d/m/Y') }}</td></tr>
        <tr><td class="k">Área</td><td class="v">Producción</td></tr>
        <tr><td class="k">Personas</td><td class="v">{{ count($filas) }}</td></tr>
    </x-documento.encabezado>

    <div class="tabla-wrap">
        <table class="items">
            <thead>
                <tr>
                    <th class="n" scope="col">N.º</th>
                    <th scope="col">Nombres y apellidos</th>
                    <th class="hora" scope="col">Entrada</th>
                    <th class="firma" scope="col">Firma</th>
                    <th class="hora" scope="col">Salida</th>
                    <th class="firma" scope="col">Firma</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($filas as $fila)
                    <tr>
                        <td class="n">{{ $loop->iteration }}</td>
                        <td class="nombre">{{ $fila['nombre'] }}</td>
                        <td class="hora">{{ $fila['entrada'] }}</td>
                        <td class="firma"></td>
                        <td class="hora">{{ $fila['salida'] }}</td>
                        <td class="firma"></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="vacia">Todavía no hay personal que marque con el lector.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="leyenda">Entrada y salida: la primera y la última marca del lector ese día, en hora de El Salvador. Sin marca, la hora queda en blanco para anotarla a mano.</p>
</main>

</body>
</html>
