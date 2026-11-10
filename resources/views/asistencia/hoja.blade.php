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

        Página impresa (docs/GUIA_PANTALLAS.md, «Páginas para imprimir»), igual que
        rutas/pedidos/hoja: documento aparte, sus propios estilos, sin el bundle ni modo
        oscuro, se lee igual en blanco y negro y entra en hoja carta vertical.

        Variables: $fecha (CarbonImmutable, el día local) y $filas
        [{nombre, entrada, salida}] con las horas ya en hora local («H:i» o null).
    --}}
    <style>
        :root {
            --tinta: #111827;
            --suave: #4b5563;
            --indigo: #3730a3;
            --indigo-borde: #c7d2fe;
            --indigo-claro: #eef2ff;
        }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            margin: 0;
            padding: 24px 16px;
            background: #fff;
            color: var(--tinta);
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 13px;
            line-height: 1.4;
        }
        .hoja { max-width: 820px; margin: 0 auto; }

        .franja {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 24px;
            margin-bottom: 16px; padding: 14px 20px;
            background: var(--indigo); color: #fff; border-radius: 10px;
            border-bottom: 5px solid #818cf8;
        }
        .marca { display: flex; align-items: center; gap: 16px; min-width: 0; }
        .logo { flex: none; width: 48px; height: 72px; padding: 4px; background: #fff; border-radius: 8px; object-fit: contain; }
        .empresa { margin: 0 0 2px; font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #e0e7ff; overflow-wrap: anywhere; }
        h1 { margin: 0; font-size: 22px; font-weight: 800; line-height: 1.2; letter-spacing: .04em; }
        .numero { margin: 0; text-align: right; }
        .numero span { display: block; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: #e0e7ff; }
        .numero strong { font-size: 24px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }

        .datos { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 0 0 16px; }
        .datos > div { min-width: 0; padding: 8px 12px; background: var(--indigo-claro); border: 1px solid var(--indigo-borde); border-radius: 8px; }
        .datos dt { font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--indigo); }
        .datos dd { margin: 2px 0 0; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }

        .tabla-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; border: 1px solid var(--indigo); }
        th, td { border: 1px solid #a5b4fc; padding: 6px 8px; vertical-align: middle; }
        thead th {
            background: var(--indigo); color: #fff; border-color: #6366f1;
            font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; text-align: left;
        }
        tbody tr:nth-child(even) td { background: #f5f7ff; }
        tbody td { height: 40px; }
        td.n, th.n { width: 2.5em; text-align: right; font-variant-numeric: tabular-nums; }
        td.n { color: var(--suave); }
        td.nombre { font-weight: 600; }
        td.hora, th.hora { width: 5.5em; text-align: center; font-variant-numeric: tabular-nums; white-space: nowrap; }
        td.hora { font-size: 16px; font-weight: 700; }
        td.firma, th.firma { width: 22%; }
        .vacia { padding: 24px 8px; text-align: center; color: var(--suave); }

        .leyenda { margin: 8px 0 0; font-size: 11px; color: var(--suave); }

        .no-print { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px; max-width: 820px; margin: 0 auto 16px; }
        .no-print form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-right: auto; }
        .no-print label { font-weight: 600; color: #374151; }
        .no-print input {
            min-height: 44px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; color: var(--tinta);
        }
        .no-print a, .no-print button {
            display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px;
            border: 1px solid #d1d5db; background: #fff; color: #374151; font: inherit; font-weight: 500;
            text-decoration: none; border-radius: 6px; cursor: pointer;
        }
        .no-print a:hover, .no-print button:hover { background: #f9fafb; }
        .no-print a:focus-visible, .no-print button:focus-visible, .no-print input:focus-visible { outline: 2px solid #4f46e5; outline-offset: 2px; }
        .no-print .error { flex-basis: 100%; margin: 0; color: #991b1b; font-weight: 600; }

        @media (max-width: 640px) {
            .datos { grid-template-columns: minmax(0, 1fr); }
            .numero { text-align: left; }
            td.firma, th.firma { width: auto; min-width: 6em; }
        }
        @page { size: letter portrait; margin: 12mm; }
        @media print {
            body { padding: 0; }
            .hoja { max-width: none; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
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
    <header class="franja">
        <div class="marca">
            <img class="logo" src="{{ asset('images/dte/logo-pdf.png') }}" alt="Logo de {{ $empresa }}" width="48" height="72">
            <div>
                <p class="empresa">{{ $empresa }}</p>
                <h1>CONTROL DE ASISTENCIA</h1>
            </div>
        </div>
        <p class="numero"><span>Fecha</span> <strong>{{ $fecha->format('d/m/Y') }}</strong></p>
    </header>

    <dl class="datos">
        <div><dt>Área</dt><dd>Producción</dd></div>
        <div><dt>Día</dt><dd>{{ ucfirst($fecha->locale('es')->dayName) }} {{ $fecha->format('d/m/Y') }}</dd></div>
        <div><dt>Personas</dt><dd>{{ count($filas) }}</dd></div>
    </dl>

    <div class="tabla-wrap">
        <table>
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
