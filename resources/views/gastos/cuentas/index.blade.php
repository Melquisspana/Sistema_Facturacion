@php
    use App\Services\Gastos\Dinero;
    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';
@endphp

{{-- Cuentas con proveedores: una línea por cuenta abierta, con su saldo.

     Solo aparecen las que deben algo. Una cuenta saldada no es una cuenta abierta: si
     apareciera en cero, esta lista crecería para siempre y dejaría de servir para lo
     único que sirve, que es ver a quién se le debe. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">Cuentas con proveedores</h1>
                <p class="text-sm text-gray-500">Compras a crédito y abonos. El saldo es la suma de lo que pasó.</p>
            </div>
            <a href="{{ route('gastos.panel') }}" class="{{ $boton }}">Volver a Por pagar</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-4xl space-y-3">

            @if (session('gastos.aviso'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('gastos.aviso') }}</div>
            @endif

            @forelse ($cuentas as $c)
                <a href="{{ route('gastos.cuentas.show', ['proveedor' => $c->beneficiario, 'moneda' => $c->moneda]) }}"
                   class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 hover:border-indigo-300 hover:bg-indigo-50/30">
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-gray-900">{{ $c->beneficiario }}</p>
                        <p class="text-sm text-gray-500">
                            {{ $c->compras }} {{ $c->compras === 1 ? 'compra abierta' : 'compras abiertas' }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Le debemos</p>
                        <p class="whitespace-nowrap text-lg font-bold tabular-nums text-gray-900">
                            {{ $c->moneda }} {{ Dinero::mostrar($c->saldo) }}
                        </p>
                    </div>
                </a>
            @empty
                <div class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-12 text-center">
                    <p class="text-sm text-gray-600">No hay ninguna cuenta con saldo.</p>
                    <p class="mt-1 text-xs text-gray-500">
                        Una cuenta aparece acá cuando se registra una compra que todavía no se ha pagado del todo.
                    </p>
                    @can('gastos.registrar')
                        <a href="{{ route('gastos.create') }}" class="{{ $boton }} mt-4">Registrar un gasto</a>
                    @endcan
                </div>
            @endforelse

            @if ($cuentas->isNotEmpty())
                <p class="px-1 text-xs text-gray-500">
                    El saldo no se guarda en ningún sitio: se calcula sumando lo que falta de cada compra. Por eso no
                    puede discrepar con el detalle.
                </p>
            @endif
        </div>
    </div>
</x-app-layout>
