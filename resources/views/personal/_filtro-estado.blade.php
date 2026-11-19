{{-- El «Estado» de las listas de Personal (Empleados y Accesos), igual en las dos. Abren con
     los activos (18/11/2026): quien se fue se ve con «Inactivos» o «Todos», y no se borra nada.
     El controlador toma cualquier otro valor como «Activos», salvo el `activo=` vacío en Empleados:
     era el «Todos» del filtro viejo y se sigue tomando así, como en Clientes. Recibe `$activo`:
     '1', '0' o 'todos'. --}}
<div>
    <label for="activo" class="block text-xs font-medium text-gray-500 dark:text-paper-300">Estado</label>
    <select id="activo" name="activo" class="mt-1 block min-h-11 rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @foreach (['1' => 'Activos', '0' => 'Inactivos', 'todos' => 'Todos'] as $valor => $rotulo)
            <option value="{{ $valor }}" @selected($activo === (string) $valor)>{{ $rotulo }}</option>
        @endforeach
    </select>
</div>
