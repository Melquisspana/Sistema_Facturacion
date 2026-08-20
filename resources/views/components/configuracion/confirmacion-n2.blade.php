@props([
    'titulo',
    'consecuencia',
    'cambios' => [],
    'accion',
    'metodo' => 'PUT',
    'campos' => [],
    'volver',
    'etiquetaConfirmar' => 'Confirmar y guardar',
])
{{--
    CONFIRMACIÓN N2 — componente reutilizable.

    Para cambios de impacto alto que NO son fiscales. Enseña qué va a cambiar,
    explica la consecuencia y exige un segundo clic. No pide frase exacta ni
    contraseña: para el puerto del servidor de correo eso no añadiría seguridad,
    añadiría gente escribiendo su contraseña sin leer. La ceremonia fuerte
    (frase + reautenticación) es N3 y vive en App\Ajustes\Ceremonias\CeremoniaN3.

    LOS SECRETOS NO PASAN POR AQUÍ. `$cambios` son objetos CambioPropuesto: los de
    tipo secreto se construyen sin `antes` ni `despues` y solo aportan la frase
    «... será reemplazada». Y `$campos` —los valores que se reenvían para poder
    guardarlos tras confirmar— NUNCA debe incluir un secreto: quien lo llame es
    responsable de eso, y por ese motivo exacto la contraseña SMTP tiene su propia
    pantalla en vez de pasar por esta confirmación.

    `$campos` viaja en inputs ocultos, pero NO decide nada: qué ajuste se escribe lo
    determina el mapa fijo del controlador, no el nombre que llegue del navegador.
--}}
<div class="mx-auto max-w-2xl space-y-5">
    <div class="rounded-md border border-amber-200 bg-amber-50 p-4">
        <h2 class="text-sm font-semibold text-amber-800">{{ $titulo }}</h2>
        <p class="mt-1 text-sm text-amber-800">{{ $consecuencia }}</p>
    </div>

    <div>
        <h3 class="text-sm font-medium text-gray-700">Esto es lo que va a cambiar</h3>

        <ul class="mt-2 divide-y divide-gray-100 rounded-md border border-gray-200">
            @foreach ($cambios as $cambio)
                <li class="px-4 py-3 text-sm">
                    <p class="font-medium text-gray-800">{{ $cambio->etiqueta }}</p>

                    @if ($cambio->esSecreto)
                        {{-- Ni el valor anterior ni el nuevo. Solo el hecho. --}}
                        <p class="mt-0.5 text-gray-500">{{ $cambio->descripcion }}</p>
                    @else
                        <p class="mt-0.5 text-gray-500">
                            <span class="line-through">{{ $cambio->antes }}</span>
                            <span class="mx-1">&rarr;</span>
                            <span class="font-medium text-gray-800">{{ $cambio->despues }}</span>
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-3">
        <a href="{{ $volver }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            Cancelar
        </a>

        <form method="POST" action="{{ $accion }}">
            @csrf
            @method($metodo)

            @foreach ($campos as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach

            {{-- La marca que distingue "quiero cambiar esto" de "ya lo confirmé".
                 Sin ella el controlador vuelve a mostrar esta misma pantalla. --}}
            <input type="hidden" name="confirmacion" value="1">

            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                {{ $etiquetaConfirmar }}
            </button>
        </form>
    </div>
</div>
