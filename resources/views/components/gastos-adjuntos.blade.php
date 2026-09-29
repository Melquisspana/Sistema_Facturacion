@props(['nombre', 'titulo'])
@php
    // Documento del gasto y comprobante del pago usan este mismo componente con
    // nombres distintos: son respaldos separados y nunca comparten vínculo.
    //
    // Los límites salen de config/gastos.php y viajan a Alpine para avisar ANTES de
    // subir. El candado real es el servidor (RegistrarGastoRequest), que mira el
    // contenido del archivo y no el tipo que declare el navegador.
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    $tipos = collect(config('gastos.mimes'))->map(fn ($ext) => $mimes[$ext] ?? null)->filter()->unique()->values();
    $imagenes = $tipos->reject(fn ($tipo) => $tipo === 'application/pdf')->values();
    $maxArchivos = (int) config('gastos.max_archivos');
    $maxMb = (int) round(config('gastos.max_archivo_kb') / 1024);
    $etiquetas = collect(config('gastos.mimes'))->reject(fn ($e) => $e === 'jpeg')->map(fn ($e) => strtoupper($e))->join(', ', ' o ');

    $limites = [
        'maxArchivos' => $maxArchivos,
        'maxBytes' => (int) config('gastos.max_archivo_kb') * 1024,
        'tipos' => $tipos->all(),
        'avisoTipo' => "Usá archivos {$etiquetas} de hasta {$maxMb} MB.",
    ];
@endphp
<div x-data="gastoAdjuntos(@js($limites))" class="space-y-3" @paste="pegar($event)">
    <p class="text-sm font-medium text-gray-700">{{ $titulo }}</p>
    <div class="rounded-lg border border-dashed border-gray-300 p-4 focus-within:ring-2 focus-within:ring-indigo-500"
         :class="arrastrando && 'bg-indigo-50'" @dragover.prevent="arrastrando = true" @dragleave.prevent="arrastrando = false"
         @drop.prevent="arrastrando = false; agregar($event.dataTransfer.files)" tabindex="0" aria-label="{{ $titulo }}: arrastrar archivos o pegar captura">
        <div class="flex flex-wrap gap-3">
            <label class="inline-flex min-h-11 cursor-pointer items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700">
                Elegir archivos
                <input class="sr-only" type="file" multiple accept="{{ $tipos->join(',') }}"
                       @change="agregar($event.target.files); $event.target.value = ''" aria-label="Elegir {{ mb_strtolower($titulo) }}">
            </label>
            {{-- `capture` abre la cámara del teléfono; en escritorio el navegador lo
                 ignora y ofrece el selector de siempre. --}}
            <label class="inline-flex min-h-11 cursor-pointer items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700">
                Tomar foto
                <input class="sr-only" type="file" accept="{{ $imagenes->join(',') }}" capture="environment"
                       @change="agregar($event.target.files); $event.target.value = ''" aria-label="Tomar foto para {{ mb_strtolower($titulo) }}">
            </label>
        </div>
        {{-- Este es el input que VIAJA en el envío. Los de arriba solo alimentan la
             lista; acá se rearma con DataTransfer para poder quitar uno sin perder
             los demás. --}}
        <input x-ref="archivos" type="file" name="{{ $nombre }}[]" multiple hidden>
        <p class="mt-3 text-sm text-gray-500">También podés arrastrar archivos o pegar una captura aquí.</p>
        <p class="mt-1 text-xs text-gray-500">{{ $etiquetas }} · Hasta {{ $maxArchivos }} archivos de {{ $maxMb }} MB cada uno.</p>
    </div>
    <p x-show="aviso" x-text="aviso" class="text-sm text-red-600" role="alert"></p>
    <ul class="space-y-2">
        <template x-for="(item, i) in archivos" :key="item.url || i">
            <li class="flex min-w-0 items-center gap-3 rounded-md border border-gray-200 p-2">
                <template x-if="item.url"><img :src="item.url" alt="Vista previa del adjunto" class="h-14 w-14 shrink-0 rounded object-cover"></template>
                <span x-show="!item.url" class="text-xs font-semibold text-gray-500">PDF</span>
                <span class="min-w-0 flex-1 break-all text-sm text-gray-700" x-text="item.file.name"></span>
                <button type="button" @click="quitar(i)" class="min-h-11 px-3 text-sm text-red-600" :aria-label="'Quitar ' + item.file.name">Quitar</button>
            </li>
        </template>
    </ul>
    <x-input-error :messages="$errors->get($nombre)" />
    @foreach ($errors->get($nombre.'.*') as $mensajes)<x-input-error :messages="$mensajes" />@endforeach
</div>
