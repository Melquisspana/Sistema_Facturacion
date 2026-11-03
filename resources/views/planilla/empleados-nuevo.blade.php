@php($c = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500')
<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold leading-tight text-gray-800">Nuevo empleado</h1></x-slot>
    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-3">
            <p class="text-sm text-gray-600">Esta alta siempre incorpora a la persona en planilla. Cada persona se carga una sola vez, acá: su usuario, su asistencia y su ficha de Rutas salen de esta ficha.</p>
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <form method="POST" action="{{ route('planilla.empleados.store') }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4"
                  x-data="{ usaSistema: @js((bool) old('usa_sistema', false)) }">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (['nombres' => 'Nombres', 'apellidos' => 'Apellidos', 'cargo' => 'Cargo'] as $campo => $etiqueta)
                        <div><label for="{{ $campo }}" class="block text-sm font-medium text-gray-700">{{ $etiqueta }}</label>
                            <input id="{{ $campo }}" name="{{ $campo }}" value="{{ old($campo) }}" maxlength="{{ $campo === 'cargo' ? 120 : 80 }}" @required($campo !== 'cargo') class="{{ $c }}"></div>
                    @endforeach
                    <div><label for="documento_tipo" class="block text-sm font-medium text-gray-700">Tipo de documento</label>
                        <select id="documento_tipo" name="documento_tipo" required class="{{ $c }}">
                            @foreach (\App\Models\Planilla\PlanillaEmpleado::DOCUMENTO_TIPOS as $valor => $etiqueta)<option value="{{ $valor }}" @selected(old('documento_tipo', 'dui') === $valor)>{{ $etiqueta }}</option>@endforeach
                        </select></div>
                    <div><label for="dui" class="block text-sm font-medium text-gray-700">Número de documento</label><input id="dui" name="dui" required maxlength="20" value="{{ old('dui') }}" class="{{ $c }}"></div>
                    <div><label for="alta_el" class="block text-sm font-medium text-gray-700">Fecha de ingreso</label><input id="alta_el" name="alta_el" type="date" value="{{ old('alta_el') }}" class="{{ $c }}"></div>
                    @can('planilla.salarios')
                        <div><label for="sueldo_mensual" class="block text-sm font-medium text-gray-700">Sueldo mensual</label><input id="sueldo_mensual" name="sueldo_mensual" inputmode="decimal" value="{{ old('sueldo_mensual') }}" class="{{ $c }}"></div>
                    @endcan
                    <div><label for="con_descuentos_ley" class="block text-sm font-medium text-gray-700">Descuentos de ley (obligatorio)</label>
                        <select id="con_descuentos_ley" name="con_descuentos_ley" required class="{{ $c }}">
                            <option value="">Elegí una opción</option><option value="1" @selected((string) old('con_descuentos_ley') === '1')>Con descuentos de ley</option><option value="0" @selected((string) old('con_descuentos_ley') === '0')>Sin descuentos de ley</option>
                        </select></div>
                </div>
                <p class="text-sm text-gray-600">Elegí qué necesita. Si enlazás una ficha existente, se usa esa ficha sin cambiar su estado.</p>
                @foreach (['usa_sistema' => ['Usa el sistema', 'usuarios.gestionar'], 'marca' => ['Marca asistencia', 'asistencia.gestionar'], 'sale_rutas' => ['Sale a rutas', 'rutas.personal.gestionar']] as $campo => [$etiqueta, $permiso])
                    @can($permiso)
                        <div><input type="hidden" name="{{ $campo }}" value="0"><input id="{{ $campo }}" type="checkbox" name="{{ $campo }}" value="1" @checked(old($campo)) @if($campo === 'usa_sistema') x-model="usaSistema" @endif><label for="{{ $campo }}" class="text-sm font-medium text-gray-700">{{ $etiqueta }}</label></div>
                    @endcan
                @endforeach
                @can('usuarios.gestionar')
                    <div x-show="usaSistema" class="grid gap-3 sm:grid-cols-2">
                        <p class="text-sm text-gray-600">Completá estos campos solo si no enlazás un usuario. Vendedor también sale a rutas.</p>
                        <div><label for="rol" class="block text-sm font-medium text-gray-700">Rol</label><select id="rol" name="rol" class="{{ $c }}">@foreach ($roles as $valor => $etiqueta)<option value="{{ $valor }}" @selected(old('rol') === $valor)>{{ $etiqueta }}</option>@endforeach</select></div>
                        <div><label for="email" class="block text-sm font-medium text-gray-700">Correo</label><input id="email" name="email" type="email" value="{{ old('email') }}" class="{{ $c }}"></div>
                        <div><label for="password" class="block text-sm font-medium text-gray-700">Contraseña</label><input id="password" name="password" type="password" autocomplete="new-password" class="{{ $c }}"></div>
                        <div><label for="password_confirmation" class="block text-sm font-medium text-gray-700">Repetí la contraseña</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="{{ $c }}"></div>
                    </div>
                @endcan
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (['user_id' => ['usuario', 'Usuario existente', 'usuarios.gestionar'], 'asistencia_empleado_id' => ['asistencia', 'Ficha de asistencia existente', 'asistencia.gestionar'], 'personal_ruta_id' => ['ruta', 'Ficha de rutas existente', 'rutas.personal.gestionar']] as $campo => [$tipo, $etiqueta, $permiso])
                        @can($permiso)
                            <div><label for="{{ $campo }}" class="block text-sm font-medium text-gray-700">{{ $etiqueta }}</label><select id="{{ $campo }}" name="{{ $campo }}" class="{{ $c }}"><option value="">Sin enlace</option>
                                @foreach ($candidatos as $candidato) @if ($candidato['tipo'] === $tipo)<option value="{{ $candidato['id'] }}" @selected((string) old($campo) === (string) $candidato['id'])>{{ $candidato['nombre'] }}</option>@endif @endforeach
                            </select></div>
                        @endcan
                    @endforeach
                </div>
                @if ($coincidencias)
                    <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700"><p>Revisá estas coincidencias y enlazá las fichas correspondientes:</p><ul class="list-inside list-disc">@foreach ($coincidencias as $coincidencia)<li>{{ $coincidencia['nombre'] }} · {{ str_replace('_', ' ', $coincidencia['tipo']) }}</li>@endforeach</ul></div>
                @endif
                <div><input id="es_otra_persona" name="es_otra_persona" type="checkbox" value="1" @checked(old('es_otra_persona'))><label for="es_otra_persona" class="text-sm font-medium text-gray-700">Confirmo que es otra persona distinta de las coincidencias.</label></div>
                @error('alta_el')
                    <div><input id="confirmar_alta_futura" name="confirmar_alta_futura" type="checkbox" value="1"><label for="confirmar_alta_futura" class="text-sm font-medium text-gray-700">Confirmo la fecha de ingreso futura.</label></div>
                @enderror
                @error('sueldo_mensual')
                    <div><input id="confirmar_bajo_minimo" name="confirmar_bajo_minimo" type="checkbox" value="1"><label for="confirmar_bajo_minimo" class="text-sm font-medium text-gray-700">Va sin descuentos: confirmo el sueldo por debajo del mínimo.</label></div>
                @enderror
                <div><label for="notas" class="block text-sm font-medium text-gray-700">Notas</label><textarea id="notas" name="notas" maxlength="2000" class="{{ $c }}">{{ old('notas') }}</textarea></div>
                <button class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Dar de alta</button>
            </form>
        </div>
    </div>
</x-app-layout>