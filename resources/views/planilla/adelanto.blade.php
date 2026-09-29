<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Comprobante de adelanto</h1>
                <p class="text-sm text-gray-500">{{ $anticipo->empleado->nombre }} · {{ $anticipo->fecha->format('d/m/Y') }}</p>
            </div>
            <a href="{{ route('planilla.anticipos') }}"
               class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Volver</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-3">

            <div class="no-imprimir rounded-md border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-600">
                Dos por hoja con su línea de corte: uno para la persona y otro para el archivo. El
                dinero de este adelanto ya está registrado <strong>una sola vez</strong>; descontarlo
                después en una quincena no vuelve a moverlo.
            </div>

            @include('planilla.partials.impresos-estilo')

            <div class="hoja-impresa">
                {{-- Original y copia son el mismo bloque, para que no puedan discrepar. --}}
                @foreach (['', ' · copia'] as $sufijo)
                    @include('planilla.partials.membrete', [
                        'documento' => 'Comprobante de adelanto',
                        'folio' => e($folio.$sufijo),
                    ])

                    <div class="hi-persona" style="display:flex; justify-content:space-between; align-items:flex-end; gap:8mm; flex-wrap:wrap">
                        <div>
                            <div class="nombre">{{ $anticipo->empleado->nombre }}</div>
                            @if ($anticipo->empleado->dui)
                                <div class="dui mono">DUI {{ $anticipo->empleado->dui }}</div>
                            @endif
                        </div>
                        <div style="text-align:right">
                            <span class="et" style="font-size:6.4pt; letter-spacing:.14em; text-transform:uppercase; color:#5c5c5c; display:block">Fecha de entrega</span>
                            <span class="mono" style="font-size:10.5pt">{{ $anticipo->fecha->format('d/m/Y') }}</span>
                        </div>
                    </div>

                    <div class="hi-monto">
                        <span class="et">Monto entregado</span>
                        <span class="cifra mono">{{ $anticipo->moneda }} {{ $resta['este_txt'] }}</span>
                    </div>

                    @if ($sufijo === '')
                        <p class="hi-condicion">
                            Recibí de <strong>{{ $negocio }}</strong> la cantidad detallada arriba en concepto de
                            <strong>adelanto</strong>, que se me descontará de mi pago de quincena.
                        </p>
                    @endif

                    {{-- La resta acordada: saldo PENDIENTE anterior + este adelanto.
                         No la suma de lo adelantado alguna vez: eso le reclamaría a la
                         persona dinero que ya devolvió. --}}
                    <div class="hi-saldo">
                        <div>
                            <span class="et">Saldo pendiente anterior</span>
                            <span class="val mono">{{ $resta['anterior_txt'] }}</span>
                        </div>
                        <div>
                            <span class="et">Este adelanto</span>
                            <span class="val mono">{{ $resta['este_txt'] }}</span>
                        </div>
                        <div>
                            <span class="et">Nuevo saldo por descontar</span>
                            <span class="val mono">{{ $resta['nuevo_txt'] }}</span>
                        </div>
                    </div>

                    <div class="hi-firmas">
                        <div class="bloque">
                            <div class="linea"></div>
                            <div class="txt">Recibí conforme</div>
                            <div class="quien mono">
                                {{ $anticipo->empleado->nombre }}@if ($anticipo->empleado->dui) · DUI {{ $anticipo->empleado->dui }}@endif
                            </div>
                        </div>
                        <div class="bloque">
                            <div class="linea"></div>
                            <div class="txt">Entregó el adelanto</div>
                            @if ($entrego)
                                <div class="quien mono">{{ $entrego }}</div>
                                @if ($sufijo === '')
                                    <div class="fuente">quien lo entregó según el registro</div>
                                @endif
                            @endif
                        </div>
                    </div>

                    @if ($sufijo === '')
                        <p class="hi-legal">
                            Este comprobante corresponde a un adelanto registrado una sola vez. El dinero sale
                            acá y se recupera descontándolo de una quincena; no se vuelve a registrar al
                            descontarlo.
                        </p>
                        <div class="hi-tijera"><span>Copia para el archivo</span></div>
                        <div style="height:6mm"></div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
