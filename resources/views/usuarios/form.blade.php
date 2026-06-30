<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $usuario->exists ? 'Editar' : 'Nuevo' }} usuario
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif
                @if (session('error'))
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
                @endif

                <form method="POST"
                      action="{{ $usuario->exists ? route('usuarios.update', $usuario) : route('usuarios.store') }}"
                      class="space-y-5">
                    @csrf
                    @if ($usuario->exists) @method('PUT') @endif

                    <div>
                        <x-input-label for="name" value="Nombre *" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                            :value="old('name', $usuario->name)" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="email" value="Correo *" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                            :value="old('email', $usuario->email)" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="rol" value="Rol *" />
                        <select id="rol" name="rol" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                            <option value="">— Seleccione —</option>
                            @foreach ($roles as $valor => $label)
                                <option value="{{ $valor }}" @selected(old('rol', $rolActual) === $valor)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('rol')" class="mt-1" />
                    </div>

                    @unless ($usuario->exists)
                        <div>
                            <x-input-label for="password" value="Contraseña *" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required />
                            <x-input-error :messages="$errors->get('password')" class="mt-1" />
                            <p class="text-xs text-gray-400 mt-1">Mínimo {{ config('security.password.min_length', 12) }} caracteres, con mayúsculas, minúsculas, números y símbolos.</p>
                        </div>
                        <div>
                            <x-input-label for="password_confirmation" value="Confirmar contraseña *" />
                            <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required />
                        </div>
                    @endunless

                    <label class="inline-flex items-center">
                        <input type="hidden" name="activo" value="0">
                        <input type="checkbox" name="activo" value="1" class="rounded border-gray-300"
                            @checked(old('activo', $usuario->activo ?? true))>
                        <span class="ml-2 text-sm text-gray-700">Activo</span>
                    </label>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Guardar</x-primary-button>
                        <a href="{{ route('usuarios.index') }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
