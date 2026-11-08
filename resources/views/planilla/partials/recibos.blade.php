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
{{-- Se recibe de quien incluye la parte; si no lo dice, lo dice la planilla. --}}
@php($esBorrador = $esBorrador ?? (isset($planilla) && $planilla->borrador()))

@foreach ($lineas as $l)
    {{-- Vacaciones: el recibo dice qué se paga y de qué año de servicio, para que se firme. --}}
    {{-- El formato sale de la clase del pago; el año, de lo que quedó escrito al confirmarlo. --}}
    @php($vac = $l['es_vacaciones'] ?? false)
    @php($anio = $l['vacaciones'] ?? null)
    @php($anioTxt = $anio ? 'del '.$anio['desde']->format('d/m/Y').' al '.$anio['hasta']->format('d/m/Y') : 'sin fecha de ingreso')
    {{-- Indemnización o renuncia: lo mismo, con el tiempo de servicio y el detalle del cálculo. --}}
    @php($ind = $l['indemnizacion'] ?? null)
    <div class="hoja-impresa">
        @if ($esBorrador)
            <div class="hi-sello">Borrador<small>no es comprobante de pago</small></div>
        @endif

        @include('planilla.partials.membrete', [
            'documento' => $esBorrador
                ? ($ind ? 'Detalle del pago' : ($vac ? 'Detalle de vacaciones' : 'Detalle de la quincena'))
                : ($ind['titulo'] ?? ($vac ? 'Recibo de vacaciones' : 'Recibo de pago')),
            'folio' => $esBorrador ? 'Sin número · impreso el '.e($cabecera['hoy']) : e($l['folio']),
        ])

        <div class="hi-persona">
            <div class="nombre">{{ $l['nombre'] }}</div>
            @if ($l['dui'])
                <div class="dui mono">{{ \App\Models\Planilla\PlanillaEmpleado::DOCUMENTO_TIPOS[$l['documento_tipo'] ?? 'dui'] }} {{ $l['dui'] }}</div>
            @endif
            @if (filled($l['cargo'] ?? null))
                <div class="dui">Puesto: {{ $l['cargo'] }}</div>
            @endif
        </div>

        @if ($vac && ! $esBorrador)
            {{-- La misma cifra que «Total recibido» abajo: el papel no puede decir dos montos.
                 Se recibe de la empleadora; sin razón social cargada aparte, del negocio. --}}
            <p class="hi-declara">
                Recibí de {{ $empleadora ?: $negocio }} la cantidad de {{ $moneda === 'USD' ? 'US$' : $moneda }}
                <span class="mono">{{ $l['pagado_txt'] }}</span> en concepto de vacaciones anuales remuneradas:
                15 días de salario más 30 % de recargo, correspondientes al año de servicio {{ $anioTxt }}.
            </p>
        @endif

        @if ($ind && ! $esBorrador)
            <p class="hi-declara">
                Recibí de {{ $empleadora ?: $negocio }} la cantidad de {{ $moneda === 'USD' ? 'US$' : $moneda }}
                <span class="mono">{{ $l['pagado_txt'] }}</span> en concepto de {{ $ind['concepto'] }}, por
                {{ $ind['tiempo'] }} de servicio, del {{ $ind['desde']->format('d/m/Y') }} al {{ $ind['hasta']->format('d/m/Y') }}.
            </p>
        @endif
        @if ($ind && filled($ind['detalle']))
            <p class="hi-calculo"><span class="et">Detalle del cálculo</span> {{ $ind['detalle'] }}</p>
        @endif

        <div class="hi-fechas">
            <div>
                @if ($ind)
                    <span class="et">Tiempo de servicio</span>
                    <span class="val mono">del {{ $ind['desde']->format('d/m/Y') }} al {{ $ind['hasta']->format('d/m/Y') }}</span>
                @elseif ($vac)
                    <span class="et">Año de servicio</span>
                    <span class="val mono">{{ $anioTxt }}</span>
                @else
                    <span class="et">Período pagado</span>
                    <span class="val mono">{{ $l['periodo_largo'] }}</span>
                    @if ($l['periodo_propio'])
                        <span class="apunte">Período particular dentro de la quincena</span>
                    @endif
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
            {{-- Aguinaldo y Quincena 25 no pagan tiempo: su monto sale como ingreso. --}}
            @if ($l['salario'] !== '0.00')
                <tr>
                    <td>Importe del período</td>
                    <td class="imp mono">{{ $l['salario'] }}</td>
                </tr>
            @endif

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

            @if ($vac)
                {{-- Se llena a mano: el sistema no sabe cuándo se toman los días. --}}
                <p class="hi-goce">Período de goce: del ____________________ al ____________________</p>
            @endif

            <div class="hi-firmas">
                <div class="bloque">
                    <div class="linea"></div>
                    <div class="txt">
                        {{-- Ternario y no una directiva pegada a la palabra:
                             «conforme@if» no se compilaría y su cierre sí. --}}
                        Recibí conforme{{ $l['parcial'] ? " los {$moneda} {$l['pagado_txt']} detallados arriba" : '' }}
                    </div>
                    <div class="quien mono">{{ $l['nombre'] }}@if ($l['dui']) · {{ \App\Models\Planilla\PlanillaEmpleado::DOCUMENTO_TIPOS[$l['documento_tipo'] ?? 'dui'] }} {{ $l['dui'] }}@endif</div>
                </div>
                @if ($vac || $ind)
                    {{-- Vacaciones e indemnización: firma la empleadora y pone su sello encima de
                         la firma (Melqui, 06/11/2026: sin recuadro aparte para el sello). --}}
                    <div class="bloque">
                        <div class="linea"></div>
                        <div class="txt">Firma y sello de la empleadora</div>
                        <div class="quien mono">{{ $empleadora ?: $negocio }}</div>
                    </div>
                @else
                    <div class="bloque">
                        <div class="linea"></div>
                        <div class="txt">Entregó el pago</div>
                        @if ($l['entrego'])
                            <div class="quien mono">{{ $l['entrego'] }}</div>
                            <div class="fuente">quien entregó este pago según el registro</div>
                        @endif
                    </div>
                @endif
            </div>
        @endunless

        <p class="hi-legal">
            @if ($ind)
                Control interno de remuneraciones con importes revisados. Este documento no sustituye
                la Planilla Única. No incluye aguinaldo ni vacaciones proporcionales.
            @else
                Control interno de remuneraciones con importes revisados. ISSS, AFP y renta son una
                propuesta del sistema revisada por una persona, y este documento no sustituye la Planilla
                Única. No incluye aporte patronal, indemnización ni horas extra.
            @endif
            @if ($esBorrador)
                La planilla no está confirmada y no se ha pagado nada: este documento
                <strong>no lleva espacio para firmar</strong> ni declara recibido.
            @endif
        </p>
    </div>
@endforeach
