{{-- Rol, correo y contraseña de un acceso nuevo. Los usan «Agregar acceso o módulos» de la
     ficha y Personal › Accesos › Nueva cuenta externa: mismos campos, mismos nombres y mismo orden.

     `$obligatorio` pone `required`. En la ficha va sin él: los campos están ocultos hasta
     marcar la casilla, y un campo oculto obligatorio no deja enviar el formulario. El
     servidor los exige igual. `$roles` limita los roles (la cuenta externa solo lleva
     Contabilidad); el servidor valida lo mismo. --}}
@php
    $obligatorio = $obligatorio ?? false;
    $roles = $roles ?? \App\Enums\RolSistema::opciones();
    $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-100';
@endphp

<p class="text-xs text-gray-500 sm:col-span-2 dark:text-paper-300">El rol dice qué puede hacer. Vendedor y Jefe de vendedores también salen a rutas.</p>
<div>
    <label for="rol" class="{{ $etiqueta }}">Rol</label>
    <select id="rol" name="rol" class="{{ $campo }}" @if ($obligatorio) required @endif>
        <option value="">— Elegí —</option>
        @foreach ($roles as $valor => $texto)
            <option value="{{ $valor }}" @selected(old('rol', count($roles) === 1 ? $valor : null) === $valor)>{{ $texto }}</option>
        @endforeach
    </select>
</div>
<div>
    <label for="email" class="{{ $etiqueta }}">Correo</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="off" class="{{ $campo }}" @if ($obligatorio) required @endif>
</div>
<div>
    <label for="password" class="{{ $etiqueta }}">Contraseña</label>
    <input id="password" name="password" type="password" autocomplete="new-password" class="{{ $campo }}" aria-describedby="password-ayuda" @if ($obligatorio) required @endif>
</div>
<div>
    <label for="password_confirmation" class="{{ $etiqueta }}">Repetí la contraseña</label>
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="{{ $campo }}" @if ($obligatorio) required @endif>
</div>
<p id="password-ayuda" class="text-xs text-gray-500 sm:col-span-2 dark:text-paper-300">
    Mínimo {{ config('security.password.min_length', 12) }} caracteres, con mayúsculas, minúsculas, números y símbolos.
</p>
