{{-- La historia de una sala en «Clientes» (Melqui, 28/11/2026): sus pedidos y sus CCF, del más
     nuevo al más viejo, de a 10 con «Ver más». Los pedidos dicen quién los sacó y cuántas cajas
     de cada producto; los CCF, su número, la fecha, si se entregó y si está por cobrar. SIN MONTOS
     en la pantalla. Cada CCF se abre en PDF (con montos, aprobado) para verlo, descargarlo o
     imprimirlo, y «Compartir» abre el menú de compartir del teléfono con el archivo, para mandarlo
     por WhatsApp; donde no se puede compartir, lo descarga.

     «Compartir» es Alpine escrito acá (el JS de producción no se recompila): baja el PDF de la
     misma ruta y llama a navigator.share con el archivo. Si el teléfono ya no deja compartir
     después de bajarlo (Safari pide el toque fresco), el archivo queda listo y se pide tocar otra
     vez. Si se cerró la sesión o el PDF ya no está, lo dice; sin señal, también. Los avisos van en
     cajas que siempre están (role="alert" el error, role="status" el «tocá otra vez»), así el
     lector de pantalla los lee al cambiar. Sin JavaScript el botón no se ve y «Ver PDF» anda igual.

     En el celular, arriba van dos atajos («Pedidos» y «CCF»): para cobrar no hace falta pasar
     los 10 pedidos. «Ver más» lleva al primero de los nuevos. Los saltos dejan 5rem arriba
     (scroll-margin-top en línea: el CSS de producción solo trae scroll-mt-6) para que la barra
     fija de arriba no tape el título.

     Variables: $sala, $zona, $pedidos y $ccf (los más nuevos primero), $masPedidos y $masCcf,
     $entregas (dte_id => entrega, PanelClientes), $porCobrar (dte_id), $paginasPedidos y
     $paginasCcf (cuántas vueltas de 10 se ven). «Ver pedido» lleva al detalle de la oficina:
     solo con `rutas.ver`.

     El marco depende de quién entra: la oficina (`rutas.ver`) lo ve con la barra lateral
     (rutas.clientes.marco-oficina) y el vendedor, que tiene solo `rutas.pedidos.ver`
     (28/11/2026), en su layout del teléfono, con «Clientes» marcado en su menú y el
     encabezado arriba del contenido. --}}
@extends(auth()->user()->can('rutas.ver') ? 'rutas.clientes.marco-oficina' : 'layouts.vendedor')

@section('titulo', $sala->nombre)

@section('encabezado')
    <div class="flex flex-wrap items-center gap-2">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">{{ $sala->nombre }}</h1>
        @unless ($sala->activo)
            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">Desactivada</span>
        @endunless
    </div>
    <a href="{{ route('rutas.clientes.index') }}"
       class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver a clientes</a>
@endsection

