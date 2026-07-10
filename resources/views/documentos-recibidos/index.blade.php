<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Documentos recibidos</h2>
            <form method="POST" action="{{ route('documentos-recibidos.sincronizar') }}">
                @csrf
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        @disabled(! $fuenteDisponible)
                        title="Lee el buzón Yahoo/IMAP (solo lectura): no marca leído, no mueve ni borra correos.">
                    {{ $fuenteDisponible ? 'Revisar correos' : 'Configurar correo Yahoo/IMAP' }}
                </button>
            </form>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            @unless ($fuenteDisponible)
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                    El correo de documentos recibidos (Yahoo/IMAP) no está configurado ({{ $fuente }}). La revisión
                    está deshabilitada hasta configurar las variables <code>DOCUMENTOS_RECIBIDOS_MAIL_*</code> en el
                    servidor. El listado de abajo muestra lo ya registrado localmente.
                </div>
            @endunless

            {{-- Pestañas --}}
            @php
                $tabs = [
                    'bandeja' => ['Bandeja recibidos', null],
                    'pendientes' => ['Pendientes contabilidad', $conteos['pendiente']],
                    'enviados' => ['Enviados a contabilidad', $conteos['enviado']],
                    'ignorados' => ['Ignorados', $conteos['ignorado']],
                ];
            @endphp
            <nav class="flex flex-wrap gap-2 border-b border-gray-200">
                @foreach ($tabs as $clave => [$titulo, $conteo])
                    <a href="{{ route('documentos-recibidos.index', ['vista' => $clave]) }}"
                       class="px-4 py-2 text-sm font-medium rounded-t-md {{ $vista === $clave ? 'bg-white border border-b-0 border-gray-200 text-indigo-600' : 'text-gray-500 hover:text-gray-700' }}">
                        {{ $titulo }}
                        @if ($conteo !== null)
                            <span class="ms-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $conteo }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            {{-- Filtros --}}
            <form method="GET" class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl p-4">
                <input type="hidden" name="vista" value="{{ $vista }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Fecha desde</label>
                        <input type="date" name="fecha_desde" value="{{ request('fecha_desde') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Fecha hasta</label>
                        <input type="date" name="fecha_hasta" value="{{ request('fecha_hasta') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Emisor</label>
                        <input type="text" name="emisor" value="{{ request('emisor') }}" placeholder="proveedor…" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Tipo</label>
                        <select name="tipo_documento" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Todos</option>
                            <option value="03" @selected(request('tipo_documento') === '03')>CCF</option>
                            <option value="01" @selected(request('tipo_documento') === '01')>Factura</option>
                            <option value="05" @selected(request('tipo_documento') === '05')>Nota de crédito</option>
                            <option value="11" @selected(request('tipo_documento') === '11')>Factura de exportación</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600">Estado</label>
                        <select name="estado" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            <option value="">Cualquiera</option>
                            <option value="pendiente" @selected(request('estado') === 'pendiente')>Pendiente</option>
                            <option value="enviado" @selected(request('estado') === 'enviado')>Enviado</option>
                            <option value="ignorado" @selected(request('estado') === 'ignorado')>Ignorado</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">Aplicar filtros</button>
                </div>
            </form>

            {{-- Listado --}}
            <div class="bg-white shadow-sm ring-1 ring-gray-200 sm:rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                                <th class="py-3 px-3">Fecha correo</th>
                                <th class="py-3 px-3">Emisor</th>
                                <th class="py-3 px-3">Tipo</th>
                                <th class="py-3 px-3">Número de control</th>
                                <th class="py-3 px-3 text-right">Total</th>
                                <th class="py-3 px-3 text-center">Adjuntos</th>
                                <th class="py-3 px-3 text-center">Estado</th>
                                <th class="py-3 px-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                                $badge = ['pendiente' => 'bg-amber-100 text-amber-700', 'enviado' => 'bg-green-100 text-green-700', 'ignorado' => 'bg-gray-100 text-gray-500'];
                            @endphp
                            @forelse ($documentos as $doc)
                                <tr class="hover:bg-gray-50 {{ $doc->estado === 'ignorado' ? 'opacity-60' : '' }}">
                                    <td class="py-2 px-3 text-gray-600">{{ optional($doc->fecha_correo)->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-2 px-3">
                                        <div class="font-medium text-gray-800">{{ $doc->emisor_nombre ?? $doc->remitente ?? '—' }}</div>
                                        <div class="text-xs text-gray-500">{{ $doc->emisor_nit ? 'NIT '.$doc->emisor_nit : '' }}{{ $doc->emisor_nrc ? ' · NRC '.$doc->emisor_nrc : '' }}</div>
                                    </td>
                                    <td class="py-2 px-3 text-gray-600">{{ $doc->tipoLabel() }}</td>
                                    <td class="py-2 px-3 font-mono text-xs text-gray-600">{{ $doc->numero_control ?? '—' }}</td>
                                    <td class="py-2 px-3 text-right text-gray-700">{{ $doc->total !== null ? '$'.number_format((float) $doc->total, 2) : '—' }}</td>
                                    <td class="py-2 px-3 text-center">
                                        <span class="inline-flex rounded px-1.5 py-0.5 text-xs {{ $doc->tiene_pdf ? 'bg-rose-100 text-rose-700' : 'bg-gray-100 text-gray-400' }}">PDF</span>
                                        <span class="inline-flex rounded px-1.5 py-0.5 text-xs {{ $doc->tiene_json ? 'bg-sky-100 text-sky-700' : 'bg-gray-100 text-gray-400' }}">JSON</span>
                                    </td>
                                    <td class="py-2 px-3 text-center">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[$doc->estado] ?? 'bg-gray-100 text-gray-600' }}">{{ ucfirst($doc->estado) }}</span>
                                    </td>
                                    <td class="py-2 px-3">
                                        <div class="flex items-center justify-end gap-2">
                                            @if ($doc->estado !== 'pendiente')
                                                <form method="POST" action="{{ route('documentos-recibidos.pendiente', $doc) }}">
                                                    @csrf @method('PATCH')
                                                    <button class="text-indigo-600 hover:underline text-xs">Marcar pendiente</button>
                                                </form>
                                            @endif
                                            @if ($doc->estado !== 'ignorado')
                                                <form method="POST" action="{{ route('documentos-recibidos.ignorar', $doc) }}">
                                                    @csrf @method('PATCH')
                                                    <button class="text-gray-500 hover:underline text-xs">Ignorar</button>
                                                </form>
                                            @endif
                                            {{-- Futuro: el envío a contabilidad llega en una fase posterior. --}}
                                            <button type="button" disabled title="Pendiente: el envío a contabilidad llega en una fase posterior."
                                                    class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-400 cursor-not-allowed">Enviar a contabilidad</button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="py-10 text-center text-gray-400">
                                    No hay documentos {{ $vista === 'bandeja' ? 'registrados' : 'en esta vista' }}.
                                    @if ($fuenteDisponible) Usá "Revisar correos" para buscar nuevos. @endif
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($documentos->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100">{{ $documentos->links() }}</div>
                @endif
            </div>

            <p class="text-xs text-gray-400">
                Fase 1: solo lectura y preparación. La revisión del buzón (Yahoo/IMAP) es de solo lectura (no marca
                leído, no mueve ni borra correos) y el envío a contabilidad se implementa en una fase posterior.
            </p>
        </div>
    </div>
</x-app-layout>
