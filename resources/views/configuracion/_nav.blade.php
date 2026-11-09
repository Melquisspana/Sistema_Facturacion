@php
    /**
     * FUENTE ÚNICA del índice del Centro de Configuración. Ninguna vista dibuja
     * este HTML por su cuenta: todas lo reciben a través de <x-configuracion-layout>.
     *
     * POR QUÉ UN ÍNDICE AGRUPADO Y NO PESTAÑAS. Con seis secciones las pestañas
     * cabían; con las que vinieron (integraciones, módulos, sistema) no caben en
     * ninguna pantalla, y una barra que se desplaza en horizontal esconde
     * justamente lo que el usuario todavía no sabe que existe. Un índice agrupado
     * crece hacia abajo, que es la dirección en la que sobra sitio.
     *
     * Los grupos son los del mapa acordado (General, Módulos, Facturación
     * electrónica, Correo, Integraciones, Sistema y Administración). SOLO se
     * dibujan los que tienen pantallas reales y que el usuario puede abrir: un
     * grupo vacío o un enlace que da 403 enseña al usuario a desconfiar del resto.
     *
     * VISIBILIDAD POR PERMISO, igual que las rutas. Todo el centro va con
     * `configuracion.gestionar`; «Salud del sistema» y el grupo «Administración»
     * tienen su propio permiso (sistema.salud, auditoria.ver,
     * importaciones.gestionar). Por eso quien solo tiene `auditoria.ver`
     * (contabilidad) entra a Auditoría por este mismo marco y ve únicamente lo suyo,
     * sin ninguna puerta de configuración. Ocultar no autoriza: cada ruta lleva su
     * middleware.
     */
    $usuario = auth()->user();
    $configura = $usuario->can('configuracion.gestionar');

    $grupos = $configura ? [
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
            'titulo' => 'Módulos',
            'items' => [
                ['ruta' => 'configuracion.modulos', 'patron' => 'configuracion.modulos*', 'titulo' => 'Prender y apagar'],
                // Opciones de cada módulo. La de un módulo apagado no se dibuja (y su
                // ruta responde 404): visible() solo mira el interruptor, sin tablas.
                ...array_values(array_map(
                    static fn (string $pagina) => [
                        'ruta' => 'configuracion.opciones.'.$pagina,
                        'patron' => 'configuracion.opciones.'.$pagina.'*',
                        'titulo' => \App\Http\Controllers\Configuracion\OpcionesModuloController::titulo($pagina),
                    ],
                    array_filter(
                        \App\Http\Controllers\Configuracion\OpcionesModuloController::paginas(),
                        static fn (string $pagina) => \App\Http\Controllers\Configuracion\OpcionesModuloController::visible($pagina),
                    ),
                )),
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
                ['ruta' => 'configuracion.integraciones.buzon-adicional', 'patron' => 'configuracion.integraciones.buzon-adicional*', 'titulo' => 'Segundo buzón'],
                ...((\App\Enums\ModuloSistema::Asistencia->activo() && $usuario->can('asistencia.dispositivos.gestionar'))
                    ? [['ruta' => 'configuracion.lectores.index', 'patron' => 'configuracion.lectores.*', 'titulo' => 'Lectores de huella']]
                    : []),
            ],
        ],
    ] : [];

    // Atajos a lo que se configura DENTRO de Producción y de Gastos. Esas pantallas
    // se quedan en su módulo a propósito: si se mudaran acá exigirían
    // `configuracion.gestionar` y el rol Producción perdería sus catálogos. Por eso
    // no dependen de $configura sino de lo mismo que piden sus rutas: módulo
    // encendido y el permiso de la pantalla. Ninguna consulta a tablas del módulo;
    // la fase 2 de Gastos es una pregunta de esquema que el menú ya hizo y el
    // servicio (singleton) recuerda.
    if (\App\Enums\AreaSistema::Planta->habilitada() && $usuario->can('planta.ver') && $usuario->can('planta.catalogos.ver')) {
        $grupos[] = [
            'titulo' => 'Producción',
            'items' => array_map(
                static fn (string $ruta, string $titulo) => ['ruta' => 'planta.'.$ruta.'.index', 'patron' => 'planta.'.$ruta.'.*', 'titulo' => $titulo],
                ['insumos', 'lotes', 'proveedores', 'ubicaciones', 'productos-base', 'presentaciones', 'empaques'],
                ['Insumos', 'Lotes', 'Proveedores', 'Ubicaciones', 'Productos base', 'Presentaciones', 'Configuración de empaque'],
            ),
        ];
    }

    if (\App\Enums\ModuloSistema::Gastos->activo() && $usuario->can('gastos.ver')
        && app(\App\Services\Gastos\InstalacionGastos::class)->fase2Instalada()) {
        $grupos[] = [
            'titulo' => 'Gastos',
            'items' => [
                ['ruta' => 'gastos.reglas.index', 'patron' => 'gastos.reglas.*', 'titulo' => 'Gastos que se repiten'],
            ],
        ];
    }

    // Sistema: lo editable de los respaldos y, aparte, «Salud del sistema», que salió
    // de la barra de Facturación con su contador de trabajos fallidos.
    $grupos[] = [
        'titulo' => 'Sistema',
        'items' => [
            ...($configura ? [['ruta' => 'configuracion.sistema', 'patron' => 'configuracion.sistema*', 'titulo' => 'Respaldos y estado']] : []),
            ...($usuario->can('sistema.salud')
                ? [['ruta' => 'admin.salud-sistema', 'patron' => 'admin.salud-sistema', 'titulo' => 'Salud del sistema', 'contador' => (int) ($jobsFallidos ?? 0)]]
                : []),
        ],
    ];

    // Administración: el rastro (Auditoría) y las cargas masivas (Importaciones), que
    // vivían en la barra de Facturación. «Accesos externos» lleva a las cuentas de gente
    // de afuera (la contadora, solo con rol Contabilidad), que no tienen ficha en
    // Personal; el acceso de los empleados se da en su ficha. Solo mira el permiso.
    $grupos[] = [
        'titulo' => 'Administración',
        'items' => [
            ...($usuario->can('auditoria.ver') ? [['ruta' => 'auditoria.index', 'patron' => 'auditoria.*', 'titulo' => 'Auditoría']] : []),
            ...($usuario->can('importaciones.gestionar') ? [['ruta' => 'importaciones.index', 'patron' => 'importaciones.*', 'titulo' => 'Importaciones']] : []),
            ...($usuario->can('usuarios.gestionar')
                ? [['ruta' => 'personal.accesos.index', 'parametros' => ['externas' => 1], 'patron' => 'personal.accesos.*', 'titulo' => 'Accesos externos']]
                : []),
        ],
    ];

    // Un grupo sin enlaces no se dibuja.
    $grupos = array_values(array_filter($grupos, static fn (array $g) => $g['items'] !== []));
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
                    <a href="{{ route($item['ruta'], $item['parametros'] ?? []) }}"
                       @if ($activo) aria-current="page" @endif
                       class="block whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition lg:mt-0.5 {{ $activo
                           ? 'bg-indigo-50 text-indigo-700'
                           : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900' }}">
                        {{ $item['titulo'] }}
                        @if (($item['contador'] ?? 0) > 0)
                            <span class="ms-1 inline-flex items-center rounded-full bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700"
                                  title="{{ $item['contador'] }} trabajos en cola fallidos (correos/DTE). Revisá Salud del sistema.">
                                {{ $item['contador'] }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>
</nav>
