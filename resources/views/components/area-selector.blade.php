@props(['areas' => [], 'activa'])

{{-- Selector superior de ÁREAS de trabajo.

     Es PRESENTACIÓN PURA: dibuja las áreas que el usuario ya puede ver según sus
     permisos y el interruptor de cada módulo (App\Enums\AreaSistema::visiblesPara).
     No autoriza nada: entrar a un área la decide su middleware de backend, y
     escribir la URL a mano de un área ajena sigue dando 403 (o 404 si el módulo
     está apagado).

     Con una sola área visible NO se dibuja nada: los cuatro roles históricos y el
     rol de producción no ven ningún elemento nuevo en la barra superior.

     SOLO DESDE lg, y no desde sm como antes. Por debajo de lg la navegación es el
     panel lateral deslizante, y el cambio de área vive allí dentro
     (<x-area-selector-panel>). Antes este elemento era `hidden sm:block` y el panel
     no ofrecía nada: entre 0 y 640 px no había NINGUNA forma de cambiar de área.
     Ahora cada ancho tiene exactamente un selector, y ninguno depende del otro. --}}
@if (count($areas) > 1)
    <div class="hidden lg:block" data-area-selector>
        <x-dropdown align="left" width="48">
            <x-slot name="trigger">
                <button type="button" aria-label="Cambiar de área de trabajo"
                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-ink-600 dark:bg-transparent dark:text-paper-300 dark:hover:bg-ink-700 dark:hover:text-paper-100 dark:focus-visible:outline-indigo-400">
                    <x-sidebar-icon :name="$activa->icono()" />
                    <span>{{ $activa->label() }}</span>
                    <svg class="h-4 w-4 fill-current text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </x-slot>

            <x-slot name="content">
                @foreach ($areas as $area)
                    <x-dropdown-link :href="route($area->rutaInicio())"
                                     @class(['font-semibold text-indigo-700 dark:text-indigo-300' => $area === $activa])>
                        {{ $area->label() }}
                    </x-dropdown-link>
                @endforeach
            </x-slot>
        </x-dropdown>
    </div>
@endif
