@php
    $c = 'block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $etiqueta = 'block text-sm font-medium text-gray-700';
    $dependientes = old('dependientes', $empleado->dependientes ?? []);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold leading-tight text-gray-800">Contrato de {{ $empleado->nombre }}</h1>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-3">
            @if (session('planilla.aviso'))
                <div role="status" class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">{{ session('planilla.aviso') }}</div>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @if (! $empleado->con_descuentos_ley)
                <p role="status" class="rounded-md border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900">
                    Quien va sin descuentos de ley no lleva contrato.
                </p>
            @else
                @foreach ($avisos as $aviso)
                    <p role="status" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">{{ $aviso }}</p>
                @endforeach

                @if ($faltantes !== [])
                    <div role="status" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        Para imprimir el contrato falta: {{ implode(', ', $faltantes) }}.
                    </div>
                @else
                    <a href="{{ route('planilla.empleados.contrato.pdf', $empleado) }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Generar contrato en PDF</a>
                    <p class="text-sm text-gray-600">Sale con la fecha de hoy y el sueldo vigente hoy. No se guarda copia: si cambia un dato, se vuelve a generar.</p>
                @endif

                <form method="POST" action="{{ route('planilla.empleados.contrato.update', $empleado) }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4"
                      x-data="{
                          modelos: @js(config('planilla.contrato.modelos')),
                          // Solo precarga: el dato se puede editar y lo que se guarda es lo que quede escrito.
                          aplicar(clave) {
                              const modelo = this.modelos[clave];
                              if (!modelo) return;
                              ['cargo', 'funciones', 'lugar_trabajo', 'herramientas'].forEach((campo) => {
                                  // Vacío también: no puede quedar el dato del otro modelo.
                                  this.$root.querySelector('#' + campo).value = modelo[campo] || '';
                              });
                          }
                      }">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="modelo" class="{{ $etiqueta }}">Modelo de contrato</label>
                        <select id="modelo" @change="aplicar($event.target.value)" class="{{ $c }}">
                            @foreach (config('planilla.contrato.modelos') as $clave => $opcion)
                                <option value="{{ $clave }}" @selected($clave === $modelo)>{{ $opcion['etiqueta'] }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-sm text-gray-600">Sale de su rol: Ventas si es vendedor o sale a rutas, Producción si no. Cambialo solo si este caso es distinto: reemplaza cargo, funciones, lugar de trabajo y herramientas.</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="sexo" class="{{ $etiqueta }}">Sexo</label>
                            <select id="sexo" name="sexo" class="{{ $c }}">
                                <option value="">Elegí una opción</option>
                                <option value="F" @selected(old('sexo', $empleado->sexo) === 'F')>F</option>
                                <option value="M" @selected(old('sexo', $empleado->sexo) === 'M')>M</option>
                            </select>
                        </div>
                        @foreach (['fecha_nacimiento' => 'Fecha de nacimiento', 'alta_el' => 'Fecha de ingreso', 'dui_expedido_el' => 'Documento expedido el'] as $campo => $texto)
                            <div>
                                <label for="{{ $campo }}" class="{{ $etiqueta }}">{{ $texto }}</label>
                                <input id="{{ $campo }}" name="{{ $campo }}" type="date" value="{{ old($campo, $empleado->$campo?->toDateString()) }}" class="{{ $c }}">
                            </div>
                        @endforeach
                        @foreach (['estado_civil' => ['Estado civil', 30], 'profesion' => ['Profesión u oficio', 120], 'nacionalidad' => ['Nacionalidad', 60], 'cargo' => ['Cargo', 120], 'domicilio' => ['Domicilio', 180], 'residencia' => ['Residencia', 255], 'dui_expedido_en' => ['Documento expedido en', 120], 'lugar_trabajo' => ['Lugar de trabajo', 255]] as $campo => [$texto, $largo])
                            <div>
                                <label for="{{ $campo }}" class="{{ $etiqueta }}">{{ $texto }}</label>
                                <input id="{{ $campo }}" name="{{ $campo }}" maxlength="{{ $largo }}" value="{{ old($campo, $empleado->$campo) }}" class="{{ $c }}">
                            </div>
                        @endforeach
                    </div>

                    @foreach (['funciones' => 'Funciones', 'herramientas' => 'Herramientas y materiales'] as $campo => $texto)
                        <div>
                            <label for="{{ $campo }}" class="{{ $etiqueta }}">{{ $texto }}</label>
                            <textarea id="{{ $campo }}" name="{{ $campo }}" maxlength="2000" rows="3" class="{{ $c }}">{{ old($campo, $empleado->$campo) }}</textarea>
                        </div>
                    @endforeach

                    <fieldset>
                        <legend class="{{ $etiqueta }}">Personas que dependen económicamente (hasta 4)</legend>
                        @for ($i = 0; $i < 4; $i++)
                            <div class="mt-2 grid gap-2 sm:grid-cols-4">
                                @foreach (['nombre' => 'Nombre', 'apellido' => 'Apellido', 'edad' => 'Edad', 'direccion' => 'Dirección'] as $campo => $texto)
                                    <div>
                                        <label for="dep-{{ $i }}-{{ $campo }}" class="{{ $etiqueta }}">{{ $texto }} {{ $i + 1 }}</label>
                                        <input id="dep-{{ $i }}-{{ $campo }}" name="dependientes[{{ $i }}][{{ $campo }}]" value="{{ $dependientes[$i][$campo] ?? '' }}" class="{{ $c }}">
                                    </div>
                                @endforeach
                            </div>
                        @endfor
                    </fieldset>

                    <button class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Guardar datos del contrato</button>
                </form>
            @endif

            <a href="{{ route('personal.empleados.show', 'p'.$empleado->id) }}" class="text-sm text-indigo-700 underline">Volver a la ficha</a>
        </div>
    </div>
</x-app-layout>
