<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            Datos de tu perfil
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            Revisá tu nombre y correo de acceso.
        </p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        @if ($errors->any())
            <div role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <p>Revisá estos datos. No se guardó nada.</p>
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Un solo nombre: quien tiene ficha en Personal lo ve acá y lo cambia allá. --}}
        @php($conFicha = $user->tieneFichaEnPersonal())
        <div>
            <x-input-label for="name" value="Nombre" />
            @if ($conFicha)
                <x-text-input id="name" type="text" class="mt-1 block min-h-11 w-full bg-gray-50" :value="$user->name" readonly aria-describedby="name-ayuda" autocomplete="name" />
                <p id="name-ayuda" class="mt-1 text-xs text-gray-500">Tu nombre se cambia en tu ficha de Personal.</p>
            @else
                <x-text-input id="name" name="name" type="text" class="mt-1 block min-h-11 w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            @endif
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" value="Correo de acceso" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block min-h-11 w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <div>
            <x-input-label for="profile_current_password" value="Contraseña actual (solo para cambiar el correo)" />
            <x-text-input id="profile_current_password" name="current_password" type="password" class="mt-1 block min-h-11 w-full" autocomplete="current-password" aria-describedby="profile-password-ayuda" />
            <p id="profile-password-ayuda" class="mt-1 text-xs text-gray-500">Para cambiar tu correo, escribí tu contraseña actual.</p>
            <x-input-error class="mt-2" :messages="$errors->get('current_password')" />
        </div>

        <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-end">
            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    role="status"
                    class="text-sm text-gray-600"
                >Cambios guardados.</p>
            @endif
            <x-primary-button>Guardar cambios</x-primary-button>
        </div>
    </form>
</section>
