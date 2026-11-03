<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Usuario: {{ $usuario->name }}</h2>
            <a href="{{ route('usuarios.index') }}" class="text-sm text-gray-500 hover:underline">← Volver</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $usuario->activo ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                        {{ $usuario->activo ? 'Activo' : 'Inactivo' }}
                    </span>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('usuarios.edit', $usuario) }}" class="text-indigo-600 hover:underline text-sm">Editar</a>
                        <a href="{{ route('usuarios.password.edit', $usuario) }}" class="text-blue-600 hover:underline text-sm">Cambiar contraseña</a>
                    </div>
                </div>
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div><dt class="text-gray-500">Nombre</dt><dd>{{ $usuario->name }}</dd></div>
                    <div><dt class="text-gray-500">Correo</dt><dd>{{ $usuario->email }}</dd></div>
                    <div><dt class="text-gray-500">Rol</dt><dd>{{ $usuario->roles->first()?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Registrado</dt><dd>{{ $usuario->created_at?->format('d/m/Y H:i') }}</dd></div>
                </dl>
            </div>

            @if ($conPersonal)
                <div class="bg-white shadow sm:rounded-lg p-6">
                    <h3 class="font-medium text-gray-700 mb-3">Personal</h3>
                    @if ($ficha)
                        <p class="text-sm">Es la cuenta de
                            @if ($verFicha)
                                <a href="{{ route('personal.empleados.show', 'p'.$ficha->id) }}" class="text-indigo-600 hover:underline">{{ $ficha->nombre }}</a>.
                            @else
                                {{ $ficha->nombre }}.
                            @endif
                            Su nombre se corrige en su ficha de Personal.
                        </p>
                    @else
                        <p class="text-sm text-gray-700">Sin ficha en Personal.</p>
                        @if ($fichasSinUsuario === null)
                            <p class="mt-1 text-sm text-gray-500">Si es un empleado, quien gestiona Planilla puede enlazarlo desde la ficha de la persona, en «Agregar acceso o módulos».</p>
                        @elseif ($fichasSinUsuario->isEmpty())
                            <p class="mt-1 text-sm text-gray-500">No hay personas sin usuario en Personal. Si es un empleado, dalo de alta en
                                <a href="{{ route('planilla.empleados.create') }}" class="text-indigo-600 hover:underline">Personal</a> y enlazalo desde su ficha.</p>
                        @else
                            <form method="POST" action="{{ route('usuarios.personal', $usuario) }}" class="mt-3 flex flex-wrap items-end gap-2">
                                @csrf
                                <div class="flex-1 min-w-56">
                                    <x-input-label for="planilla_empleado_id" value="Enlazar con la persona" />
                                    <select id="planilla_empleado_id" name="planilla_empleado_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                        <option value="">— Seleccione —</option>
                                        @foreach ($fichasSinUsuario as $persona)
                                            <option value="{{ $persona->id }}" @selected((string) old('planilla_empleado_id') === (string) $persona->id)>{{ $persona->nombre }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <x-primary-button>Enlazar</x-primary-button>
                            </form>
                            <p class="mt-2 text-xs text-gray-500">El usuario toma el nombre de la ficha. Si es Vendedor, queda también en Rutas.</p>
                        @endif
                        @if ($errors->any())
                            <ul class="mt-3 list-disc list-inside rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                            </ul>
                        @endif
                    @endif
                </div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6">
                <h3 class="font-medium text-gray-700 mb-3">Historial de auditoría</h3>
                @forelse ($actividades as $actividad)
                    <div class="flex items-start gap-3 py-2 border-b border-gray-100 last:border-0 text-sm">
                        <div class="text-gray-400 whitespace-nowrap">{{ $actividad->created_at->format('d/m/Y H:i') }}</div>
                        <div>
                            <span class="font-medium">{{ $actividad->causer?->name ?? 'Sistema' }}</span>
                            {{ $actividad->description }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">Sin actividad registrada.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
