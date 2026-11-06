<x-app-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 dark:text-paper-100 leading-tight">TXT de pago</h1>
    </x-slot>
    <div class="py-8">
        <div class="max-w-full xl:max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 space-y-6 text-sm text-gray-700 dark:text-paper-100">
            @if ($soloRevisar)
                <p class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-700" role="status">Revisión: todavía no se aplicó nada.</p>
            @endif
            <a href="{{ route('cobros.index', ['cliente_id' => $cliente->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Volver a Cobros</a>
            @if (isset($correo))
                <div class="bg-white dark:bg-ink-800 shadow sm:rounded-lg p-6 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <caption class="text-left font-medium">TXT encontrados en el correo</caption>
                        <thead><tr>
                            @foreach (['Archivo', 'Fecha del correo', 'Asunto', 'Estado'] as $titulo)
                                <th scope="col" class="p-2 text-left font-medium">{{ $titulo }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @foreach ($correo as $mensaje)
                                <tr>
                                    <td class="p-2">{{ $mensaje['nombre'] }}</td>
                                    <td class="p-2">{{ $mensaje['fecha']?->format('d/m/Y H:i') ?? '—' }}</td>
                                    <td class="p-2">{{ $mensaje['asunto'] }}</td>
                                    <td class="p-2">{{ $mensaje['estado'] === 'nuevo' ? 'Nuevo' : 'Ya aplicado' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if (collect($correo)->where('estado', 'nuevo')->isEmpty())
                        <p role="status">No se encontraron TXT de pago nuevos en el correo.</p>
                    @endif
                </div>
            @endif
            @php($paraAplicar = collect($archivos)->where('estado', 'aplicado')->unique('hash'))
            @if ($soloRevisar && $paraAplicar->isNotEmpty())
                <form method="POST" action="{{ route('cobros.pagos.aplicar', $cliente) }}">
                    @csrf
                    @foreach ($paraAplicar as $archivo)
                        <input type="hidden" name="hashes[]" value="{{ $archivo['hash'] }}">
                    @endforeach
                    <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">Aplicar los {{ $paraAplicar->count() }}</button>
                </form>
            @endif
            {{-- Una tarjeta por TXT, encabezada por el PPQ que paga: el TXT casi siempre va con un quedan. --}}
            @foreach ($archivos as $archivo)
                @if ($archivo['estado'] === 'aplicado')
                    @include('cobros.partials.informe-txt', ['informe' => $archivo['informe'], 'lotesPpq' => $archivo['lotes'], 'pendientes' => $archivo['pendientes']])
                @else
                    <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
                        <strong class="font-mono">{{ $archivo['nombre'] }}</strong>: {{ $archivo['mensaje'] }}
                    </div>
                @endif
            @endforeach
            @if (! $soloRevisar)
                <a href="{{ route('cobros.estado-cobro', $cliente) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Descargar estado de cobro en Excel</a>
            @endif
        </div>
    </div>
</x-app-layout>
