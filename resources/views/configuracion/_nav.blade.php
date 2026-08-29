@php
    /**
     * FUENTE ÚNICA del índice del Centro de Configuración. Ninguna vista dibuja
     * este HTML por su cuenta: todas lo reciben a través de <x-configuracion-layout>.
     *
     * POR QUÉ UN ÍNDICE AGRUPADO Y NO PESTAÑAS. Con seis secciones las pestañas
     * cabían; con las que vienen (integraciones, módulos, sistema) no caben en
     * ninguna pantalla, y una barra que se desplaza en horizontal esconde
     * justamente lo que el usuario todavía no sabe que existe. Un índice agrupado
     * crece hacia abajo, que es la dirección en la que sobra sitio.
     *
     * Los grupos son los del mapa acordado (General, Facturación electrónica,
     * Correo, y más adelante Integraciones / Módulos / Sistema). SOLO se dibujan
     * los que tienen pantallas reales: un grupo vacío o un enlace a una página que
     * todavía no existe enseña al usuario a desconfiar del resto del índice.
     */
    $grupos = [
        [
            'titulo' => null,
            'items' => [
                ['ruta' => 'configuracion.resumen', 'patron' => 'configuracion.resumen', 'titulo' => 'Resumen'],
            ],
        ],
        [
            'titulo' => 'General',
            'items' => [
                ['ruta' => 'configuracion.empresa.edit', 'patron' => 'configuracion.empresa.*', 'titulo' => 'Empresa emisora'],
                ['ruta' => 'configuracion.establecimientos.index', 'patron' => 'configuracion.establecimientos.*', 'titulo' => 'Establecimientos'],
                ['ruta' => 'configuracion.puntos-venta.index', 'patron' => 'configuracion.puntos-venta.*', 'titulo' => 'Puntos de venta'],
            ],
        ],
        [
            'titulo' => 'Facturación electrónica',
            'items' => [
                ['ruta' => 'configuracion.fiscal.hacienda', 'patron' => 'configuracion.fiscal.hacienda*', 'titulo' => 'Hacienda / API'],
                ['ruta' => 'configuracion.fiscal.firmador', 'patron' => 'configuracion.fiscal.firmador*', 'titulo' => 'Certificado y firmador'],
                ['ruta' => 'configuracion.correlativos.index', 'patron' => 'configuracion.correlativos.*', 'titulo' => 'Correlativos'],
                ['ruta' => 'configuracion.fiscal.parametros', 'patron' => 'configuracion.fiscal.parametros*', 'titulo' => 'Parámetros fiscales'],
                ['ruta' => 'configuracion.fiscal.invalidacion', 'patron' => 'configuracion.fiscal.invalidacion*', 'titulo' => 'Invalidación'],
            ],
        ],
        [
            'titulo' => 'Correo',
            'items' => [
                ['ruta' => 'configuracion.correo.edit', 'patron' => 'configuracion.correo.*', 'titulo' => 'Correo y servidor'],
                ['ruta' => 'configuracion.contabilidad.edit', 'patron' => 'configuracion.contabilidad.*', 'titulo' => 'Contabilidad'],
            ],
        ],
        [
            'titulo' => 'Integraciones',
            'items' => [
                ['ruta' => 'configuracion.integraciones.gmail', 'patron' => 'configuracion.integraciones.gmail*', 'titulo' => 'Gmail / Pronto pago'],
                ['ruta' => 'configuracion.integraciones.documentos-recibidos', 'patron' => 'configuracion.integraciones.documentos-recibidos*', 'titulo' => 'Buzón de compras'],
            ],
        ],
        [
            'titulo' => 'Sistema',
            'items' => [
                ['ruta' => 'configuracion.sistema', 'patron' => 'configuracion.sistema*', 'titulo' => 'Respaldos y estado'],
            ],
        ],
    ];
@endphp

{{-- RESPONSIVE SIN DUPLICAR MARCADO: el mismo <nav> es una lista de pastillas que
     se envuelven en móvil (flex-wrap: nunca hay desplazamiento horizontal) y un
     índice vertical agrupado a partir de lg. Los títulos de grupo se ocultan en
     móvil porque ahí no hay sitio para jerarquía, no porque sobren. --}}
<nav aria-label="Secciones de configuración" class="lg:h-full lg:border-r lg:border-gray-200">
    <div class="flex flex-wrap gap-1 border-b border-gray-200 p-3 lg:block lg:space-y-4 lg:border-b-0 lg:p-4">
        @foreach ($grupos as $grupo)
            <div class="contents lg:block">
                @if ($grupo['titulo'])
                    <p class="hidden lg:block lg:px-3 lg:pb-1 lg:text-xs lg:font-semibold lg:uppercase lg:tracking-wide lg:text-gray-400">
                        {{ $grupo['titulo'] }}
                    </p>
                @endif

                @foreach ($grupo['items'] as $item)
                    @php $activo = request()->routeIs($item['patron']); @endphp
                    <a href="{{ route($item['ruta']) }}"
                       @if ($activo) aria-current="page" @endif
                       class="block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition lg:mt-0.5 {{ $activo
                           ? 'bg-indigo-50 text-indigo-700'
                           : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900' }}">
                        {{ $item['titulo'] }}
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>
</nav>
