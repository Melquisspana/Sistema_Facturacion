{{-- Hoja grupal para firma · EL FORMATO PRINCIPAL.

     Un renglón por persona, diez por carta. Entraron diez comprimiendo el entorno
     —logo de 13 mm, márgenes de 10, pie de firmas en una fila baja— antes que la
     letra, que es la misma que se aprobó. Cada renglón conserva 17 mm y la columna
     de firma es la más ancha de la hoja.

     Las EXCEPCIONES se leen sin buscarlas: el período particular de quien entró a
     mitad va en su columna, el pago a cuenta lleva recuadro con lo que falta, y una
     fecha de pago distinta de la del encabezado se dice en el renglón.

     Los totales NO se cuadran a la fuerza. Si «total recibido» no coincide con
     importe menos descuentos es porque alguien cobró a cuenta, y esconder esa
     diferencia sería esconder justo lo que hay que revisar. --}}
{{-- Se recibe de quien incluye la parte. La vista previa de formatos no tiene
     una planilla de verdad, y la parte no puede exigirle una. --}}
@php($esBorrador = $esBorrador ?? (isset($planilla) && $planilla->borrador()))

<div class="hoja-impresa">
    @if ($esBorrador)
        <div class="hi-sello">Borrador<small>no es comprobante de pago</small></div>
    @endif

    @include('planilla.partials.membrete', [
        'documento' => 'Planilla para firma',
        'folio' => e($cabecera['periodo_largo'])
            .'<br>'.($esBorrador
                ? 'Sin pagar · impresa el '.e($cabecera['hoy'])
                : 'Pagada el '.e($cabecera['fecha_pago'] ?? '—')),
    ])

    <table class="hi-grupal">
        <thead>
            <tr>
                <th class="izq" style="width:6mm">N.º</th>
                <th class="izq" style="width:46mm">Trabajador</th>
                <th class="izq" style="width:16mm">Período</th>
                <th style="width:18mm">Importe</th>
                <th style="width:18mm">Descuento</th>
                <th style="width:26mm">{{ $esBorrador ? 'A pagar' : 'Total recibido' }}</th>
                @unless ($esBorrador)
                    <th class="izq" style="width:66mm">Firma</th>
                @endunless
            </tr>
        </thead>
        <tbody>
            @foreach ($lineas as $i => $l)
                <tr>
                    <td class="n mono">{{ $i + 1 }}</td>
                    <td>
                        <div class="nomg">{{ $l['nombre'] }}</div>
                        @if ($l['dui'])
                            <div class="duig mono">DUI {{ $l['dui'] }}</div>
                        @endif
                    </td>
                    <td class="perg mono">{{ $l['periodo'] }}</td>
                    <td class="imp mono">{{ $l['t']['total_ingresos_txt'] }}</td>
                    <td class="imp mono">{{ $l['t']['descuentos'] > 0 ? $l['t']['descuentos_txt'] : '—' }}</td>
                    <td class="imp mono">
                        {{ $esBorrador ? $l['t']['a_pagar_txt'] : $l['pagado_txt'] }}
                        @if (! $esBorrador && $l['parcial'])
                            <span class="marca">Pago parcial<em>faltan {{ $moneda }} {{ $l['pendiente_txt'] }}</em></span>
                        @endif
                        @if (! $esBorrador && $l['fecha_pago_propia'])
                            <span class="aparte mono">pagado el {{ $l['fecha_pago_propia'] }}</span>
                        @endif
                    </td>
                    @unless ($esBorrador)
                        {{-- La celda de la firma. La clase no es decorativa: le da el
                             ancho mínimo para que quepa una rúbrica de verdad. --}}
                        <td class="firma-celda"></td>
                    @endunless
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td></td>
                <td>{{ count($lineas) }} {{ count($lineas) === 1 ? 'trabajador' : 'trabajadores' }}</td>
                <td></td>
                <td class="imp mono">{{ $suma['total_ingresos_txt'] }}</td>
                <td class="imp mono">{{ $suma['descuentos_txt'] }}</td>
                <td class="imp mono">{{ $esBorrador ? $suma['a_pagar_txt'] : $suma['pagado_txt'] }}</td>
                @unless ($esBorrador)
                    <td></td>
                @endunless
            </tr>
        </tfoot>
    </table>

    @unless ($esBorrador)
        <div class="hi-pie">
            <div class="bloque">
                <div class="linea"></div>
                {{-- OJO con pegar una directiva a una palabra: «pago@if» NO se
                     compila —Blade exige que no venga precedida de un carácter de
                     palabra— y en cambio su @endif sí, dejando un endif suelto que
                     rompe la vista entera. Por eso la condición va en su propio
                     bloque y no incrustada en la frase. --}}
                <div class="txt">
                    @if ($entregaron !== '')
                        Entregó el pago · <strong>{{ $entregaron }}</strong>
                        <br><em>según los pagos registrados de esta quincena</em>
                    @else
                        Entregó el pago · nombre y firma
                    @endif
                </div>
            </div>
            <div class="bloque">
                <div class="linea"></div>
                <div class="txt">Revisó · nombre y firma</div>
            </div>
        </div>
    @endunless

    <p class="hi-legal">
        Control interno de remuneraciones con importes revisados a mano. No es una planilla de
        nómina legal: no incluye cálculos de ISSS, AFP, renta, vacaciones, aguinaldo,
        indemnización ni horas extra.
        @unless ($esBorrador)
            Cada trabajador firma únicamente por el total recibido de su renglón; las diferencias
            entre importe y total recibido están señaladas donde corresponde.
        @else
            Esta planilla todavía no se confirma: no hay obligaciones, no se ha pagado nada y por
            eso no lleva espacio para firmar.
        @endunless
    </p>
</div>
