@props([
    'departamentos',
    'municipios',
    'departamentoId' => null,
    'municipioId' => null,
    // Tercer nivel (división 2024). Si se pasan distritos, se muestra el select de Distrito.
    'distritos' => null,
    'distritoId' => null,
    'distritoRequerido' => false,
    // El departamento es obligatorio en salas y establecimientos, opcional en empresa
    // y clientes (ver los FormRequest correspondientes).
    'departamentoRequerido' => false,
])

{{-- Cascada Departamento → Municipio 2024 → Distrito.

     El municipio filtra por departamento; el DISTRITO filtra por el MUNICIPIO elegido
     (no solo por el departamento). Antes el distrito listaba todo el departamento con el
     nombre de su agrupación como adorno, así que se podía guardar un municipio de
     "Cabañas Este" junto al distrito "Ilobasco" (que es de Cabañas Oeste): un par que
     Hacienda rechaza con «[receptor.direccion.distrito] VALOR NO ES PERMITIDO».

     El municipio se muestra con su nombre FISCAL 2024 (`nombre_fiscal`, ej. "Cabañas
     Oeste"), no con el nombre municipal anterior que sigue guardado en `nombre`.

     Todo se revalida en el servidor: el navegador no es la fuente de la regla. --}}
<div class="contents"
     x-data="{
        departamentoId: @js((string) old('departamento_id', $departamentoId)),
        municipioId: @js((string) old('municipio_id', $municipioId)),
        distritoId: @js((string) old('distrito_id', $distritoId)),
        municipios: @js($municipios->map(fn ($m) => [
            'id' => (string) $m->id,
            'nombre' => $m->nombre_fiscal ?? $m->nombre,
            'codigo' => (string) $m->codigo,
            'departamento_id' => (string) $m->departamento_id,
        ])->values()),
        distritos: @js(($distritos ?? collect())->map(fn ($d) => [
            'id' => (string) $d->id,
            'nombre' => $d->nombre,
            'municipio' => $d->municipio,
            'municipio_codigo' => (string) $d->municipio_codigo,
            'departamento_id' => (string) $d->departamento_id,
        ])->values()),
        get municipiosFiltrados() {
            return this.municipios.filter(m => m.departamento_id === this.departamentoId);
        },
        get municipioElegido() {
            return this.municipios.find(m => m.id === this.municipioId) ?? null;
        },
        {{-- Distritos del MUNICIPIO elegido. Sin municipio elegido no se ofrece ninguno:
             el distrito no tiene sentido fuera de su municipio. --}}
        get distritosFiltrados() {
            const m = this.municipioElegido;
            if (! m) { return []; }
            return this.distritos.filter(d =>
                d.departamento_id === m.departamento_id && d.municipio_codigo === m.codigo);
        },
        onDepartamentoChange() { this.municipioId = ''; this.distritoId = ''; },
        {{-- Al cambiar de municipio se descarta el distrito si ya no le pertenece. --}}
        onMunicipioChange() {
            if (! this.distritosFiltrados.some(d => d.id === this.distritoId)) { this.distritoId = ''; }
        },
     }">
    <div>
        <x-input-label for="departamento_id" value="Departamento{{ $departamentoRequerido ? ' *' : '' }}" />
        <select id="departamento_id" name="departamento_id"
                x-model="departamentoId" x-on:change="onDepartamentoChange()"
                @if($departamentoRequerido) required @endif
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
                x-model="municipioId" x-on:change="onMunicipioChange()"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
            <option value="">— Seleccione —</option>
            <template x-for="m in municipiosFiltrados" :key="m.id">
                <option :value="m.id" x-text="m.nombre"></option>
            </template>
        </select>
        <x-input-error :messages="$errors->get('municipio_id')" class="mt-1" />
        <p class="text-xs text-gray-400 mt-1" x-show="departamentoId === ''">Seleccione primero un departamento.</p>
        <p class="text-xs text-gray-400 mt-1" x-show="departamentoId !== ''">Municipio fiscal 2024 (CAT-013).</p>
    </div>

    @if ($distritos !== null)
        <div>
            <x-input-label for="distrito_id" value="Distrito{{ $distritoRequerido ? ' *' : '' }}" />
            <select id="distrito_id" name="distrito_id"
                    x-model="distritoId" @if($distritoRequerido) :required="true" @endif
                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                <option value="">— Seleccione —</option>
                {{-- Solo el nombre del distrito: el municipio ya está elegido arriba, así
                     que prefijarlo aquí sería redundante y confuso. --}}
                <template x-for="d in distritosFiltrados" :key="d.id">
                    <option :value="d.id" x-text="d.nombre"></option>
                </template>
            </select>
            <x-input-error :messages="$errors->get('distrito_id')" class="mt-1" />
            <p class="text-xs text-gray-400 mt-1" x-show="municipioId === ''">Seleccione primero un municipio.</p>
            <p class="text-xs text-gray-400 mt-1" x-show="municipioId !== '' && distritosFiltrados.length === 0" x-cloak>
                Este municipio no tiene distritos cargados en el catálogo.
            </p>
        </div>
    @endif
</div>
