@php
    use App\Services\Gastos\Dinero;

    $campo = 'block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 min-h-11';
    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50';

    $abiertasJs = collect($abiertas)->map(fn ($a) => $a + ['pendiente_txt' => Dinero::decimal($a['pendiente'])])->values();
@endphp

{{-- La cuenta de un proveedor: saldo arriba, dos acciones, y la libreta debajo.

     Las compras suben el saldo y los abonos lo bajan. No hay ninguna deuda mensual
     automática: el saldo es la suma de lo que de verdad pasó.

     ── El reparto del abono ──

     Por defecto va a lo más antiguo primero, que es lo que hace cualquiera con una
     libreta. Pero se MUESTRA antes de confirmar y se puede cambiar: cuando un pago
     corresponde a una compra concreta, hay que poder decirlo. Con dinero, adivinar y no
     dejar corregir es peor que preguntar. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold leading-tight text-gray-800">{{ $proveedor }}</h1>
                <p class="text-sm text-gray-500">Cuenta abierta · {{ $moneda }}</p>
            </div>
            <a href="{{ route('gastos.cuentas') }}" class="{{ $boton }}">Todas las cuentas</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6"
         x-data="{
             panel: null,
             abiertas: @js($abiertasJs),
             dirigido: false,
             abono: '',
             n(v) { const x = parseFloat(String(v ?? '').replace(',', '.')); return isNaN(x) ? 0 : x; },
             m(v) { return v.toFixed(2); },
             /* El reparto propuesto: lo más antiguo primero. Es el mismo criterio que
                aplica el servidor, replicado acá solo para ENSEÑARLO antes de confirmar. */
             plan() {
                 let resto = Math.round(this.n(this.abono) * 100);
                 return this.abiertas.map(a => {
                     const aplica = Math.min(resto, a.pendiente);
                     resto -= aplica;
                     return { ...a, aplica: aplica, queda: a.pendiente - aplica };
                 });
             },
             sobrante() {
                 const total = this.abiertas.reduce((s, a) => s + a.pendiente, 0);
                 return Math.max(Math.round(this.n(this.abono) * 100) - total, 0);
             },
         }">
        <div class="mx-auto max-w-3xl space-y-3">

            @if (session('gastos.aviso'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('gastos.aviso') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- El saldo, arriba y grande: es lo que se viene a ver. --}}
            <section class="rounded-lg border border-gray-200 bg-white p-5">
                <p class="text-xs uppercase tracking-wide text-gray-500">Le debemos</p>
                <p class="mt-1 text-3xl font-bold tabular-nums text-gray-900">{{ $moneda }} {{ Dinero::mostrar($saldo) }}</p>
                <p class="mt-1 text-xs text-gray-500">
                    {{ count($abiertas) }} {{ count($abiertas) === 1 ? 'compra abierta' : 'compras abiertas' }}
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($puedeRegistrar)
                        <button type="button" @click="panel = panel === 'compra' ? null : 'compra'"
                                class="inline-flex min-h-11 flex-1 items-center justify-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 sm:flex-none">
                            ＋ Agregar compra
                        </button>
                    @endif
                    @if ($puedePagar && $saldo > 0)
                        <button type="button" @click="panel = panel === 'abono' ? null : 'abono'"
                                class="inline-flex min-h-11 flex-1 items-center justify-center rounded-md bg-emerald-700 px-5 text-sm font-semibold text-white hover:bg-emerald-800 sm:flex-none">
                            ✓ Registrar abono
                        </button>
                    @endif
                </div>
            </section>

            {{-- ══════════ Agregar compra ══════════ --}}
            @if ($puedeRegistrar)
                <section x-show="panel === 'compra'" x-cloak class="rounded-lg border border-indigo-200 bg-white p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Agregar compra</h2>
                    <p class="mt-1 text-sm text-gray-600">A {{ $proveedor }} · {{ $moneda }}</p>

                    <form method="POST" action="{{ route('gastos.cuentas.compras') }}" class="mt-4 space-y-4">
                        @csrf
                        {{-- El origen es una PALABRA de una lista cerrada, no una URL: se
                             vuelve a esta misma libreta. Ver App\Services\Gastos\DestinoVuelta. --}}
                        <input type="hidden" name="origen" value="cuenta">
                        <input type="hidden" name="clave" value="{{ $clave }}">
                        <input type="hidden" name="beneficiario" value="{{ $proveedor }}">
                        <input type="hidden" name="moneda" value="{{ $moneda }}">

                        <div>
                            <label for="concepto" class="block text-sm font-medium text-gray-700">¿Qué compraste?</label>
                            <input id="concepto" name="concepto" type="text" maxlength="200" required
                                   placeholder="Pepitoria, maní…" class="{{ $campo }} mt-1">
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="importe-compra" class="block text-sm font-medium text-gray-700">Importe</label>
                                <input id="importe-compra" name="importe" type="text" inputmode="decimal" required
                                       placeholder="0.00" class="{{ $campo }} mt-1 text-right text-lg tabular-nums">
                            </div>
                            <div>
                                <label for="fecha-compra" class="block text-sm font-medium text-gray-700">Fecha</label>
                                <input id="fecha-compra" name="fecha" type="date" required max="{{ $hoy }}"
                                       value="{{ $hoy }}" class="{{ $campo }} mt-1">
                            </div>
                        </div>

                        <fieldset>
                            <legend class="block text-sm font-medium text-gray-700">¿Para quién es?</legend>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label class="cursor-pointer">
                                    <input type="radio" name="ambito" value="empresarial" class="peer sr-only" checked>
                                    <span class="inline-flex min-h-11 items-center rounded-md border border-gray-300 px-4 text-sm text-gray-700
                                                 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:font-semibold peer-checked:text-indigo-800">De la empresa</span>
                                </label>
                                @if ($vePersonales)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="ambito" value="personal" class="peer sr-only">
                                        <span class="inline-flex min-h-11 items-center rounded-md border border-gray-300 px-4 text-sm text-gray-700
                                                     peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:font-semibold peer-checked:text-indigo-800">Personal o de la casa</span>
                                    </label>
                                @endif
                            </div>
                        </fieldset>

                        {{-- El vencimiento va acá dentro, y es opcional: una cuenta abierta
                             normalmente no tiene fecha, pero una compra concreta sí puede
                             haberse pactado para una. No se exige y no se inventa. --}}
                        <details class="rounded-md border border-gray-200 bg-gray-50 p-3">
                            <summary class="cursor-pointer text-sm font-medium text-gray-700">
                                Vencimiento y categoría <span class="font-normal text-gray-500">· opcionales</span>
                            </summary>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="vence" class="block text-xs text-gray-600">¿Quedaron en una fecha?</label>
                                    <input id="vence" name="vence" type="date" class="{{ $campo }} mt-1">
                                    <p class="mt-1 text-xs text-gray-500">Vacío = cuenta abierta, sin fecha.</p>
                                </div>
                                <div>
                                    <label for="categoria-compra" class="block text-xs text-gray-600">Categoría</label>
                                    <input id="categoria-compra" name="categoria" type="text" maxlength="100"
                                           class="{{ $campo }} mt-1">
                                </div>
                            </div>
                        </details>

                        <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
                            <button type="button" @click="panel = null" class="{{ $boton }}">Cancelar</button>
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700">
                                Agregar al saldo
                            </button>
                        </div>
                    </form>
                </section>
            @endif

            {{-- ══════════ Registrar abono ══════════ --}}
            @if ($puedePagar && $saldo > 0)
                <section x-show="panel === 'abono'" x-cloak class="rounded-lg border border-emerald-200 bg-white p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Registrar abono</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        A {{ $proveedor }} · le debemos {{ $moneda }} {{ Dinero::mostrar($saldo) }}
                    </p>

                    <form method="POST" action="{{ route('gastos.cuentas.abonos') }}" class="mt-4 space-y-4">
                        @csrf
                        {{-- Abonar varias compras seguidas del mismo proveedor no debe
                             sacar de la libreta cada vez. --}}
                        <input type="hidden" name="origen" value="cuenta">
                        <input type="hidden" name="clave" value="{{ $claveAbono }}">
                        <input type="hidden" name="beneficiario" value="{{ $proveedor }}">
                        <input type="hidden" name="moneda" value="{{ $moneda }}">

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="importe-abono" class="block text-sm font-medium text-gray-700">¿Cuánto le abonaste?</label>
                                <input id="importe-abono" name="importe" type="text" inputmode="decimal" required
                                       x-model="abono" placeholder="0.00"
                                       class="{{ $campo }} mt-1 text-right text-lg tabular-nums">
                                <p class="mt-1 text-xs" x-show="n(abono) > 0" x-cloak
                                   :class="sobrante() > 0 ? 'text-red-700' : 'text-gray-500'"
                                   x-text="sobrante() > 0
                                       ? 'Se pasa por {{ $moneda }} ' + m(sobrante() / 100) + ' de lo que se le debe.'
                                       : 'Quedarían {{ $moneda }} ' + m(({{ $saldo }} - Math.round(n(abono) * 100)) / 100)"></p>
                            </div>
                            <div>
                                <label for="fecha-abono" class="block text-sm font-medium text-gray-700">Fecha</label>
                                <input id="fecha-abono" name="fecha" type="date" required max="{{ $hoy }}"
                                       value="{{ $hoy }}" class="{{ $campo }} mt-1">
                            </div>
                        </div>

                        <fieldset>
                            <legend class="block text-sm font-medium text-gray-700">¿Cómo le pagaste?</legend>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach (config('gastos.metodos') as $clave => $texto)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="metodo" value="{{ $clave }}" class="peer sr-only"
                                               @checked($clave === 'transferencia')>
                                        <span class="inline-flex min-h-11 items-center rounded-md border border-gray-300 px-4 text-sm text-gray-700
                                                     peer-checked:border-emerald-600 peer-checked:bg-emerald-50 peer-checked:font-semibold peer-checked:text-emerald-800">{{ $texto }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        {{-- El reparto: se enseña SIEMPRE que haya algo escrito. Quien paga
                             tiene que poder ver contra qué compras va su dinero. --}}
                        <div x-show="n(abono) > 0 && sobrante() === 0" x-cloak
                             class="rounded-md border border-gray-200 bg-gray-50 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-gray-700">Se aplicaría así</p>
                                <label class="flex cursor-pointer items-center gap-2 text-xs text-gray-600">
                                    <input type="checkbox" name="dirigido" value="1" x-model="dirigido"
                                           class="rounded border-gray-300 text-indigo-600">
                                    Elegir yo a qué compra va
                                </label>
                            </div>

                            <div class="mt-2 space-y-1.5">
                                <template x-for="(l, i) in plan()" :key="l.cuota_id">
                                    <div class="flex items-center gap-3 rounded border border-gray-200 bg-white p-2 text-sm">
                                        <input type="hidden" :name="'reparto['+i+'][cuota_id]'" :value="l.cuota_id">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-gray-800" x-text="l.concepto"></span>
                                            <span class="block text-xs text-gray-500">
                                                <span x-text="l.fecha"></span>
                                                <template x-if="l.vence"><span> · vence <span x-text="l.vence"></span></span></template>
                                                · pendiente <span x-text="l.pendiente_txt"></span>
                                            </span>
                                        </span>
                                        <template x-if="dirigido">
                                            <input type="text" inputmode="decimal" :name="'reparto['+i+'][importe]'"
                                                   :value="m(l.aplica / 100)"
                                                   class="w-28 rounded-md border-gray-300 text-right tabular-nums">
                                        </template>
                                        <template x-if="! dirigido">
                                            <span class="w-28 text-right tabular-nums"
                                                  :class="l.aplica > 0 ? 'text-gray-900 font-medium' : 'text-gray-300'"
                                                  x-text="l.aplica > 0 ? m(l.aplica / 100) : '—'"></span>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <p class="mt-2 text-xs text-gray-500" x-show="! dirigido">
                                De la compra más antigua a la más nueva. Marcá la casilla si este pago corresponde a
                                una compra concreta.
                            </p>
                        </div>

                        <details class="rounded-md border border-gray-200 bg-gray-50 p-3">
                            <summary class="cursor-pointer text-sm font-medium text-gray-700">
                                Referencia y comprobante <span class="font-normal text-gray-500">· opcionales</span>
                            </summary>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="referencia" class="block text-xs text-gray-600">Número de transferencia</label>
                                    <input id="referencia" name="referencia" type="text" maxlength="180" class="{{ $campo }} mt-1">
                                </div>
                                <div>
                                    <label for="sin-comp" class="block text-xs text-gray-600">¿No hay comprobante?</label>
                                    <input id="sin-comp" name="sin_comprobante" type="text" maxlength="250" class="{{ $campo }} mt-1">
                                </div>
                            </div>
                        </details>

                        <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
                            <button type="button" @click="panel = null" class="{{ $boton }}">Cancelar</button>
                            <button type="submit" :disabled="n(abono) <= 0 || sobrante() > 0"
                                    class="inline-flex min-h-11 items-center rounded-md bg-emerald-700 px-5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                                Registrar abono
                            </button>
                        </div>
                    </form>
                </section>
            @endif

            {{-- ══════════ La libreta ══════════ --}}
            <section class="rounded-lg border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Movimiento</h2>

                <div class="mt-3 divide-y divide-gray-100">
                    @forelse ($movimientos as $m)
                        <div class="flex items-baseline gap-3 py-2.5 text-sm">
                            <span class="w-20 shrink-0 tabular-nums text-xs text-gray-500">{{ $m['fecha']?->format('d/m/Y') }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-gray-800">{{ $m['concepto'] }}</span>
                                @if ($m['vence'])
                                    <span class="block text-xs text-gray-500">vence el {{ $m['vence'] }}</span>
                                @endif
                            </span>
                            <span class="whitespace-nowrap tabular-nums {{ $m['tipo'] === 'compra' ? 'text-gray-900' : 'text-emerald-700' }}">
                                {{ $m['tipo'] === 'compra' ? '+' : '−' }} {{ Dinero::mostrar($m['importe']) }}
                            </span>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-gray-500">Todavía no hay movimientos en esta cuenta.</p>
                    @endforelse
                </div>

                @if ($movimientos !== [])
                    <div class="mt-3 flex items-baseline justify-between border-t-2 border-gray-300 pt-3">
                        <span class="font-semibold text-gray-900">Saldo</span>
                        <span class="text-lg font-bold tabular-nums text-gray-900">{{ $moneda }} {{ Dinero::mostrar($saldo) }}</span>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
