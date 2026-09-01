{{-- Campos compartidos por el alta y la edición de una persona de campo.

     NO incluye `activo`: activar o desactivar es un acto con su propio botón y su propia
     confirmación, no una casilla que se pisa sin querer al corregir un teléfono. --}}
@php
    $personal = $personal ?? null;
    $funcionesElegidas = old('funciones', $personal?->funcionesEnum()->map(fn ($f) => $f->value)->all() ?? []);
@endphp

<div>
    <label for="nombre" class="block text-sm font-medium text-gray-700 dark:text-paper-200">Nombre</label>
    <input type="text" name="nombre" id="nombre" required maxlength="120" autofocus
           value="{{ old('nombre', $personal->nombre ?? '') }}"
           class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
    <x-input-error :messages="$errors->get('nombre')" class="mt-1" />
</div>

<div>
    <span class="block text-sm font-medium text-gray-700 dark:text-paper-200">Funciones</span>
    <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">
        Qué se le puede pedir a esta persona. Son combinables y no otorgan permisos del sistema:
        quién puede pulsar qué botón se decide por rol, no acá.
    </p>

    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        @foreach ($funciones as $funcion)
            <label class="flex cursor-pointer items-start gap-2.5 rounded-md border border-gray-200 px-3 py-2 hover:bg-gray-50 dark:border-ink-600 dark:hover:bg-ink-700">
                <input type="checkbox" name="funciones[]" value="{{ $funcion->value }}"
                       @checked(in_array($funcion->value, $funcionesElegidas))
                       class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800">
                <span>
                    <span class="block text-sm font-medium text-gray-700 dark:text-paper-200">{{ $funcion->label() }}</span>
                    <span class="block text-xs text-gray-400 dark:text-paper-500">{{ $funcion->detalle() }}</span>
                </span>
            </label>
        @endforeach
    </div>
    <x-input-error :messages="$errors->get('funciones')" class="mt-1" />
</div>

<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
    <div>
        <label for="telefono" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
            Teléfono <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
        </label>
        <input type="text" name="telefono" id="telefono" maxlength="30"
               value="{{ old('telefono', $personal->telefono ?? '') }}"
               class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <x-input-error :messages="$errors->get('telefono')" class="mt-1" />
    </div>

    <div>
        <label for="user_id" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
            Usuario del sistema <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
        </label>
        <select name="user_id" id="user_id"
                class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            <option value="">Sin usuario</option>
            @foreach ($usuarios as $usuario)
                <option value="{{ $usuario->id }}" @selected(old('user_id', $personal->user_id ?? '') == $usuario->id)>{{ $usuario->name }}</option>
            @endforeach
            @if ($personal?->user && ! $usuarios->contains('id', $personal->user_id))
                <option value="{{ $personal->user_id }}" selected>{{ $personal->user->name }}</option>
            @endif
        </select>
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
            Solo si además entra al sistema. Casi nadie de campo lo necesita.
        </p>
        <x-input-error :messages="$errors->get('user_id')" class="mt-1" />
    </div>
</div>

<div>
    <label for="asistencia_empleado_id" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
        Misma persona en planilla <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
    </label>
    <select name="asistencia_empleado_id" id="asistencia_empleado_id"
            class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <option value="">Sin enlazar</option>
        @foreach ($empleados as $empleado)
            <option value="{{ $empleado->id }}" @selected(old('asistencia_empleado_id', $personal->asistencia_empleado_id ?? '') == $empleado->id)>
                {{ $empleado->nombreCompleto() }}
            </option>
        @endforeach
        @if ($personal?->empleado && ! $empleados->contains('id', $personal->asistencia_empleado_id))
            <option value="{{ $personal->asistencia_empleado_id }}" selected>{{ $personal->empleado->nombreCompleto() }}</option>
        @endif
    </select>
    <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">
        Sirve para que la misma persona no figure dos veces en el sistema. Es solo una referencia:
        Rutas no lee asistencia ni depende de ese módulo.
    </p>
    <x-input-error :messages="$errors->get('asistencia_empleado_id')" class="mt-1" />
</div>

<div>
    <label for="notas" class="block text-sm font-medium text-gray-700 dark:text-paper-200">
        Notas <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
    </label>
    <textarea name="notas" id="notas" rows="2" maxlength="300"
              class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">{{ old('notas', $personal->notas ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('notas')" class="mt-1" />
</div>
