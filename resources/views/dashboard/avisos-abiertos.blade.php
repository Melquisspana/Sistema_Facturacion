{{--
    Avisos abiertos del sistema (App\Services\Alertas\Avisador::abiertos), solo con el
    permiso de Salud del sistema. Misma caja roja que «Atención inmediata», pero sin
    role="alert": ya hay uno arriba y dos seguidos se leerían en voz alta en cada carga.
    El modo oscuro lo resuelve app.css. Sin avisos, la caja no se dibuja.
--}}
@if ($avisosAbiertos)
    @if ($avisosAbiertos['avisos'] !== [])
        <section aria-labelledby="titulo-avisos" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <h2 id="titulo-avisos" class="font-semibold">Avisos abiertos ({{ count($avisosAbiertos['avisos']) }})</h2>
            <ul class="mt-2 space-y-3">
                @foreach ($avisosAbiertos['avisos'] as $aviso)
                    <li>
                        <p class="font-semibold">{{ $aviso['titulo'] }}</p>
                        @if ($aviso['detalle'] !== '')
                            <p class="mt-0.5">{{ $aviso['detalle'] }}</p>
                        @endif
                        <p class="mt-0.5">Desde {{ $aviso['desde']->format('d/m/Y H:i') }}</p>
                        @if ($aviso['que_hacer'] !== '')
                            <p class="mt-0.5">{{ $aviso['que_hacer'] }}</p>
                        @endif
                        @unless ($aviso['correo_salio'])
                            <p class="mt-0.5">El correo de este aviso todavía no salió.</p>
                        @endunless
                        @if ($aviso['enlace'])
                            <a href="{{ $aviso['enlace'] }}" class="mt-1 inline-flex min-h-11 items-center font-medium underline">Revisar<span class="sr-only">: {{ $aviso['titulo'] }}</span></a>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-2">Se quitan solos cuando se arregla. También llegan por correo.</p>
        </section>
    @endif
    @if ($avisosAbiertos['estado'] !== null)
        <p class="text-sm text-gray-500">
            {{ match ($avisosAbiertos['estado']) {
                'apagado' => 'Los avisos están apagados: Configuración › Módulos › Avisos de fallas.',
                'sin_correo' => 'Los avisos no tienen a quién escribir: cargá un correo en Configuración › Módulos › Avisos de fallas.',
                default => 'Los avisos no se revisaron en los últimos 15 minutos: puede faltar alguno.',
            } }}
        </p>
    @endif
@endif
