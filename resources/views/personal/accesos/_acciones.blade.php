{{-- Lo que se le hace a un acceso que ya existe: rol y correo, contraseña, activar o
     desactivar, y su historial. Un solo lugar para todo el sistema: lo usan la ficha de la
     persona («Acceso y módulos») y la página de la cuenta sin ficha en Personal › Accesos.
     Quien lo incluye ya comprobó `usuarios.gestionar`; el servidor lo vuelve a exigir.

     Recibe $cuenta (con roles), $ficha (o null) y $actividades. Los errores llegan en el
     bloque «acceso», para no mezclarse con los otros formularios de la ficha. --}}
@php
    $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-100';
    $secundario = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $erroresAcceso = $errors->getBag('acceso');
    $rolActual = $cuenta->roles->first()?->name;
    // La cuenta externa (contadora que no es empleada) solo lleva Contabilidad.
    $rolesAcceso = $cuenta->cuenta_externa
        ? [\App\Enums\RolSistema::Contabilidad->value => \App\Enums\RolSistema::Contabilidad->label()]
        : \App\Enums\RolSistema::opciones();
@endphp

@if ($erroresAcceso->any())
    <div role="alert" class="mt-2 rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
        <p>Revisá estos datos. No se guardó nada.</p>
        <ul class="list-inside list-disc">@foreach ($erroresAcceso->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="mt-2 flex flex-wrap items-start gap-3">
    <details @if ($erroresAcceso->hasAny(['name', 'email', 'rol'])) open @endif>
        <summary class="{{ $secundario }} cursor-pointer">Cambiar rol o correo</summary>
        <form method="POST" action="{{ route('personal.accesos.update', $cuenta) }}" class="mt-2 grid w-full max-w-xl gap-2 sm:grid-cols-2">
            @csrf
            @method('PUT')
            @unless ($ficha)
                <div class="sm:col-span-2">
                    <label for="acceso_name" class="{{ $etiqueta }}">Nombre</label>
                    <input id="acceso_name" name="name" value="{{ old('name', $cuenta->name) }}" maxlength="255" required class="{{ $campo }}">
                </div>
            @endunless
            <div>
                <label for="acceso_rol" class="{{ $etiqueta }}">Rol</label>
                <select id="acceso_rol" name="rol" required class="{{ $campo }}">
                    @foreach ($rolesAcceso as $valor => $texto)
                        <option value="{{ $valor }}" @selected(old('rol', $rolActual) === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="acceso_email" class="{{ $etiqueta }}">Correo</label>
                <input id="acceso_email" name="email" type="email" value="{{ old('email', $cuenta->email) }}" maxlength="255" required autocomplete="off" class="{{ $campo }}">
            </div>
            <p class="text-xs text-gray-500 sm:col-span-2 dark:text-paper-300">
                @if ($ficha)
                    El nombre se corrige arriba, en «Editar datos». Vendedor y Jefe de vendedores también salen a rutas.
                @elseif ($cuenta->cuenta_externa)
                    Cuenta externa: solo con rol Contabilidad.
                @else
                    Vendedor y Jefe de vendedores también salen a rutas.
                @endif
            </p>
            <div class="sm:col-span-2"><button type="submit" class="{{ $secundario }}">Guardar cambios</button></div>
        </form>
    </details>

    <details @if ($erroresAcceso->has('password')) open @endif>
        <summary class="{{ $secundario }} cursor-pointer">Cambiar contraseña</summary>
        <form method="POST" action="{{ route('personal.accesos.password', $cuenta) }}" class="mt-2 grid w-full max-w-xl gap-2 sm:grid-cols-2"
              onsubmit="return confirm('¿Cambiar la contraseña de esta cuenta? La anterior deja de servir.')">
            @csrf
            @method('PUT')
            <div>
                <label for="acceso_password" class="{{ $etiqueta }}">Contraseña nueva</label>
                <input id="acceso_password" name="password" type="password" required autocomplete="new-password" aria-describedby="acceso-password-ayuda" class="{{ $campo }}">
            </div>
            <div>
                <label for="acceso_password_confirmation" class="{{ $etiqueta }}">Repetí la contraseña</label>
                <input id="acceso_password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="{{ $campo }}">
            </div>
            <p id="acceso-password-ayuda" class="text-xs text-gray-500 sm:col-span-2 dark:text-paper-300">
                Mínimo {{ config('security.password.min_length', 12) }} caracteres, con mayúsculas, minúsculas, números y símbolos.
            </p>
            <div class="sm:col-span-2"><button type="submit" class="{{ $secundario }}">Guardar contraseña</button></div>
        </form>
    </details>

    <form method="POST" action="{{ route('personal.accesos.toggle-activo', $cuenta) }}"
          @if ($cuenta->activo) onsubmit="return confirm('¿Desactivar este acceso? No va a poder entrar hasta que lo actives.')" @endif>
        @csrf
        @method('PATCH')
        <button type="submit" class="{{ $secundario }}">{{ $cuenta->activo ? 'Desactivar acceso' : 'Activar acceso' }}</button>
    </form>

    <details class="w-full">
        <summary class="{{ $secundario }} cursor-pointer">Historial del acceso</summary>
        <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-ink-700">
            @forelse ($actividades as $actividad)
                <li class="flex flex-wrap items-start gap-x-3 gap-y-1 py-2">
                    <span class="whitespace-nowrap text-gray-500 dark:text-paper-300">{{ $actividad->created_at->format('d/m/Y H:i') }}</span>
                    <span class="text-gray-800 dark:text-paper-100"><span class="font-medium">{{ $actividad->causer?->name ?? 'Sistema' }}</span> {{ $actividad->description }}</span>
                </li>
            @empty
                <li class="py-2 text-gray-500 dark:text-paper-300">Sin actividad registrada.</li>
            @endforelse
        </ul>
    </details>
</div>
