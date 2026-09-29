@php
    use App\Models\Gastos\Aviso;

    $color = [
        'vencido' => 'border-red-200 bg-red-50',
        'vence' => 'border-amber-200 bg-amber-50',
        'falta_monto' => 'border-sky-200 bg-sky-50',
    ];
    $etiquetaTipo = [
        'vencido' => 'text-red-800',
        'vence' => 'text-amber-800',
        'falta_monto' => 'text-sky-800',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold leading-tight text-gray-800">
                Avisos
                @if ($sinLeer > 0)
                    <span class="ml-1 rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-semibold text-white">{{ $sinLeer }}</span>
                @endif
            </h1>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('gastos.avisos.preferencias') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Preferencias</a>
                <a href="{{ route('gastos.panel') }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Ir a Gastos</a>
            </div>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-4xl space-y-4">
            <x-gastos-aviso />

            <div class="flex flex-wrap items-center justify-between gap-2">
                <nav class="flex gap-1 rounded-md border border-gray-200 bg-white p-1" aria-label="Filtro">
                    <a href="{{ route('gastos.avisos.index') }}"
                       @class(['min-h-11 rounded px-3 py-2 text-sm font-medium', 'bg-indigo-50 text-indigo-700' => ! $todos, 'text-gray-600 hover:bg-gray-50' => $todos])>Sin leer</a>
                    <a href="{{ route('gastos.avisos.index', ['todos' => 1]) }}"
                       @class(['min-h-11 rounded px-3 py-2 text-sm font-medium', 'bg-indigo-50 text-indigo-700' => $todos, 'text-gray-600 hover:bg-gray-50' => ! $todos])>Todos</a>
                </nav>

                @if ($sinLeer > 0)
                    <form method="POST" action="{{ route('gastos.avisos.leer-todos') }}">
                        @csrf
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Marcar todos como leídos
                        </button>
                    </form>
                @endif
            </div>

            @if ($avisos->isEmpty())
                {{-- Bandeja vacía es una buena noticia y se dice así. Nada de «no hay
                     resultados», que suena a que algo falló. --}}
                <div class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-10 text-center">
                    <p class="text-sm font-medium text-gray-700">
                        {{ $todos ? 'No hay avisos.' : 'No tenés avisos sin leer.' }}
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        Los avisos salen de obligaciones con saldo real. Si no hay ninguno, no hay nada por vencer
                        dentro de tu anticipación configurada.
                    </p>
                </div>
            @else
                <ul class="space-y-2">
                    @foreach ($avisos as $aviso)
                        <li @class(['rounded-lg border px-4 py-3', $color[$aviso->tipo] ?? 'border-gray-200 bg-white', 'opacity-60' => $aviso->leido()])>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold uppercase tracking-wide {{ $etiquetaTipo[$aviso->tipo] ?? 'text-gray-500' }}">
                                        {{ Aviso::TIPOS[$aviso->tipo] ?? $aviso->tipo }}
                                    </p>
                                    <p class="text-sm font-medium text-gray-900">{{ $aviso->titulo }}</p>
                                    <p class="text-sm text-gray-600">{{ $aviso->detalle }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $aviso->created_at?->format('Y-m-d H:i') }}</p>
                                </div>

                                <div class="flex flex-none flex-wrap gap-2">
                                    @if ($aviso->gasto)
                                        <a href="{{ route('gastos.show', $aviso->gasto) }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Ver</a>
                                    @endif

                                    @unless ($aviso->leido())
                                        <form method="POST" action="{{ route('gastos.avisos.leer', $aviso) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">Leído</button>
                                        </form>
                                    @endunless
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{ $avisos->links() }}

                <p class="text-xs text-gray-500">
                    Marcar un aviso como leído <strong>no cambia ninguna deuda</strong>: solo limpia esta bandeja.
                    Las obligaciones se pagan desde su ficha, con su comprobante.
                </p>
            @endif
        </div>
    </div>
</x-app-layout>
