{{-- Campos compartidos por el alta y la edición de una salida.

     NO incluye `estado` ni `fecha_fin_real`: el estado se mueve con las acciones
     del detalle (terminar / cancelar) y la fecha real la escribe el acto de
     terminar. Quién la encabeza volvió el 03/11/2026 con «Armar salida» (opcional).

     Al ARMAR, el día es «Hoy» u «Otro día» (hoy o uno que viene): para hoy sale en
     camino, para otro día queda planificada. Sin JavaScript: el servidor lee `dia`. La fecha se
     ve solo con «Otro día» (Alpine); sin JavaScript se ve siempre y con «Hoy» se ignora. --}}
@php
    $salida = $salida ?? null;
    $elegidos = old('personal', $salida?->participantes->pluck('rutas_personal_id')->all() ?? []);
    $encabeza = old('responsable_id', $salida?->participantes->first(fn ($p) => $p->esResponsable())?->rutas_personal_id);
    $hoy = \App\Support\HoraNegocio::fechaHoy();
    $dia = old('dia', 'hoy');
@endphp

<div>
    <label for="ruta_id" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Ruta</label>
    <select name="ruta_id" id="ruta_id" required
            class="mt-1 min-h-11 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <option value="">Elegí una ruta…</option>
        @foreach ($rutas as $r)
            <option value="{{ $r->id }}" @selected(old('ruta_id', $salida->ruta_id ?? '') == $r->id)>{{ $r->nombre }}</option>
        @endforeach
    </select>
    @if ($rutas->isEmpty())
        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
            No hay rutas activas. <a href="{{ route('rutas.rutas.create') }}" class="underline">Creá una primero</a>.
        </p>
    @endif
    <x-input-error :messages="$errors->get('ruta_id')" class="mt-1" />
</div>

<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
    @if ($salida === null)
        <fieldset x-data="{ dia: @js($dia === 'otro' ? 'otro' : 'hoy') }">
            <legend class="block text-sm font-medium text-gray-700">Día</legend>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <label class="cursor-pointer">
                    <input type="radio" name="dia" value="hoy" x-model="dia" @checked($dia !== 'otro') class="peer sr-only">
                    <span class="inline-flex min-h-11 items-center rounded-full px-4 text-sm ring-1 ring-gray-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-indigo-600">Hoy</span>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="dia" value="otro" x-model="dia" @checked($dia === 'otro') class="peer sr-only">
                    <span class="inline-flex min-h-11 items-center rounded-full px-4 text-sm ring-1 ring-gray-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-indigo-600">Otro día</span>
                </label>
                <div x-show="dia === 'otro'">
                    <label for="fecha_inicio" class="sr-only">Fecha del otro día</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" min="{{ $hoy }}"
                           value="{{ old('fecha_inicio', $hoy) }}"
                           class="min-h-11 rounded-md border-gray-300 text-sm">
                </div>
            </div>
            <p class="mt-1 text-xs text-gray-500">Para hoy sale en camino. Para otro día queda planificada hasta ese día.</p>
            <x-input-error :messages="$errors->get('fecha_inicio')" class="mt-1" />
        </fieldset>
    @else
        <div>
            <label for="fecha_inicio" class="block text-sm font-medium text-gray-700 dark:text-paper-100">Fecha de inicio</label>
            <input type="date" name="fecha_inicio" id="fecha_inicio"
                   value="{{ old('fecha_inicio', $salida->fecha_inicio?->toDateString()) }}"
                   class="mt-1 min-h-11 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            <x-input-error :messages="$errors->get('fecha_inicio')" class="mt-1" />
        </div>
    @endif

    <div>
        <label for="fecha_fin_estimada" class="block text-sm font-medium text-gray-700 dark:text-paper-100">
            Regreso estimado <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
        </label>
        <input type="date" name="fecha_fin_estimada" id="fecha_fin_estimada"
               value="{{ old('fecha_fin_estimada', $salida?->fecha_fin_estimada?->toDateString() ?? '') }}"
               class="mt-1 min-h-11 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
        <p class="mt-1 text-xs text-gray-400 dark:text-paper-500">Si se quedan varios días (por ejemplo San Miguel o Santa Ana), poné el día que regresan. Cada día se termina aparte y el dinero se entrega al terminar la ruta.</p>
        <x-input-error :messages="$errors->get('fecha_fin_estimada')" class="mt-1" />
    </div>
</div>

<div>
    <span class="block text-sm font-medium text-gray-700 dark:text-paper-100">¿Quiénes van?</span>
    <p class="mt-0.5 text-xs text-gray-400 dark:text-paper-500">
        Quiénes van en esta salida. Puede ir más de una persona, y nadie tiene ruta fija.
    </p>

    {{-- Sin alto máximo: la lista se ve entera, sin un desplazamiento propio que esconda gente. --}}
    <div class="mt-2 rounded-md border border-gray-200 dark:border-ink-600">
        @forelse ($personal as $persona)
            <label class="flex min-h-11 cursor-pointer items-center gap-2.5 border-b border-gray-100 px-3 py-2 last:border-0 hover:bg-gray-50 dark:border-ink-700 dark:hover:bg-ink-700">
                <input type="checkbox" name="personal[]" value="{{ $persona->id }}"
                       @checked(in_array($persona->id, $elegidos))
                       class="shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-ink-600 dark:bg-ink-800">
                <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="text-sm text-gray-700 dark:text-paper-100">{{ $persona->nombre }}</span>
                    @foreach ($persona->funcionesEnum() as $funcion)
                        <span class="whitespace-nowrap rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $funcion->clase() }}">{{ $funcion->label() }}</span>
                    @endforeach
                </span>
            </label>
        @empty
            <p class="px-3 py-4 text-center text-sm text-gray-500 dark:text-paper-300">
                No hay personal de campo activo.
                @if (auth()->user()->can('planilla.ver') && auth()->user()->can('rutas.personal.ver'))
                    <a href="{{ route('rutas.personal.index') }}" class="underline">Agregá vendedores en Personal</a>.
                @else
                    Pedile a la administración que agregue uno.
                @endif
            </p>
        @endforelse
    </div>
    <x-input-error :messages="$errors->get('personal')" class="mt-1" />
</div>

<div>
    <label for="responsable_id" class="block text-sm font-medium text-gray-700">
        ¿Quién la encabeza? <span class="font-normal text-gray-500">(opcional)</span>
    </label>
    <select name="responsable_id" id="responsable_id"
            class="mt-1 min-h-11 w-full rounded-md border-gray-300 text-sm">
        <option value="">Nadie en especial</option>
        @foreach ($personal as $persona)
            <option value="{{ $persona->id }}" @selected((string) $encabeza === (string) $persona->id)>{{ $persona->nombre }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-500">Tiene que ir en la salida.</p>
    <x-input-error :messages="$errors->get('responsable_id')" class="mt-1" />
</div>

<div>
    <label for="observaciones" class="block text-sm font-medium text-gray-700 dark:text-paper-100">
        Observaciones <span class="font-normal text-gray-400 dark:text-paper-500">(opcional)</span>
    </label>
    <textarea name="observaciones" id="observaciones" rows="3" maxlength="1000"
              class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">{{ old('observaciones', $salida->observaciones ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('observaciones')" class="mt-1" />
</div>
