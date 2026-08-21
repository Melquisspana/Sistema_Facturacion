@props(['empleado'])
{{-- Campos de una persona. Compartido por alta y edición para que las dos
     pantallas no se separen con el tiempo.

     NO hay campo de estado ni de usuario del sistema:
      - activar/desactivar es un acto aparte, con su propio botón y su propia
        línea de auditoría, no un checkbox perdido entre nombres;
      - `user_id` no se toca desde acá: atar a una persona con una cuenta del
        sistema la ataría también a sus permisos fiscales. --}}
<div class="grid gap-5 sm:grid-cols-2">
    <div>
        <label for="nombres" class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombres</label>
        <input id="nombres" name="nombres" type="text" required maxlength="80"
               value="{{ old('nombres', $empleado->nombres) }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('nombres') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="apellidos" class="block text-sm font-medium text-gray-700 dark:text-paper-200">Apellidos</label>
        <input id="apellidos" name="apellidos" type="text" required maxlength="80"
               value="{{ old('apellidos', $empleado->apellidos) }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        @error('apellidos') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="codigo" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
            Código de planilla <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
        </label>
        <input id="codigo" name="codigo" type="text" maxlength="30"
               value="{{ old('codigo', $empleado->codigo) }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">El número con el que Recursos Humanos ya identifica a esta persona.</p>
        @error('codigo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="fecha_ingreso" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
            Fecha de ingreso <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
        </label>
        <input id="fecha_ingreso" name="fecha_ingreso" type="date"
               value="{{ old('fecha_ingreso', $empleado->fecha_ingreso?->format('Y-m-d')) }}"
               class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-500 dark:text-paper-400">Cuesta rellenarla hacia atrás; si la sabés, ponela ahora.</p>
        @error('fecha_ingreso') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
</div>
