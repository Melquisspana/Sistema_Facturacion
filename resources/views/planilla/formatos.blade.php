@php
    // Los totales se calculan con el MISMO servicio que usa la planilla de verdad, y la
    // maquetación son las MISMAS partes que se imprimen. Si la vista previa usara otro
    // código, revisar el formato acá no probaría nada sobre el papel real.
    use App\Services\Gastos\Dinero;

    $lineas = [];
    $suma = ['total_ingresos' => 0, 'descuentos' => 0, 'a_pagar' => 0, 'pagado' => 0];

    foreach ($ficticia['lineas'] as $i => $l) {
        $conceptos = [];
        foreach ($l['ingresos'] as $x) {
            $conceptos[] = ['tipo' => 'ingreso', 'importe' => $x['importe']];
        }
        foreach ($l['descuentos'] as $x) {
            $conceptos[] = ['tipo' => 'descuento', 'importe' => $x['importe'], 'destino' => $x['destino'],
                'tercero' => $x['tercero'], 'referencia' => $x['referencia'] ?? null];
        }

        $t = $totales->linea($l['salario'], $conceptos);

        // La vista previa muestra los formatos COMO SI ya estuvieran pagados, porque es
        // así como se firman. Lo que se enseña es la maqueta, no un estado del sistema.
        $pagado = $l['pagado'] ?? $t['a_pagar'];

        $lineas[] = $l + [
            't' => $t + [
                'total_ingresos_txt' => Dinero::mostrar($t['total_ingresos']),
                'descuentos_txt' => Dinero::mostrar($t['descuentos']),
                'a_pagar_txt' => Dinero::mostrar($t['a_pagar']),
            ],
            'folio' => $ficticia['codigo'].'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            'periodo' => $l['periodo'] ?? $ficticia['periodo_corto'],
            'periodo_largo' => $l['periodo_largo'] ?? $ficticia['periodo_largo'],
            'periodo_propio' => isset($l['periodo']),
            'pagado' => $pagado,
            'pagado_txt' => Dinero::mostrar($pagado),
            'pendiente_txt' => Dinero::mostrar(max($t['a_pagar'] - $pagado, 0)),
            'parcial' => $pagado > 0 && $pagado < $t['a_pagar'],
            'fecha_pago' => $ficticia['fecha_pago'],
            'fecha_pago_propia' => $l['fecha_pago_propia'] ?? null,
            'entrego' => $l['entrego'] ?? $ficticia['entrego'],
        ];

        $suma['total_ingresos'] += $t['total_ingresos'];
        $suma['descuentos'] += $t['descuentos'];
        $suma['a_pagar'] += $t['a_pagar'];
        $suma['pagado'] += $pagado;
    }

    $suma += [
        'total_ingresos_txt' => Dinero::mostrar($suma['total_ingresos']),
        'descuentos_txt' => Dinero::mostrar($suma['descuentos']),
        'a_pagar_txt' => Dinero::mostrar($suma['a_pagar']),
        'pagado_txt' => Dinero::mostrar($suma['pagado']),
    ];

    $moneda = $ficticia['moneda'];
    $cabecera = [
        'periodo' => $ficticia['periodo'],
        'periodo_largo' => $ficticia['periodo_largo'],
        'fecha_pago' => $ficticia['fecha_pago'],
        'hoy' => now()->format('d/m/Y'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Formatos</h1>
            <nav class="flex gap-1 rounded-md border border-gray-200 bg-white p-1" aria-label="Formato">
                @foreach (['hoja' => 'Hoja para firmas', 'recibo' => 'Recibo individual'] as $clave => $texto)
                    <a href="{{ route('planilla.formatos', ['formato' => $clave]) }}"
                       @class(['min-h-11 rounded px-3 py-2 text-sm font-medium', 'bg-indigo-50 text-indigo-700' => $formato === $clave, 'text-gray-600 hover:bg-gray-50' => $formato !== $clave])>{{ $texto }}</a>
                @endforeach
            </nav>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-3">

            {{-- Se dice en grande y arriba del todo: esto es inventado. Una vista previa
                 de un formato de planilla es exactamente la clase de papel que alguien
                 podría imprimir y confundir con el real. --}}
            <div class="no-imprimir rounded-md border-2 border-dashed border-amber-400 bg-amber-50 px-4 py-2.5 text-sm text-amber-900" role="status">
                <strong>Vista previa con datos ficticios.</strong> Los nombres y los importes son inventados y no
                corresponden a ninguna persona. Sirve para revisar el formato sin abrir los sueldos de nadie.
            </div>

            @include('planilla.partials.impresos-estilo')

            @include('planilla.partials.'.($formato === 'hoja' ? 'hoja-firmas' : 'recibos'), [
                'cabecera' => $cabecera, 'lineas' => $lineas, 'suma' => $suma, 'moneda' => $moneda,
                'negocio' => $negocio, 'empleadora' => $empleadora, 'logo' => $logo,
                'entregaron' => $ficticia['entrego'], 'esBorrador' => false,
            ])

            <p class="text-xs text-gray-500">
                Los impresos reales de cada planilla salen desde su ficha, y ahí se adjunta el documento
                firmado.
            </p>
        </div>
    </div>
</x-app-layout>
