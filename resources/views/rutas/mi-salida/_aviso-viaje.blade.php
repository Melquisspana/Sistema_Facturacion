{{-- «Tu viaje del … sigue abierto» (16/11/2026): arriba de Inicio y de Sacar pedido cuando el
     viaje sigue sumando pero su último pedido o entrega es de otro día (lo decide el servidor:
     Viajes::preguntarSiSigue). «Sí, sigo» no cambia nada; «No, empiezo uno nuevo» es «Terminar
     el día» con «No, vuelvo». Los mismos botones que esa pregunta. Pide $viaje y $preguntarSiSigue. --}}
@if ($preguntarSiSigue && $viaje)
    @can('terminarDia', $viaje)
        <form method="POST" action="{{ route('rutas.mi-salida.sigue-viaje', $viaje) }}"
              class="mt-5 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-ink-800 dark:ring-ink-600" aria-labelledby="aviso-viaje-titulo">
            @csrf
            <p id="aviso-viaje-titulo" class="font-semibold text-amber-800 dark:text-amber-300">
                Tu viaje del {{ $viaje->fecha_inicio->copy()->locale('es')->dayName }} {{ $viaje->fecha_inicio->format('d/m') }} sigue abierto. ¿Seguís en ese viaje?
            </p>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button name="sigue" value="1" class="inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-3 text-center text-sm font-semibold text-white hover:bg-indigo-700">Sí, sigo</button>
                <button name="sigue" value="0" class="inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">No, empiezo uno nuevo</button>
            </div>
        </form>
    @endcan
@endif
