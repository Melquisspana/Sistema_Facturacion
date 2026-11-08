{{-- La ficha de la persona: junta lo de planilla y lo de Asistencia. Cada bloque aparece
     solo si el usuario alcanza ese módulo: ver App\Http\Controllers\Personal\PersonalController. --}}
@php
    use App\Models\Planilla\Planilla;
    use App\Models\Planilla\PlanillaEmpleado;
    use App\Services\Gastos\Dinero;
    use App\Services\Planilla\IndemnizacionBaja;

    $tarjeta = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600';
    $dt = 'text-gray-500 dark:text-paper-300';
    $dd = 'text-gray-800 dark:text-paper-100';
    $campo = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $etiqueta = 'block text-sm font-medium text-gray-700 dark:text-paper-100';
    $secundario = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100';
    $gestiona = $ficha !== null && auth()->user()->can('planilla.gestionar');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-paper-100">{{ $nombre }}</h2>
            @if ($asistencia)
                <div class="flex items-center gap-3">
                    <x-asistencia.estado :activo="$asistencia->activo"
                                         :accion="route('asistencia.empleados.toggle-activo', $asistencia)"
                                         etiquetaActivo="Marca" etiquetaInactivo="No marca" />
                    {{-- Quien está en planilla se edita una sola vez, abajo, en «Editar datos». --}}
                    @if (! $ficha && auth()->user()->can('asistencia.gestionar'))
                        <a href="{{ route('asistencia.empleados.edit', $asistencia) }}"
                           class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-ink-600 dark:text-paper-100 dark:hover:bg-ink-700">Editar</a>
                    @endif
                </div>
            @endif
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <p role="status" class="rounded-md border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-800 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-300">{{ session('status') }}</p>
            @endif
            @if (session('planilla.aviso'))
                <p role="status" class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('planilla.aviso') }}</p>
            @endif

            @if ($ficha)
                @if ($errors->hasAny(['nombre', 'nombres', 'apellidos', 'documento_tipo', 'dui', 'cargo', 'alta_el', 'vacaciones_pagadas_hasta', 'modulos', 'rol', 'email', 'password', 'user_id', 'asistencia_empleado_id', 'personal_ruta_id', 'sueldo_mensual', 'vigente_desde', 'fecha', 'motivo_baja', 'empleado', 'contrato']))
                    <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                        <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <div class="{{ $tarjeta }}">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Planilla</h3>
                    <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div class="flex gap-2"><dt class="{{ $dt }}">Estado:</dt><dd class="{{ $dd }}">{{ $ficha->activo ? 'Activo' : 'De baja'.($ficha->baja_el ? ' desde el '.\Illuminate\Support\Carbon::parse($ficha->baja_el)->format('d/m/Y') : '') }}</dd></div>
                        @if ($baja)
                            <div class="flex gap-2"><dt class="{{ $dt }}">Motivo de la baja:</dt><dd class="{{ $dd }}">{{ $baja['motivo'] ? IndemnizacionBaja::MOTIVOS[$baja['motivo']] : 'Sin dato: la baja es de antes de que se preguntara' }}{{ $baja['motivo'] === 'otro' ? ' · no corresponde indemnización' : '' }}</dd></div>
                        @endif
                        <div class="flex gap-2"><dt class="{{ $dt }}">Descuentos de ley:</dt><dd class="{{ $dd }}">{{ $ficha->con_descuentos_ley ? 'Con descuentos' : 'Sin descuentos' }}</dd></div>
                        <div class="flex gap-2"><dt class="{{ $dt }}">{{ PlanillaEmpleado::DOCUMENTO_TIPOS[$ficha->documento_tipo] }}:</dt><dd class="{{ $dd }}">{{ $ficha->dui ?? '—' }}</dd></div>
                        <div class="flex gap-2"><dt class="{{ $dt }}">Cargo:</dt><dd class="{{ $dd }}">{{ $ficha->cargo ?? '—' }}</dd></div>
                        <div class="flex gap-2"><dt class="{{ $dt }}">Ingreso:</dt><dd class="{{ $dd }}">{{ $ficha->alta_el?->format('d/m/Y') ?? '—' }}</dd></div>
                        <div class="flex gap-2"><dt class="{{ $dt }}">Vacaciones pagadas hasta:</dt><dd class="{{ $dd }}">{{ $ficha->vacaciones_pagadas_hasta ?? '—' }}</dd></div>
                        @if ($verImportes)
                            <div class="flex gap-2"><dt class="{{ $dt }}">Sueldo mensual:</dt><dd class="tabular-nums {{ $dd }}">{{ $mensual !== null ? Dinero::mostrar($mensual) : 'Sin sueldo' }}</dd></div>
                        @endif
                    </dl>

                    @if ($gestiona)
                        <div class="mt-4 flex flex-wrap items-start gap-3">
                            {{-- Un solo lugar para los datos de la persona: llegan solos a Asistencia y Rutas.
                                 Quien marca escribe nombres y apellidos por separado, como los guarda Asistencia. --}}
                            @php
                                $marca = $ficha->asistencia_empleado_id !== null;
                                $puedeEditar = ! $marca || auth()->user()->can('asistencia.gestionar');
                            @endphp
                            @if ($puedeEditar)
                                <details @if ($errors->hasAny(['nombre', 'nombres', 'apellidos', 'documento_tipo', 'dui', 'cargo', 'alta_el', 'vacaciones_pagadas_hasta'])) open @endif>
                                    <summary class="{{ $secundario }} cursor-pointer">Editar datos</summary>
                                    <form method="POST" action="{{ route('planilla.empleados.datos', $ficha) }}" class="mt-2 grid w-full max-w-xl gap-2 sm:grid-cols-2">
                                        @csrf
                                        @if ($marca)
                                            <div><label for="nombres" class="{{ $etiqueta }}">Nombres</label>
                                                <input id="nombres" name="nombres" value="{{ old('nombres', $asistencia?->nombres) }}" maxlength="80" required class="{{ $campo }}"></div>
                                            <div><label for="apellidos" class="{{ $etiqueta }}">Apellidos</label>
                                                <input id="apellidos" name="apellidos" value="{{ old('apellidos', $asistencia?->apellidos) }}" maxlength="80" required class="{{ $campo }}"></div>
                                        @else
                                            <div class="sm:col-span-2"><label for="nombre" class="{{ $etiqueta }}">Nombre completo</label>
                                                <input id="nombre" name="nombre" value="{{ old('nombre', $ficha->nombre) }}" maxlength="120" required class="{{ $campo }}"></div>
                                        @endif
                                        <div><label for="documento_tipo" class="{{ $etiqueta }}">Tipo de documento</label>
                                            <select id="documento_tipo" name="documento_tipo" required class="{{ $campo }}">
                                                @foreach (PlanillaEmpleado::DOCUMENTO_TIPOS as $valor => $texto)
                                                    <option value="{{ $valor }}" @selected(old('documento_tipo', $ficha->documento_tipo) === $valor)>{{ $texto }}</option>
                                                @endforeach
                                            </select></div>
                                        <div><label for="dui" class="{{ $etiqueta }}">Número de documento</label>
                                            <input id="dui" name="dui" value="{{ old('dui', $ficha->dui) }}" maxlength="20" required class="{{ $campo }}"></div>
                                        <div><label for="cargo" class="{{ $etiqueta }}">Cargo</label>
                                            <input id="cargo" name="cargo" value="{{ old('cargo', $ficha->cargo) }}" maxlength="120" class="{{ $campo }}"></div>
                                        <div><label for="alta_el" class="{{ $etiqueta }}">Fecha de ingreso</label>
                                            <input id="alta_el" name="alta_el" type="date" value="{{ old('alta_el', $ficha->alta_el?->toDateString()) }}" class="{{ $campo }}"></div>
                                        <div class="sm:col-span-2"><label for="vacaciones_pagadas_hasta" class="{{ $etiqueta }}">Vacaciones pagadas hasta (año) (opcional)</label>
                                            <input id="vacaciones_pagadas_hasta" name="vacaciones_pagadas_hasta" type="number" inputmode="numeric" step="1"
                                                   min="{{ $ficha->alta_el?->year ?? 1900 }}" max="{{ now()->year }}"
                                                   value="{{ old('vacaciones_pagadas_hasta', $ficha->vacaciones_pagadas_hasta) }}"
                                                   aria-describedby="vacaciones_pagadas_hasta_ayuda" class="{{ $campo }}">
                                            <p id="vacaciones_pagadas_hasta_ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">Último año en que cobró sus vacaciones, aunque haya sido fuera del sistema.</p></div>
                                        <p class="text-xs text-gray-500 sm:col-span-2 dark:text-paper-300">Se guarda también en Asistencia y Rutas. Las quincenas confirmadas no cambian.</p>
                                        <div class="sm:col-span-2"><button type="submit" class="{{ $secundario }}">Guardar datos</button></div>
                                    </form>
                                </details>
                            @endif

                            {{-- Lo que no se marcó al dar de alta se agrega acá, después. --}}
                            @php
                                $opciones = array_filter([
                                    'marca' => $ficha->asistencia_empleado_id === null && auth()->user()->can('asistencia.gestionar') ? 'Marca con huella' : null,
                                    'sale_rutas' => $ficha->personal_ruta_id === null && auth()->user()->can('rutas.personal.gestionar') ? 'Sale a rutas' : null,
                                    'usa_sistema' => $ficha->user_id === null && auth()->user()->can('usuarios.gestionar') ? 'Darle acceso al sistema' : null,
                                ]);
                            @endphp
                            @if ($ficha->activo && $opciones)
                                <details @if ($errors->hasAny(['modulos', 'rol', 'email', 'password', 'user_id', 'asistencia_empleado_id', 'personal_ruta_id'])) open @endif
                                         x-data="{ marca: @js((bool) old('marca')), usa: @js((bool) old('usa_sistema')), existente: @js((string) old('user_id', '')) }">
                                    <summary class="{{ $secundario }} cursor-pointer">Agregar acceso o módulos</summary>
                                    <form method="POST" action="{{ route('planilla.empleados.modulos', $ficha) }}" class="mt-2 w-full max-w-xl space-y-2">
                                        @csrf
                                        @foreach ($opciones as $clave => $texto)
                                            <label class="flex min-h-11 items-center gap-2 text-sm text-gray-700 dark:text-paper-100">
                                                <input type="hidden" name="{{ $clave }}" value="0">
                                                <input type="checkbox" name="{{ $clave }}" value="1" @checked(old($clave))
                                                       @if ($clave === 'marca') x-model="marca" @elseif ($clave === 'usa_sistema') x-model="usa" @endif
                                                       class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                {{ $texto }}
                                            </label>
                                        @endforeach
                                        {{-- Asistencia toma el nombre de esta ficha: no se escribe otro. Si ya
                                             había una ficha de Asistencia suelta de esta persona, se enlaza esa. --}}
                                        @isset($opciones['marca'])
                                            <div x-show="marca" class="space-y-1">
                                                @if ($asistenciasSueltas->isNotEmpty())
                                                    <label for="asistencia_empleado_id" class="{{ $etiqueta }}">Ficha de Asistencia</label>
                                                    <select id="asistencia_empleado_id" name="asistencia_empleado_id" class="{{ $campo }}">
                                                        <option value="">Crear una nueva</option>
                                                        @foreach ($asistenciasSueltas as $suelta)
                                                            <option value="{{ $suelta['id'] }}" @selected((string) old('asistencia_empleado_id') === (string) $suelta['id'])>
                                                                {{ $suelta['nombre'] }}{{ $suelta['parecido'] ? ' · nombre parecido' : '' }}{{ $suelta['activo'] ? '' : ' · no marca' }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @if ($asistenciasSueltas->contains('parecido', true))
                                                        <p class="text-xs text-amber-900 dark:text-amber-300">Ya marca alguien con un nombre parecido. Si es esta persona, elegila para no tenerla dos veces.</p>
                                                    @endif
                                                @endif
                                                <p class="text-xs text-gray-500 dark:text-paper-300">Marca con el nombre de esta ficha: {{ $ficha->nombre }}. Para cambiarlo, usá «Editar datos».</p>
                                            </div>
                                        @endisset
                                        @isset($opciones['usa_sistema'])
                                            @if ($usuariosSinFicha->isNotEmpty())
                                                <div x-show="usa">
                                                    <label for="user_id" class="{{ $etiqueta }}">Usuario</label>
                                                    <select id="user_id" name="user_id" x-model="existente" class="{{ $campo }}">
                                                        <option value="">Crear uno nuevo</option>
                                                        @foreach ($usuariosSinFicha as $cuenta)
                                                            <option value="{{ $cuenta->id }}" @selected((string) old('user_id') === (string) $cuenta->id)>
                                                                {{ $cuenta->name }} · {{ $cuenta->email }} · {{ $cuenta->roles->first()?->name ?? 'sin rol' }}{{ $cuenta->activo ? '' : ' · inactivo' }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    <p class="mt-1 text-xs text-gray-500 dark:text-paper-300">Si ya tiene una cuenta sin ficha (de antes), elegila: queda enlazada y toma el nombre de esta ficha.</p>
                                                </div>
                                            @endif
                                            <div x-show="usa && existente === ''" class="grid gap-2 sm:grid-cols-2">
                                                @include('personal.accesos._campos')
                                            </div>
                                        @endisset
                                        <button type="submit" class="{{ $secundario }}">Agregar</button>
                                    </form>
                                </details>
                            @endif

                            @if ($ficha->activo && $ficha->con_descuentos_ley && $verImportes)
                                <a href="{{ route('planilla.empleados.contrato', $ficha) }}" class="{{ $secundario }}">Contrato</a>
                            @endif

                            @if ($ficha->activo && $verImportes)
                                <details>
                                    <summary class="{{ $secundario }} cursor-pointer">Cambiar sueldo</summary>
                                    <form method="POST" action="{{ route('planilla.empleados.sueldo', $ficha) }}" class="mt-2 w-72 space-y-2">
                                        @csrf
                                        <div>
                                            <label for="sueldo_mensual" class="{{ $etiqueta }}">Sueldo mensual</label>
                                            <input id="sueldo_mensual" name="sueldo_mensual" inputmode="decimal" required class="{{ $campo }}">
                                        </div>
                                        <div>
                                            <label for="vigente_desde" class="{{ $etiqueta }}">Vigente desde</label>
                                            <input id="vigente_desde" name="vigente_desde" type="date" value="{{ now()->toDateString() }}" required class="{{ $campo }}">
                                        </div>
                                        <div>
                                            <label for="motivo" class="{{ $etiqueta }}">Motivo</label>
                                            <input id="motivo" name="motivo" maxlength="500" class="{{ $campo }}">
                                        </div>
                                        @unless ($ficha->con_descuentos_ley)
                                            <div>
                                                <input id="confirmar_bajo_minimo" name="confirmar_bajo_minimo" type="checkbox" value="1">
                                                <label for="confirmar_bajo_minimo" class="text-sm text-gray-700 dark:text-paper-100">Confirmo si queda por debajo del mínimo.</label>
                                            </div>
                                        @endunless
                                        <button class="{{ $secundario }}">Guardar sueldo</button>
                                    </form>
                                </details>
                            @endif

                            {{-- Indemnización o prestación por renuncia: se paga una sola vez, al irse.
                                 Los mismos permisos que pagar vacaciones: gestionar y ver importes. --}}
                            @if ($baja && $verImportes && $baja['motivo'] !== 'otro')
                                @if ($baja['pago'])
                                    <a href="{{ route('planilla.show', $baja['pago']) }}" class="{{ $secundario }}">Ver {{ lcfirst($baja['pago']->nombreDelPago()) }}</a>
                                @else
                                    <a href="{{ route('planilla.empleados.indemnizacion', $ficha) }}" class="{{ $secundario }}">Calcular {{ $baja['motivo'] ? IndemnizacionBaja::nombre($baja['motivo']) : 'indemnización o prestación por renuncia' }}</a>
                                @endif
                            @endif

                            <details @if ($errors->hasAny(['fecha', 'motivo_baja'])) open @endif>
                                <summary class="{{ $secundario }} cursor-pointer">{{ $ficha->activo ? 'Dar de baja' : 'Reactivar' }}</summary>
                                <form method="POST" action="{{ route($ficha->activo ? 'planilla.empleados.baja' : 'planilla.empleados.reactivar', $ficha) }}"
                                      onsubmit="return confirm('¿Confirmás el cambio de estado de esta persona y sus módulos enlazados?')" class="mt-2 w-72 space-y-2">
                                    @csrf
                                    @if ($ficha->activo)
                                        <div>
                                            <label for="fecha" class="{{ $etiqueta }}">Fecha de baja</label>
                                            <input id="fecha" name="fecha" type="date" value="{{ old('fecha', now()->toDateString()) }}" required class="{{ $campo }}">
                                        </div>
                                        <div>
                                            <label for="motivo_baja" class="{{ $etiqueta }}">Motivo de la baja</label>
                                            <select id="motivo_baja" name="motivo_baja" required aria-describedby="motivo_baja_ayuda" class="{{ $campo }}">
                                                <option value="">Elegí el motivo</option>
                                                @foreach (IndemnizacionBaja::MOTIVOS as $valor => $texto)
                                                    <option value="{{ $valor }}" @selected(old('motivo_baja') === $valor)>{{ $texto }}</option>
                                                @endforeach
                                            </select>
                                            <p id="motivo_baja_ayuda" class="mt-1 text-xs text-gray-500 dark:text-paper-300">Decide si le toca indemnización o prestación por renuncia. «Otro» es con causa, fin de contrato u otro caso: no corresponde ninguna.</p>
                                        </div>
                                    @endif
                                    <button class="{{ $secundario }}">Confirmar</button>
                                </form>
                            </details>
                        </div>
                    @endif
                </div>

                {{-- Todo lo de esta persona, en el mismo orden en todas las fichas, con el
                     camino a cada parte. Cada parte vuelve acá. --}}
                @php
                    $cuenta = $ficha->usuario;
                    $rolCuenta = $cuenta?->roles->first()?->name;
                    $rutaPersona = $ficha->personalRuta;
                @endphp
                <div class="{{ $tarjeta }}">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Acceso y módulos</h3>
                    <dl class="mt-3 divide-y divide-gray-100 text-sm dark:divide-ink-700">
                        <div class="flex flex-wrap items-start gap-x-3 gap-y-1 py-2">
                            <dt class="w-24 shrink-0 {{ $dt }}">Usuario</dt>
                            <dd class="{{ $dd }}">
                                @if ($cuenta)
                                    {{ $cuenta->email }} · {{ $rolCuenta ? (\App\Enums\RolSistema::opciones()[$rolCuenta] ?? $rolCuenta) : 'sin rol' }} · {{ $cuenta->activo ? 'Activo' : 'Inactivo' }}
                                    {{-- El acceso se cambia acá mismo: ya no hay pantalla de Usuarios aparte. --}}
                                    @can('usuarios.gestionar')
                                        @include('personal.accesos._acciones', ['cuenta' => $cuenta, 'ficha' => $ficha, 'actividades' => $actividadesCuenta])
                                    @endcan
                                @else
                                    <span class="{{ $dt }}">Sin usuario</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <dt class="w-24 shrink-0 {{ $dt }}">Asistencia</dt>
                            <dd class="{{ $dd }}">
                                @if ($ficha->asistencia_empleado_id !== null)
                                    Marca con huella
                                    @if ($asistencia)
                                        <a href="#asistencia" class="ml-2 text-indigo-600 hover:underline dark:text-indigo-400">Ver huellas y marcaciones</a>
                                    @endif
                                @else
                                    <span class="{{ $dt }}">No marca</span>
                                @endif
                            </dd>
                        </div>
                        <div class="flex flex-wrap items-start gap-x-3 gap-y-1 py-2">
                            <dt class="w-24 shrink-0 {{ $dt }}">Rutas</dt>
                            <dd class="{{ $dd }}">
                                @if ($rutaPersona)
                                    {{ $rutaPersona->funcionesEnum()->map(fn ($f) => $f->label())->implode(', ') ?: 'Sin funciones' }} · {{ $rutaPersona->activo ? 'Activo' : 'Inactivo' }}
                                    @if ($rutaPersona->telefono)
                                        <p class="mt-1">Teléfono · {{ $rutaPersona->telefono }}</p>
                                    @endif
                                    @if ($rutaPersona->notas)
                                        <p class="mt-1 whitespace-pre-line">{{ $rutaPersona->notas }}</p>
                                    @endif
                                    @can('rutas.ver')
                                        @can('rutas.personal.gestionar')
                                            <details class="mt-2" @if ($errors->hasAny(['telefono', 'notas', 'funciones', 'funciones.*'])) open @endif>
                                                <summary class="{{ $secundario }} cursor-pointer">Editar datos de rutas</summary>
                                                <form method="POST" action="{{ route('rutas.personal.update', $rutaPersona) }}" class="mt-2 grid w-full max-w-xl gap-2">
                                                    @csrf
                                                    @method('PUT')
                                                    @if ($errors->hasAny(['telefono', 'notas', 'funciones', 'funciones.*']))
                                                        <p role="alert" class="text-sm text-red-600 dark:text-red-400">Revisá estos datos. No se guardó nada.</p>
                                                    @endif
                                                    <div>
                                                        <label for="ruta_telefono" class="{{ $etiqueta }}">Teléfono (opcional)</label>
                                                        <input id="ruta_telefono" name="telefono" maxlength="30" value="{{ old('telefono', $rutaPersona->telefono) }}" class="{{ $campo }}">
                                                        @error('telefono')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                                    </div>
                                                    <div>
                                                        <label for="ruta_notas" class="{{ $etiqueta }}">Notas (opcional)</label>
                                                        <textarea id="ruta_notas" name="notas" maxlength="300" class="{{ $campo }}">{{ old('notas', $rutaPersona->notas) }}</textarea>
                                                        @error('notas')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                                    </div>
                                                    <fieldset>
                                                        <legend class="{{ $etiqueta }}">Funciones</legend>
                                                        @foreach (\App\Enums\FuncionPersonalRuta::cases() as $funcion)
                                                            <label class="flex min-h-11 items-center gap-2">
                                                                <input type="checkbox" name="funciones[]" value="{{ $funcion->value }}" @checked(in_array($funcion->value, old('funciones', session()->hasOldInput() ? [] : $rutaPersona->funcionesEnum()->map(fn ($f) => $f->value)->all()))) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800">
                                                                {{ $funcion->label() }}
                                                            </label>
                                                        @endforeach
                                                        @error('funciones')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                                        @error('funciones.*')<p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                                    </fieldset>
                                                    <div><button type="submit" class="{{ $secundario }}">Guardar</button></div>
                                                </form>
                                            </details>
                                            <form method="POST" action="{{ route('rutas.personal.toggle-activo', $rutaPersona) }}" class="mt-2">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="{{ $secundario }}">{{ $rutaPersona->activo ? 'Desactivar en rutas' : 'Activar en rutas' }}</button>
                                            </form>
                                        @endcan
                                    @endcan
                                    @if (auth()->user()->can('rutas.ver') && auth()->user()->can('rutas.personal.ver') && ! \App\Models\PersonalRuta::seGestionaEnPersonal(auth()->user()))
                                        <a href="{{ route('rutas.personal.show', $rutaPersona) }}" class="ml-2 text-indigo-600 hover:underline dark:text-indigo-400">Abrir en Rutas</a>
                                    @endif
                                @else
                                    <span class="{{ $dt }}">No sale a rutas</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="{{ $tarjeta }}">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-paper-100">Últimos pagos</h3>
                    @if ($quincenas->isEmpty())
                        <p class="mt-3 text-sm text-gray-500 dark:text-paper-300">Todavía no está en ninguna planilla.</p>
                    @else
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <caption class="sr-only">Últimas planillas de la persona</caption>
                                <thead>
                                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-ink-600 dark:text-paper-300">
                                        <th scope="col" class="py-2 pr-4">Pago</th>
                                        <th scope="col" class="py-2 pr-4">Tipo</th>
                                        <th scope="col" class="py-2 pr-4">Estado</th>
                                        @if ($verImportes)
                                            <th scope="col" class="py-2 text-right">A pagar</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-ink-700">
                                    @foreach ($quincenas as $q)
                                        <tr>
                                            <td class="py-2.5 pr-4"><a href="{{ route('planilla.show', $q->planilla) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $q->planilla->esIndividual() ? 'Pago del '.$q->planilla->fecha_pago?->format('d/m/Y') : $q->planilla->periodo }}</a></td>
                                            <td class="py-2.5 pr-4 text-gray-600 dark:text-paper-300">{{ Planilla::CLASES[$q->planilla->clase] }}</td>
                                            <td class="py-2.5 pr-4 text-gray-600 dark:text-paper-300">{{ Planilla::ESTADOS[$q->planilla->estado] }}</td>
                                            @if ($verImportes)
                                                <td class="py-2.5 text-right tabular-nums text-gray-800 dark:text-paper-100">{{ Dinero::mostrar($q->a_pagar) }}</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            @if ($asistencia)
                <div id="asistencia" class="scroll-mt-6">
                    @include('asistencia.empleados._ficha')
                </div>
            @endif

            <a href="{{ route('personal.empleados') }}" class="inline-block text-sm text-gray-500 hover:underline dark:text-paper-300">← Volver a empleados</a>
        </div>
    </div>
</x-app-layout>
