@props([
    'titulo',
    'icono' => null,
    'activo' => false,
    'clave' => null,
])
{{--
    Grupo del sidebar. Dos modos, según se pase o no `clave`:

      - SIN `clave`: encabezado estático, exactamente el de siempre. Para grupos
        de un solo enlace (Inicio, Resumen, Sistema), donde colapsar no aporta.
      - CON `clave`: grupo COLAPSABLE. `clave` identifica el grupo en localStorage
        para recordar abierto/cerrado entre páginas.

    Reglas deliberadas:

      1. `activo` —lo calcula quien incluye el grupo, con request()->routeIs()—
         fuerza el grupo ABIERTO al cargar, pase lo que pase en localStorage. La
         página en la que estás nunca queda escondida dentro de un grupo cerrado.
      2. Sin preferencia guardada el grupo nace ABIERTO: la navegación se ve igual
         que antes de que existieran los colapsables, y cerrar es una decisión del
         usuario, no el estado por defecto. Antes de que Alpine arranque el panel
         también está visible, así que nunca se pierde un enlace por JS lento.
      3. Esto es PRESENTACIÓN. Quién VE cada grupo lo decide el @if/@can de quien
         lo incluye; quién puede ENTRAR lo decide el middleware de cada ruta.
--}}
@php
    // Mismo título de grupo de siempre: letra pequeña, mayúsculas espaciadas y
    // contraste deliberadamente MENOR que el de un enlace (la categoría orienta,
    // el enlace es lo que se clickea).
    $claseTitulo = 'mb-1.5 flex w-full items-center gap-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-paper-500';
    $claveAlmacen = 'dlg-nav:'.$clave;
    $idPanel = 'sidebar-grupo-'.$clave;
@endphp

<div @if ($clave)
        x-data="{
            abierto: @js((bool) $activo) || (localStorage.getItem(@js($claveAlmacen)) ?? '1') === '1',
            alternar() {
                this.abierto = ! this.abierto;
                localStorage.setItem(@js($claveAlmacen), this.abierto ? '1' : '0');
            },
        }"
    @endif>

    @if ($clave)
        <button type="button" @click="alternar()"
                :aria-expanded="abierto ? 'true' : 'false'" aria-controls="{{ $idPanel }}"
                class="{{ $claseTitulo }} rounded transition hover:text-gray-600 dark:hover:text-paper-300">
            @if ($icono)
                <x-sidebar-icon :name="$icono" />
            @endif
            <span>{{ $titulo }}</span>
            <svg class="ms-auto h-3.5 w-3.5 shrink-0 transition-transform duration-150"
                 :class="abierto ? 'rotate-180' : ''"
                 xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
        </button>

        <div id="{{ $idPanel }}" x-show="abierto" class="space-y-0.5">
            {{ $slot }}
        </div>
    @else
        <p class="{{ $claseTitulo }}">
            @if ($icono)
                <x-sidebar-icon :name="$icono" />
            @endif
            <span>{{ $titulo }}</span>
        </p>

        <div class="space-y-0.5">
            {{ $slot }}
        </div>
    @endif
</div>
