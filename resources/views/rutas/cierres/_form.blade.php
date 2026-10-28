<a href="{{ route($prefijo.'show', $salida) }}" class="inline-flex min-h-11 items-center text-sm underline dark:text-paper-300">Volver a la salida</a>
<h1 class="mt-2 text-3xl font-bold dark:text-paper-100">{{ $salida->ruta->nombre }}</h1>
<p class="mt-1 text-gray-600 dark:text-paper-300">Cierre del día · {{ \Illuminate\Support\Carbon::parse($fecha)->translatedFormat('d M Y') }}</p>
@if ($errors->any())
    <div role="alert" class="mt-4 rounded-xl bg-red-50 p-4 text-red-800 dark:bg-ink-800 dark:text-red-300"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if ($cierre?->estaCerrado())
    @include('rutas.cierres._cerrado')
    @if (! $modoVendedor)
        <form method="POST" action="{{ route('rutas.salidas.reabrir', [$salida, $fecha]) }}" class="mt-6 space-y-3">
            @csrf
            <label for="motivo" class="block font-semibold dark:text-paper-100">Motivo de reapertura</label>
            <input id="motivo" name="motivo" value="{{ old('motivo') }}" required minlength="5" maxlength="300" class="min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
            <button class="min-h-11 rounded-xl bg-gray-800 px-5 py-3 font-semibold text-white dark:bg-paper-100 dark:text-ink-900">Reabrir el día</button>
        </form>
    @endif
@else
    @if ($cierre?->reabierto_en)
        <p class="mt-4 text-amber-800 dark:text-amber-300">Día reabierto: {{ $cierre->reabierto_motivo }}</p>
    @endif
    <dl class="mt-5 grid grid-cols-3 gap-2 sm:gap-3">
        @foreach (\App\Enums\FormaCobroEntrega::cases() as $forma)
            <div class="rounded-xl bg-white p-3 ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600 sm:p-4">
                <dt class="text-xs text-gray-600 sm:text-sm dark:text-paper-300">{{ $forma === \App\Enums\FormaCobroEntrega::Cheque ? 'Cheques' : ($forma === \App\Enums\FormaCobroEntrega::Transferencia ? 'Transferencias' : 'Efectivo') }}</dt>
                <dd class="mt-1 text-lg font-bold tabular-nums dark:text-paper-100 sm:text-2xl" data-total-forma="{{ $forma->value }}">${{ number_format((float) $entregas->filter(fn ($e) => $e->forma_cobro === $forma)->sum('monto_cobrado'), 2) }}</dd>
            </div>
        @endforeach
    </dl>
    @if ($pendientes)
        <p class="mt-4 text-sm text-amber-800 dark:text-amber-300">{{ $pendientes }} sin marcar siguen en la salida.</p>
    @endif
    <form method="POST" action="{{ route($prefijo.'cerrar', $salida) }}" class="mt-5 space-y-5" id="cierre-dia">
        @csrf
        <input type="hidden" name="fecha" value="{{ $fecha }}">
        @forelse ($entregas->groupBy('cliente_sucursal_id') as $filas)
            <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-200 dark:bg-ink-800 dark:ring-ink-600">
                <h2 class="border-b border-gray-100 px-4 py-3 text-lg font-bold dark:border-ink-600 dark:text-paper-100">{{ $filas->first()->sala?->nombre ?? 'Sala sin identificar' }}</h2>
                @foreach ($filas as $entrega)
                    <div class="space-y-3 border-b border-gray-100 p-4 last:border-0 dark:border-ink-600" data-cobro>
                        <p class="font-semibold dark:text-paper-100" title="{{ $entrega->dte->numero_control }}">…{{ \App\Support\OrdenCompra::ultimosDigitos($entrega->dte->numero_control) }} · ${{ number_format((float) $entrega->dte->total_pagar, 2) }} · {{ $entrega->forma_cobro?->label() ?? 'Sin cobro (quedan)' }}</p>
                        @if ($entrega->monto_esperado !== null)
                            <p class="text-sm text-gray-600 dark:text-paper-300">Esperado ${{ number_format((float) $entrega->monto_esperado, 2) }}</p>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div><label for="monto-{{ $entrega->id }}" class="block text-sm dark:text-paper-300">Monto cobrado</label>
                                    <input id="monto-{{ $entrega->id }}" name="entregas[{{ $entrega->id }}][monto_cobrado]" type="number" inputmode="decimal" required min="0" max="{{ $entrega->monto_esperado }}" step="0.01" value="{{ old('entregas.'.$entrega->id.'.monto_cobrado', $entrega->monto_cobrado) }}" data-monto class="mt-1 min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100"></div>
                                <div><label for="forma-{{ $entrega->id }}" class="block text-sm dark:text-paper-300">Forma de cobro</label>
                                    <select id="forma-{{ $entrega->id }}" name="entregas[{{ $entrega->id }}][forma_cobro]" data-forma class="mt-1 min-h-11 w-full rounded-lg border-gray-300 dark:border-ink-600 dark:bg-ink-900 dark:text-paper-100">
                                        @foreach (\App\Enums\FormaCobroEntrega::opciones() as $valor => $label)<option value="{{ $valor }}" @selected(old('entregas.'.$entrega->id.'.forma_cobro', $entrega->forma_cobro?->value) === $valor)>{{ $label }}</option>@endforeach
                                    </select></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </section>
        @empty
            <p class="dark:text-paper-300">No hay entregas registradas en este día.</p>
        @endforelse
        @if ($entregas->isNotEmpty() || $cierre)
            <div><label for="entrega-dinero" class="block font-semibold dark:text-paper-100">¿Quién entrega el dinero?</label>
                <select id="entrega-dinero" name="entrega_dinero_personal_id" class="mt-2 min-h-11 w-full rounded-xl border-gray-300 dark:border-ink-600 dark:bg-ink-800 dark:text-paper-100">
                    <option value="">Elegí una persona</option>
                    @foreach ($participantes as $personaEntrega)<option value="{{ $personaEntrega->id }}" @selected((string) old('entrega_dinero_personal_id', $cierre?->entrega_dinero_personal_id ?? ($participantes->count() === 1 ? $personaEntrega->id : null)) === (string) $personaEntrega->id)>{{ $personaEntrega->nombre }}</option>@endforeach
                </select>
                <p class="mt-1 text-sm text-gray-600 dark:text-paper-300">Obligatorio si hay algo cobrado.</p>
            </div>
            <button class="min-h-11 w-full rounded-xl bg-green-600 px-5 py-4 text-lg font-bold text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600">Cerrar el día</button>
        @endif
    </form>
    <script>
        (() => {
            const form = document.getElementById('cierre-dia');
            const actualizar = () => {
                const totales = {efectivo: 0, cheque: 0, transferencia: 0};
                form.querySelectorAll('[data-cobro]').forEach(fila => {
                    const monto = fila.querySelector('[data-monto]');
                    const forma = fila.querySelector('[data-forma]');
                    if (monto && forma) totales[forma.value] += Math.round((Number(monto.value) || 0) * 100);
                });
                document.querySelectorAll('[data-total-forma]').forEach(cifra => {
                    cifra.textContent = '$' + (totales[cifra.dataset.totalForma] / 100).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                });
                document.getElementById('entrega-dinero')?.toggleAttribute('required', Object.values(totales).some(monto => monto > 0));
            };
            form.addEventListener('input', actualizar);
            form.addEventListener('change', actualizar);
            actualizar();
        })();
    </script>
@endif
