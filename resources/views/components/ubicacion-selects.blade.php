@props([
    'departamentos',
    'municipios',
    'departamentoId' => null,
    'municipioId' => null,
    // Tercer nivel (división 2024). Si se pasan distritos, se muestra el select de Distrito.
    'distritos' => null,
    'distritoId' => null,
    'distritoRequerido' => false,
])

{{-- Selects dependientes: el municipio se filtra por el departamento elegido.
     Si se proveen distritos, se agrega un tercer select (Distrito) filtrado por
     departamento, con la etiqueta "Municipio 2024 — Distrito". Se valida en servidor. --}}
<div class="contents"
     x-data="{
        departamentoId: @js((string) old('departamento_id', $departamentoId)),
        municipioId: @js((string) old('municipio_id', $municipioId)),
        distritoId: @js((string) old('distrito_id', $distritoId)),
        municipios: @js($municipios->map(fn ($m) => [
            'id' => (string) $m->id,
            'nombre' => $m->nombre,
            'departamento_id' => (string) $m->departamento_id,
        ])->values()),
        distritos: @js(($distritos ?? collect())->map(fn ($d) => [
            'id' => (string) $d->id,
            'nombre' => $d->nombre,
            'municipio' => $d->municipio,
            'departamento_id' => (string) $d->departamento_id,
        ])->values()),
        get municipiosFiltrados() {
            return this.municipios.filter(m => m.departamento_id === this.departamentoId);
        },
        get distritosFiltrados() {
            return this.distritos.filter(d => d.departamento_id === this.departamentoId);
        },
     }">
    <div>
        <x-input-label for="departamento_id" value="Departamento" />
        <select id="departamento_id" name="departamento_id"
                x-model="departamentoId" x-on:change="municipioId=''; distritoId=''"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
            <option value="">— Seleccione —</option>
            @foreach ($departamentos as $depto)
                <option value="{{ $depto->id }}">{{ $depto->nombre }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('departamento_id')" class="mt-1" />
    </div>

    <div>
        <x-input-label for="municipio_id" value="Municipio" />
        <select id="municipio_id" name="municipio_id"
                x-model="municipioId"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
            <option value="">— Seleccione —</option>
            <template x-for="m in municipiosFiltrados" :key="m.id">
                <option :value="m.id" x-text="m.nombre"></option>
            </template>
        </select>
        <x-input-error :messages="$errors->get('municipio_id')" class="mt-1" />
        <p class="text-xs text-gray-400 mt-1" x-show="departamentoId === ''">Seleccione primero un departamento.</p>
    </div>

    @if ($distritos !== null)
        <div>
            <x-input-label for="distrito_id" value="Distrito{{ $distritoRequerido ? ' *' : '' }}" />
            <select id="distrito_id" name="distrito_id"
                    x-model="distritoId" @if($distritoRequerido) :required="true" @endif
                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                <option value="">— Seleccione —</option>
                <template x-for="d in distritosFiltrados" :key="d.id">
                    <option :value="d.id" x-text="d.municipio + ' — ' + d.nombre"></option>
                </template>
            </select>
            <x-input-error :messages="$errors->get('distrito_id')" class="mt-1" />
            <p class="text-xs text-gray-400 mt-1" x-show="departamentoId === ''">Seleccione primero un departamento.</p>
        </div>
    @endif
</div>
