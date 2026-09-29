{{-- Recibo individual · OPCIÓN SECUNDARIA.

     Se imprime desde un pago que YA existe: toma de ahí el importe entregado, la
     fecha real, quién lo entregó y si fue completo o a cuenta. Imprimirlo no
     registra un segundo pago.

     Dos reglas que se ven en el papel:

       · Si no hay descuentos, ese apartado NO APARECE. Ni en cero ni vacío: no
         aparece. Imprimir «Descuentos: 0.00» obliga a leer una línea para
         descubrir que no dice nada.

       · Un borrador no declara recibido. Cambia de nombre, pierde el número, gana
         el sello y se queda sin línea de firma. Una firma sobre un dinero que no
         salió es un problema de verdad. --}}
{{-- Se recibe de quien incluye la parte. La vista previa de formatos no tiene
     una planilla de verdad, y la parte no puede exigirle una. --}}
@php($esBorrador = $esBorrador ?? (isset($planilla) && $planilla->borrador()))

@foreach ($lineas as $l)
    <div class="hoja-impresa">
        @if ($esBorrador)
            <div class="hi-sello">Borrador<small>no es comprobante de pago</small></div>
        @endif

        @include('planilla.partials.membrete', [
            'documento' => $esBorrador ? 'Detalle de la quincena' : 'Recibo de pago',
            'folio' => $esBorrador ? 'Sin número · impreso el '.e($cabecera['hoy']) : e($l['folio']),
        ])

        <div class="hi-persona">
            <div class="nombre">{{ $l['nombre'] }}</div>
            @if ($l['dui'])
                <div class="dui mono">DUI {{ $l['dui'] }}</div>
            @endif
        </div>

        <div class="hi-fechas">
            <div>
                <span class="et">Período pagado</span>
                <span class="val mono">{{ $l['periodo_largo'] }}</span>
                @if ($l['periodo_propio'])
                    <span class="apunte">Período particular dentro de la quincena</span>
                @endif
            </div>
            <div>
                <span class="et">Fecha del pago</span>
                @if ($esBorrador)
                    <span class="val">todavía sin pagar</span>
                @else
                    <span class="val mono">{{ $l['fecha_pago'] ?? '—' }}</span>
                @endif
            </div>
        </div>

        <table class="hi-desglose">
            <tr>
                <td>Importe del período</td>
                <td class="imp mono">{{ $l['salario'] }}</td>
            </tr>

            @foreach ($l['ingresos'] as $ingreso)
                <tr>
                    <td class="sangria">{{ $ingreso['concepto'] }}</td>
                    <td class="imp mono">{{ $ingreso['importe'] }}</td>
                </tr>
            @endforeach

            {{-- El apartado entero vive dentro de la condición: sin descuentos no se imprime. --}}
            @if (count($l['descuentos']) > 0)
                <tr class="apartado"><td colspan="2">Descuentos</td></tr>
                @foreach ($l['descuentos'] as $descuento)
                    <tr>
                        <td class="sangria">
                            {{ $descuento['concepto'] }}
                            @if ($descuento['tercero'])
                                <span style="color:#4a4a4a">· se entrega a {{ $descuento['tercero'] }}</span>
                            @endif
                        </td>
                        <td class="imp mono">− {{ $descuento['importe'] }}</td>
                    </tr>
                @endforeach
            @endif

            <tr class="total">
                <td>{{ $esBorrador ? 'A pagar' : 'Total recibido' }}</td>
                <td class="imp mono">{{ $moneda }} {{ $esBorrador ? $l['t']['a_pagar_txt'] : $l['pagado_txt'] }}</td>
            </tr>
        </table>

        @unless ($esBorrador)
            @if ($l['parcial'])
                <div class="hi-parcial">
                    <span class="et">Pago parcial</span>
                    <span class="saldo mono">Queda pendiente {{ $moneda }} {{ $l['pendiente_txt'] }}</span>
                </div>
            @else
                <p class="hi-completo">Pago completo · no queda saldo pendiente</p>
            @endif

            <div class="hi-firmas">
                <div class="bloque">
                    <div class="linea"></div>
                    <div class="txt">
                        {{-- Ternario y no una directiva pegada a la palabra:
                             «conforme@if» no se compilaría y su cierre sí. --}}
                        Recibí conforme{{ $l['parcial'] ? " los {$moneda} {$l['pagado_txt']} detallados arriba" : '' }}
                    </div>
                    <div class="quien mono">{{ $l['nombre'] }}@if ($l['dui']) · DUI {{ $l['dui'] }}@endif</div>
                </div>
                <div class="bloque">
                    <div class="linea"></div>
                    <div class="txt">Entregó el pago</div>
                    @if ($l['entrego'])
                        <div class="quien mono">{{ $l['entrego'] }}</div>
                        <div class="fuente">quien entregó este pago según el registro</div>
                    @endif
                </div>
            </div>
        @endunless

        <p class="hi-legal">
            Control interno de remuneraciones con importes revisados a mano. No es una planilla de
            nómina legal: no incluye cálculos de ISSS, AFP, renta, vacaciones, aguinaldo,
            indemnización ni horas extra.
            @if ($esBorrador)
                La planilla no está confirmada y no se ha pagado nada: este documento
                <strong>no lleva espacio para firmar</strong> ni declara recibido.
            @endif
        </p>
    </div>
@endforeach
