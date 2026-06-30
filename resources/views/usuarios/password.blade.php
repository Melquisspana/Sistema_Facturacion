<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Cambiar contraseña — {{ $usuario->name }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">

                @if ($errors->any())
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('usuarios.password.update', $usuario) }}" class="space-y-5"
                      onsubmit="return confirm('¿Cambiar la contraseña de este usuario?');">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="password" value="Nueva contraseña *" />
                        <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('password')" class="mt-1" />
                        <p class="text-xs text-gray-400 mt-1">Mínimo {{ config('security.password.min_length', 12) }} caracteres, con mayúsculas, minúsculas, números y símbolos.</p>
                    </div>
                    <div>
                        <x-input-label for="password_confirmation" value="Confirmar contraseña *" />
                        <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button>Cambiar contraseña</x-primary-button>
                        <a href="{{ route('usuarios.show', $usuario) }}" class="text-sm text-gray-500 hover:underline">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
