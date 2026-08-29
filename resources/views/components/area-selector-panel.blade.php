@props(['areas' => [], 'activa'])

{{-- Cambio de ÁREA dentro del panel lateral (la navegación por debajo de lg).

     Es el gemelo de <x-area-selector>, que vive en la barra superior y solo se
     dibuja desde lg. Los dos leen la MISMA lista —$areasVisibles, es decir
     App\Enums\AreaSistema::visiblesPara(): módulo encendido Y permiso de entrada—
     así que ninguno de los dos puede ofrecer un área que el otro esconda.

     Por qué una lista y no un desplegable: acá el espacio es vertical y sobra, y
     un desplegable dentro de un panel que ya es un desplegable obliga a dos
     gestos para algo que se hace de un vistazo. Además una lista se recorre con
     Tab sin abrir nada.

     PRESENTACIÓN PURA. No autoriza: entrar a un área la decide el middleware de
     su grupo de rutas, y escribir a mano la URL de un área ajena sigue dando 403
     (o 404 si el módulo está apagado). Con una sola área visible no se dibuja
     nada, igual que en la barra superior. --}}
@if (count($areas) > 1)
    <nav aria-label="Áreas de trabajo"
         class="border-b border-gray-200 px-3 py-3 lg:hidden dark:border-ink-600">
        <p class="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500">
            Área
        </p>

        <ul class="space-y-0.5">
            @foreach ($areas as $area)
                @php $esActiva = $area === $activa; @endphp
                <li>
                    <a href="{{ route($area->rutaInicio()) }}"
                       @if ($esActiva) aria-current="true" @endif
                       class="ms-1 flex items-center gap-2 rounded-r-md border-l-2 px-3 py-1.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-400 {{ $esActiva
                           ? 'border-indigo-600 bg-indigo-50 font-semibold text-indigo-700 dark:border-indigo-400 dark:bg-indigo-500/15 dark:text-indigo-300'
                           : 'border-transparent text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-paper-300 dark:hover:bg-ink-700 dark:hover:text-paper-100' }}">
                        <x-sidebar-icon :name="$area->icono()" />
                        <span class="min-w-0 truncate">{{ $area->label() }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