@section('content')
    @cannot('rutas.ver')
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">@yield('encabezado')</div>
    @endcannot

    @php
        $secundario = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
        $caja = 'rounded-lg border border-gray-200 bg-white p-4';
        $titulo = 'text-xs font-semibold uppercase tracking-wide text-gray-500';
        $dt = 'text-xs font-medium text-gray-500';
        $dd = 'mt-0.5 text-sm text-gray-800';
        $cuantosPorCobrar = count($porCobrar);
    @endphp

    <div class="space-y-6">
            {{-- Desactivada (28/11/2026): no sale en la lista ni se le sacan pedidos; su historia se sigue consultando. --}}
            @unless ($sala->activo)
                <p class="text-sm text-gray-600">Esta sala está desactivada: no sale en la lista y no se le sacan pedidos. Su historia queda para consultar.</p>
            @endunless

            <dl class="{{ $caja }} grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div>
                    <dt class="{{ $dt }}">Código</dt>
                    <dd class="{{ $dd }}">{{ $sala->codigo ?: 'Sin código' }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">Zona</dt>
                    <dd class="{{ $dd }}">{{ $zona?->nombre ?? 'Sin zona' }}</dd>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <dt class="{{ $dt }}">Facturas</dt>
                    <dd class="{{ $dd }}">{{ $cuantosPorCobrar === 0 ? 'Nada por cobrar' : $cuantosPorCobrar.' CCF por cobrar' }}</dd>
                </div>
            </dl>

            {{-- Atajos del celular: en la computadora las dos columnas ya se ven juntas. --}}
            <nav aria-label="Ir a" class="grid grid-cols-2 gap-2 lg:hidden">
                <a href="#pedidos" class="{{ $secundario }}">Pedidos</a>
                <a href="#ccf" class="{{ $secundario }}">CCF</a>
            </nav>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- ===================== Pedidos ===================== --}}
                <section id="pedidos" aria-labelledby="pedidos-titulo" style="scroll-margin-top: 5rem">
                    <h2 id="pedidos-titulo" class="{{ $titulo }}">Pedidos</h2>
                    <ul class="mt-3 space-y-3">
                        @forelse ($pedidos as $pedido)
                            <li id="pedido-{{ $loop->iteration }}" class="{{ $caja }}" style="scroll-margin-top: 5rem">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <p class="text-sm font-medium text-gray-800">{{ $pedido->fecha->format('d/m/Y') }} · {{ $pedido->sacadoPor?->nombre ?? 'Sin vendedor' }}</p>
                                    <x-rutas.estado-badge :estado="$pedido->estado" />
                                </div>
                                @include('rutas.clientes._lineas', ['pedido' => $pedido, 'tope' => null])
                                @can('rutas.ver')
                                    <a href="{{ route('rutas.pedidos.show', $pedido) }}" class="mt-1 inline-flex min-h-11 items-center text-sm text-indigo-600 hover:underline">Ver pedido<span class="sr-only"> del {{ $pedido->fecha->format('d/m/Y') }}</span></a>
                                @endcan
                            </li>
                        @empty
                            <li class="{{ $caja }} py-10 text-center text-gray-500">Todavía no tiene pedidos.</li>
                        @endforelse
                    </ul>
                    @if ($masPedidos)
                        <a href="{{ route('rutas.clientes.show', [$sala, 'pedidos' => $paginasPedidos + 1, 'ccf' => $paginasCcf]) }}#pedido-{{ $pedidos->count() + 1 }}" class="{{ $secundario }} mt-3 w-full">Ver más pedidos</a>
                    @endif
                </section>

                {{-- ===================== CCF ===================== --}}
                <section id="ccf" aria-labelledby="ccf-titulo" style="scroll-margin-top: 5rem">
                    <h2 id="ccf-titulo" class="{{ $titulo }}">CCF</h2>
                    <ul class="mt-3 space-y-3">
                        @forelse ($ccf as $dte)
                            @php
                                $url = route('rutas.clientes.ccf', [$sala, $dte]);
                                $corto = 'CCF …'.\App\Support\OrdenCompra::ultimosDigitos($dte->numero_control);
                            @endphp
                            <li id="ccf-{{ $loop->iteration }}" class="{{ $caja }}" style="scroll-margin-top: 5rem" x-data="compartirPdf(@js($url), @js('CCF-'.$dte->numero_control.'.pdf'), @js($corto.' · '.$sala->nombre))">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <p class="font-mono text-sm font-medium text-gray-800" title="{{ $dte->numero_control }}">
                                        <span aria-hidden="true">{{ $corto }}</span>
                                        <span class="sr-only">CCF {{ $dte->numero_control }}</span>
                                    </p>
                                    <p class="text-sm text-gray-600">{{ $dte->fecha_emision?->format('d/m/Y') }}</p>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    @include('rutas.clientes._entrega', ['entrega' => $entregas[$dte->id]])
                                    @if (in_array($dte->id, $porCobrar, true))
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Por cobrar</span>
                                    @endif
                                    @if ($dte->numero_orden_compra)
                                        <span class="break-all font-mono text-xs text-gray-500">OC {{ $dte->numero_orden_compra }}</span>
                                    @endif
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <a href="{{ $url }}" target="_blank" rel="noopener" class="{{ $secundario }}">Ver PDF<span class="sr-only"> del {{ $corto }}</span></a>
                                    <button type="button" x-cloak @click="compartir()" :disabled="ocupado" :aria-busy="ocupado ? 'true' : 'false'" class="{{ $secundario }}">
                                        <span x-text="ocupado ? 'Preparando…' : 'Compartir'">Compartir</span><span class="sr-only"> el {{ $corto }}</span>
                                    </button>
                                </div>
                                <div role="alert"><p x-cloak x-show="error" x-text="error" class="mt-2 text-sm text-red-600"></p></div>
                                <div role="status"><p x-cloak x-show="listo" x-text="listo" class="mt-2 text-sm text-gray-600"></p></div>
                            </li>
                        @empty
                            <li class="{{ $caja }} py-10 text-center text-gray-500">Todavía no tiene CCF.</li>
                        @endforelse
                    </ul>
                    @if ($masCcf)
                        <a href="{{ route('rutas.clientes.show', [$sala, 'pedidos' => $paginasPedidos, 'ccf' => $paginasCcf + 1]) }}#ccf-{{ $ccf->count() + 1 }}" class="{{ $secundario }} mt-3 w-full">Ver más CCF</a>
                    @endif
                </section>
            </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('compartirPdf', (url, nombre, titulo) => ({
                archivo: null,
                ocupado: false,
                error: '',
                listo: '',
                async compartir() {
                    if (this.ocupado) return;
                    this.error = '';
                    this.listo = '';
                    if (this.archivo) { this.mandar(); return; }
                    this.ocupado = true;
                    try {
                        let respuesta;
                        try {
                            respuesta = await fetch(url, { credentials: 'same-origin' });
                        } catch (sinSenal) {
                            this.error = 'No se pudo traer el PDF. Revisá la señal y probá de nuevo.';
                            return;
                        }
                        {{-- Con la sesión vencida, la respuesta es la página de entrar (HTML), no el PDF. --}}
                        const esPdf = (respuesta.headers.get('Content-Type') || '').includes('pdf');
                        if (respuesta.redirected || [401, 403, 419].includes(respuesta.status) || (respuesta.ok && ! esPdf)) {
                            this.error = 'Se cerró la sesión. Volvé a entrar y probá de nuevo.';
                            return;
                        }
                        if (! respuesta.ok) {
                            this.error = 'Ese PDF ya no está. Recargá la página.';
                            return;
                        }
                        this.archivo = new File([await respuesta.blob()], nombre, { type: 'application/pdf' });
                    } catch (error) {
                        this.error = 'No se pudo traer el PDF. Revisá la señal y probá de nuevo.';
                        return;
                    } finally {
                        this.ocupado = false;
                    }
                    this.mandar();
                },
                {{-- El menú de compartir del teléfono; donde no hay, se descarga. --}}
                mandar() {
                    if (navigator.canShare && navigator.canShare({ files: [this.archivo] })) {
                        navigator.share({ files: [this.archivo], title: titulo }).catch((error) => {
                            {{-- Cerró el menú (AbortError) o ya había uno abierto (InvalidStateError): no se baja nada. --}}
                            if (error.name === 'NotAllowedError') this.listo = 'El PDF ya está listo: tocá «Compartir» otra vez.';
                            else if (error.name !== 'AbortError' && error.name !== 'InvalidStateError') this.bajar();
                        });
                    } else {
                        this.bajar();
                    }
                },
                bajar() {
                    const enlace = document.createElement('a');
                    enlace.href = URL.createObjectURL(this.archivo);
                    enlace.download = this.archivo.name;
                    document.body.appendChild(enlace);
                    enlace.click();
                    enlace.remove();
                    setTimeout(() => URL.revokeObjectURL(enlace.href), 60000);
                },
            }));
        });
    </script>
@endsection
