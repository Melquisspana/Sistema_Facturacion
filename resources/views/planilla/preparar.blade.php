@php
    use App\Models\Planilla\Planilla;
    use App\Models\Planilla\PlanillaConcepto;

    $campo = 'block w-full rounded border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
    $importe = $campo.' text-right tabular-nums';
    $boton = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';

    $lineasIniciales = $planilla->detalles->map(fn ($d) => [
        'planilla_empleado_id' => $d->planilla_empleado_id,
        'nombre' => $d->nombre_snapshot,
        'cargo' => $d->cargo_snapshot,
        'salario' => (string) $d->salario,
        'periodo_desde' => $d->periodo_desde?->toDateString() ?? '',
        'periodo_hasta' => $d->periodo_hasta?->toDateString() ?? '',
        // El habitual se muestra debajo del campo como referencia. Cambiar el importe
        // de ESTA quincena no lo toca: son cosas distintas.
        'habitual' => $habituales[$d->planilla_empleado_id] ?? '',
        'observaciones' => $d->observaciones,
        'conceptos' => $d->conceptos->map(fn ($x) => [
            'tipo' => $x->tipo, 'concepto' => $x->concepto, 'importe' => (string) $x->importe,
            'destino' => $x->destino ?? '', 'tercero' => $x->tercero ?? '', 'referencia' => $x->referencia ?? '',
            'planilla_anticipo_id' => $x->planilla_anticipo_id ?? '',
        ])->values(),
    ])->values();

    $disponiblesJs = $disponibles->map(fn ($e) => [
        'id' => $e->id, 'nombre' => $e->nombre, 'cargo' => $e->cargo,
        'salario' => $habitualesDisponibles[$e->id] ?? '',
        'habitual' => $habitualesDisponibles[$e->id] ?? '',
    ])->values();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ Planilla::TIPOS_PERIODO[$planilla->tipo_periodo] }} · {{ $planilla->periodo }}
                </h1>
                <p class="text-sm text-gray-500">
                    {{ $planilla->periodoEnPalabras() }}
                    @if ($planilla->clase !== 'regular') · {{ Planilla::CLASES[$planilla->clase] }} @endif
                </p>
            </div>
            <a href="{{ route('planilla.index') }}" class="{{ $boton }}">Volver</a>
        </div>
    </x-slot>

    <div class="px-4 py-5 sm:px-6">
        <div class="mx-auto max-w-6xl space-y-3">

            @if (session('planilla.aviso'))
                <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800" role="status">{{ session('planilla.aviso') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
            @endif

            <p class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900">
                <strong>Esto es un borrador y no debe nada.</strong> Las obligaciones se crean al
                <strong>confirmar</strong>, y una sola vez.
            </p>

            <form method="POST" action="{{ route('planilla.guardar', $planilla) }}"
                  x-data="{
                      lineas: @js($lineasIniciales),
                      disponibles: @js($disponiblesJs),
                      anticipos: @js($anticipos),
                      nuevo: '',
                      // Qué filas tienen el detalle abierto. Arranca vacío: la quincena
                      // normal no necesita abrir ninguna.
                      abiertos: [],
                      abierto(i) { return this.abiertos.includes(i); },
                      alterna(i) {
                          const k = this.abiertos.indexOf(i);
                          if (k === -1) { this.abiertos.push(i); } else { this.abiertos.splice(k, 1); }
                      },
                      n(v) { const x = parseFloat(String(v ?? '').replace(',', '.')); return isNaN(x) ? 0 : x; },
                      m(v) { return v.toFixed(2); },
                      fmt(v) { const s = String(v ?? '').trim(); return s === '' ? '' : this.n(s).toFixed(2); },
                      otrosIngresos(l) { return l.conceptos.filter(x => x.tipo === 'ingreso').reduce((s, x) => s + this.n(x.importe), 0); },
                      totalIngresos(l) { return this.n(l.salario) + this.otrosIngresos(l); },
                      bolsa(l, cual) {
                          return l.conceptos.filter(x => {
                              if (x.tipo !== 'descuento') return false;
                              const conNombre = x.destino === 'tercero' && (x.tercero || '').trim() !== '';
                              if (cual === 'terceros') return conNombre;
                              if (cual === 'anticipos') return x.destino === 'anticipo';
                              if (cual === 'otros') return x.destino === 'otro';
                              return !conNombre && x.destino !== 'anticipo' && x.destino !== 'otro';
                          }).reduce((s, x) => s + this.n(x.importe), 0);
                      },
                      descuentos(l) { return ['terceros', 'anticipos', 'otros', 'pendientes'].reduce((s, k) => s + this.bolsa(l, k), 0); },
                      aPagar(l) { return this.totalIngresos(l) - this.descuentos(l); },
                      suma(f, arg) { return this.lineas.reduce((s, l) => s + (arg ? this[f](l, arg) : this[f](l)), 0); },
                      sumaSalarios() { return this.lineas.reduce((s, l) => s + this.n(l.salario), 0); },
                      agregar() {
                          const p = this.disponibles.find(d => String(d.id) === String(this.nuevo));
                          if (!p) return;
                          this.lineas.push({ planilla_empleado_id: p.id, nombre: p.nombre, cargo: p.cargo,
                              salario: p.salario, habitual: p.habitual || '', periodo_desde: '', periodo_hasta: '',
                              observaciones: '', conceptos: [] });
                          this.disponibles = this.disponibles.filter(d => String(d.id) !== String(this.nuevo));
                          this.nuevo = '';
                      },
                      quitar(i) {
                          const l = this.lineas[i];
                          this.disponibles.push({ id: l.planilla_empleado_id, nombre: l.nombre, cargo: l.cargo,
                              salario: l.habitual || l.salario, habitual: l.habitual || '' });
                          this.lineas.splice(i, 1);
                          // Los índices se corren al quitar una fila: si no se limpian,
                          // queda abierto el detalle de otra persona.
                          this.abiertos = [];
                      },
                      // ── Anticipos: la resta que se ve al elegir cuánto descontar ──
                      anticipoDe(l, x) {
                          return (this.anticipos[l.planilla_empleado_id] || [])
                              .find(a => String(a.id) === String(x.planilla_anticipo_id));
                      },
                      saldoAnticipo(l, x) { const a = this.anticipoDe(l, x); return a ? this.n(a.pendiente) : 0; },
                      quedaAnticipo(l, x) { return this.saldoAnticipo(l, x) - this.n(x.importe); },

                      // ── Período particular: solo se CUENTAN los días, no se reparte
                      //    el importe. Contar ayuda a decidir; repartir sería decidir
                      //    por quien tiene que hacerlo.
                      dias(l) {
                          const d = l.periodo_desde || @js($planilla->desde->toDateString());
                          const h = l.periodo_hasta || @js($planilla->hasta->toDateString());
                          const ms = new Date(h + 'T00:00:00') - new Date(d + 'T00:00:00');
                          return isNaN(ms) ? 0 : Math.round(ms / 86400000) + 1;
                      },
                      diasDe(l) {
                          const n = this.dias(l);
                          const total = @js($planilla->desde->diffInDays($planilla->hasta) + 1);
                          return n > 0 ? n + ' de ' + total + (n === 1 ? ' día' : ' días') : '';
                      },
                      etiquetaPeriodo(l) {
                          const d = (l.periodo_desde || '').slice(8) || '??';
                          const h = (l.periodo_hasta || '').slice(8) || '??';
                          return d + '–' + h;
                      },
                      // ¿Hay algo en esta fila que merezca abrir el detalle?
                      pendientesDe(l) {
                          return l.conceptos.length > 0 || !!l.periodo_desde || !!l.periodo_hasta
                              || (l.observaciones || '') !== '';
                      },
                      addConcepto(i, tipo) { this.lineas[i].conceptos.push({ tipo: tipo, concepto: '', importe: '', destino: '', tercero: '', referencia: '', planilla_anticipo_id: '' }); },
                      delConcepto(i, j) { this.lineas[i].conceptos.splice(j, 1); }
                  }">
                @csrf
                @method('PUT')

                {{-- ══════════ TOTALES: una RESTA, no cuatro cifras al lado ══════════

                     Antes eran cuatro tarjetas iguales y hacía falta una frase que
                     explicara que no se suman. La resta lo dice sola: los descuentos van
                     sangrados debajo de su propio total, y «a terceros» es una de sus
                     partes, no otro total equivalente. --}}
                <section class="rounded-lg border border-gray-200 bg-white p-4" aria-labelledby="resumen-planilla">
                    <h2 id="resumen-planilla" class="text-sm font-semibold uppercase tracking-wide text-gray-500">
                        Resumen · {{ $planilla->moneda }}
                    </h2>

                    <dl class="mt-2 space-y-1 text-sm">
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-gray-700">Total de ingresos</dt>
                            <dd class="whitespace-nowrap font-semibold tabular-nums text-gray-900" x-text="m(suma('totalIngresos'))"></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 pl-4 text-xs text-gray-500">
                            <dt>Salarios</dt>
                            <dd class="whitespace-nowrap tabular-nums" x-text="m(sumaSalarios())"></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 pl-4 text-xs text-gray-500">
                            <dt>Otros ingresos</dt>
                            <dd class="whitespace-nowrap tabular-nums" x-text="m(suma('otrosIngresos'))"></dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-4 border-t border-gray-100 pt-1">
                            <dt class="text-gray-700">− Descuentos</dt>
                            <dd class="whitespace-nowrap font-semibold tabular-nums text-gray-900" x-text="m(suma('descuentos'))"></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 pl-4 text-xs text-gray-500">
                            <dt>Se entregan a terceros</dt>
                            <dd class="whitespace-nowrap tabular-nums" x-text="m(suma('bolsa', 'terceros'))"></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 pl-4 text-xs text-gray-500">
                            <dt>Anticipos ya pagados</dt>
                            <dd class="whitespace-nowrap tabular-nums" x-text="m(suma('bolsa', 'anticipos'))"></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 pl-4 text-xs text-gray-500">
                            <dt>Otros descuentos</dt>
                            <dd class="whitespace-nowrap tabular-nums" x-text="m(suma('bolsa', 'otros'))"></dd>
                        </div>
                        <div x-show="suma('bolsa', 'pendientes') > 0" x-cloak
                             class="flex items-baseline justify-between gap-4 pl-4 text-xs text-amber-700">
                            <dt>Sin clasificar todavía</dt>
                            <dd class="whitespace-nowrap tabular-nums" x-text="m(suma('bolsa', 'pendientes'))"></dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-4 border-t-2 border-gray-300 pt-2">
                            <dt class="font-semibold text-gray-900">= A pagar a los empleados</dt>
                            <dd class="whitespace-nowrap text-lg font-bold tabular-nums text-indigo-900" x-text="m(suma('aPagar'))"></dd>
                        </div>
                    </dl>

                    <p class="mt-2 text-xs text-gray-500">
                        El <strong>total de ingresos</strong> es lo que cuesta la planilla; lo que se entrega a
                        terceros ya está dentro de los descuentos.
                    </p>
                </section>

                @if ($reparos !== [])
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">
                        <p class="font-medium">Antes de confirmar hay que resolver:</p>
                        <ul class="mt-1 list-inside list-disc">@foreach ($reparos as $r)<li>{{ $r }}</li>@endforeach</ul>
                    </div>
                @endif

                {{-- ══════════ LAS PERSONAS ══════════

                     UN SOLO juego de campos. En pantallas anchas el bloque de cada persona
                     se convierte en una fila de rejilla alineada con la cabecera; por
                     debajo de `lg` se apila y cada campo lleva su etiqueta a la vista.

                     No hay tabla con desplazamiento horizontal: se probó y comprimía los
                     campos hasta cortar nombres e importes. Una rejilla que se apila
                     resuelve las dos cosas sin duplicar inputs —que es lo que pasaría con
                     dos maquetaciones, y enviaría cada dato dos veces—. --}}
                <div class="hidden rounded-t-lg border border-b-0 border-gray-200 bg-gray-50 px-4 py-2 lg:grid lg:grid-cols-12 lg:gap-3">
                    <p class="col-span-3 text-xs font-medium uppercase tracking-wide text-gray-500">Persona</p>
                    <p class="col-span-2 text-xs font-medium uppercase tracking-wide text-gray-500">Pago del período</p>
                    <p class="col-span-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Extras</p>
                    <p class="col-span-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Descuentos</p>
                    <p class="col-span-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Total a pagar</p>
                </div>

                <div class="space-y-3 lg:space-y-0">
                    <template x-for="(l, i) in lineas" :key="l.planilla_empleado_id">
                        <section class="rounded-lg border border-gray-200 bg-white p-4 lg:grid lg:grid-cols-12 lg:gap-3 lg:rounded-none lg:border-t-0 lg:first:border-t"
                                 :aria-label="'Línea de ' + l.nombre">

                            {{-- ── Persona ── --}}
                            <div class="lg:col-span-3">
                                <p class="text-base font-semibold text-gray-900 lg:text-sm" x-text="l.nombre"></p>
                                <p class="text-xs text-gray-500" x-text="l.cargo || 'Sin cargo'"></p>
                                <input type="hidden" :name="'lineas['+i+'][planilla_empleado_id]'" :value="l.planilla_empleado_id">

                                {{-- Distintivos de lo que esta persona tiene FUERA de lo
                                     normal. Es lo que permite cerrar el detalle y aun así
                                     ver de un vistazo quién necesita revisión. --}}
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span x-show="l.periodo_desde || l.periodo_hasta" x-cloak
                                          class="rounded-full border border-amber-300 px-2 py-0.5 text-[0.65rem] font-medium text-amber-800"
                                          x-text="etiquetaPeriodo(l)"></span>
                                    <span x-show="bolsa(l, 'anticipos') > 0" x-cloak
                                          class="rounded-full border border-violet-300 px-2 py-0.5 text-[0.65rem] font-medium text-violet-800">anticipo</span>
                                    <span x-show="bolsa(l, 'terceros') > 0" x-cloak
                                          class="rounded-full border border-sky-300 px-2 py-0.5 text-[0.65rem] font-medium text-sky-800">a un tercero</span>
                                    <span x-show="bolsa(l, 'pendientes') > 0" x-cloak
                                          class="rounded-full border border-red-300 px-2 py-0.5 text-[0.65rem] font-medium text-red-800">sin clasificar</span>
                                </div>
                            </div>

                            {{-- ── Pago del período ── --}}
                            <div class="mt-3 lg:col-span-2 lg:mt-0">
                                <label :for="'salario-'+i" class="block text-sm font-medium text-gray-700 lg:sr-only">Pago del período</label>
                                <input :id="'salario-'+i" type="text" inputmode="decimal"
                                       :name="'lineas['+i+'][salario]'" x-model="l.salario"
                                       @blur="l.salario = fmt(l.salario)"
                                       placeholder="0.00" class="{{ $importe }}">
                                <p class="mt-1 text-xs text-gray-400" x-show="l.habitual !== ''" x-cloak
                                   x-text="'habitual ' + l.habitual"></p>
                            </div>

                            {{-- ── Extras ── --}}
                            <div class="mt-3 flex items-baseline justify-between gap-2 lg:col-span-2 lg:mt-0 lg:block lg:text-right">
                                <span class="text-sm font-medium text-gray-700 lg:hidden">Extras</span>
                                <span class="text-sm tabular-nums"
                                      :class="otrosIngresos(l) > 0 ? 'text-gray-900' : 'text-gray-300'"
                                      x-text="otrosIngresos(l) > 0 ? m(otrosIngresos(l)) : '—'"></span>
                            </div>

                            {{-- ── Descuentos ── --}}
                            <div class="mt-1 flex items-baseline justify-between gap-2 lg:col-span-2 lg:mt-0 lg:block lg:text-right">
                                <span class="text-sm font-medium text-gray-700 lg:hidden">Descuentos</span>
                                <span class="text-sm tabular-nums"
                                      :class="descuentos(l) > 0 ? 'text-gray-900' : 'text-gray-300'"
                                      x-text="descuentos(l) > 0 ? m(descuentos(l)) : '—'"></span>
                            </div>

                            {{-- ── Total a pagar ── --}}
                            <div class="mt-2 flex items-baseline justify-between gap-2 border-t border-gray-100 pt-2 lg:col-span-3 lg:mt-0 lg:block lg:border-t-0 lg:pt-0 lg:text-right">
                                <span class="text-sm font-semibold text-gray-700 lg:hidden">Total a pagar</span>
                                <span class="text-lg font-bold tabular-nums"
                                      :class="aPagar(l) < 0 ? 'text-red-700' : 'text-indigo-900'" x-text="m(aPagar(l))"></span>

                                <div class="mt-2 hidden lg:block">
                                    <button type="button" @click="alterna(i)"
                                            class="text-xs font-medium text-indigo-700 hover:underline"
                                            :aria-expanded="abierto(i) ? 'true' : 'false'"
                                            :aria-controls="'detalle-'+i"
                                            x-text="(abierto(i) ? 'Ocultar' : 'Detalle') + (pendientesDe(l) ? ' ·' : '')"></button>
                                </div>
                            </div>

                            <p x-show="aPagar(l) < 0" x-cloak class="mt-1 text-xs text-red-700 lg:col-span-12">
                                Los descuentos superan al total de ingresos.
                            </p>

                            {{-- En móvil el botón va abajo y a lo ancho, no perdido a la derecha. --}}
                            <div class="mt-3 lg:hidden">
                                <button type="button" @click="alterna(i)"
                                        class="{{ $boton }} w-full"
                                        :aria-expanded="abierto(i) ? 'true' : 'false'"
                                        :aria-controls="'detalle-movil-'+i"
                                        x-text="abierto(i) ? 'Ocultar detalle' : 'Ver detalle'"></button>
                            </div>

                            {{-- ══════════ EL DETALLE ══════════

                                 Lo que casi nunca hace falta: extras, descuentos, el
                                 período particular de quien entró a mitad y la nota.
                                 Cerrado por defecto, porque la quincena normal no lo
                                 necesita y abrirlo para todos sería la pantalla de antes.

                                 Va DENTRO del mismo <section> y ocupa las doce columnas,
                                 así que no hay un segundo juego de campos: los inputs son
                                 los mismos, se muestren o no. --}}
                            <div x-show="abierto(i)" x-cloak :id="'detalle-'+i"
                                 class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3 lg:col-span-12">

                                {{-- ── Período particular ── --}}
                                <fieldset>
                                    <legend class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Período trabajado
                                    </legend>
                                    <p class="mt-1 text-xs text-gray-500">
                                        Vacío = la quincena completa ({{ $planilla->desde->format('d/m') }} al
                                        {{ $planilla->hasta->format('d/m') }}). Se rellena solo para quien entró o
                                        salió a mitad, y <strong>viaja al recibo y a la hoja de firmas</strong>.
                                    </p>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                        <div>
                                            <label :for="'pd-'+i" class="block text-xs text-gray-500">Trabajó desde</label>
                                            <input :id="'pd-'+i" type="date"
                                                   :min="@js($planilla->desde->toDateString())"
                                                   :max="@js($planilla->hasta->toDateString())"
                                                   :name="'lineas['+i+'][periodo_desde]'" x-model="l.periodo_desde"
                                                   class="{{ $campo }}">
                                        </div>
                                        <div>
                                            <label :for="'ph-'+i" class="block text-xs text-gray-500">Hasta</label>
                                            <input :id="'ph-'+i" type="date"
                                                   :min="l.periodo_desde || @js($planilla->desde->toDateString())"
                                                   :max="@js($planilla->hasta->toDateString())"
                                                   :name="'lineas['+i+'][periodo_hasta]'" x-model="l.periodo_hasta"
                                                   class="{{ $campo }}">
                                        </div>
                                        <div class="flex items-end">
                                            <p class="text-xs text-gray-500" x-show="l.periodo_desde || l.periodo_hasta" x-cloak>
                                                <span x-text="diasDe(l)"></span>
                                                <br>
                                                {{-- Se dice en voz alta: el importe NO se reparte solo. --}}
                                                <span class="text-amber-700">El importe lo escribís vos.</span>
                                            </p>
                                        </div>
                                    </div>
                                </fieldset>

                                {{-- ── Extras y descuentos ── --}}
                                <div class="mt-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Extras y descuentos de esta quincena
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500">
                                        Empiezan vacíos cada vez, a propósito: un extra que se copiara solo
                                        terminaría pagándose dos veces.
                                    </p>

                                    <template x-for="(x, j) in l.conceptos" :key="j">
                                        <div class="mt-2 rounded-md border bg-white p-2"
                                             :class="x.tipo === 'ingreso' ? 'border-emerald-200' : 'border-rose-200'">
                                            <p class="text-xs font-semibold"
                                               :class="x.tipo === 'ingreso' ? 'text-emerald-800' : 'text-rose-800'"
                                               x-text="x.tipo === 'ingreso' ? 'Extra' : 'Descuento'"></p>
                                            <input type="hidden" :name="'lineas['+i+'][conceptos]['+j+'][tipo]'" :value="x.tipo">

                                            <div class="mt-1 grid grid-cols-3 gap-2">
                                                <div class="col-span-2">
                                                    <label :for="'con-'+i+'-'+j" class="block text-xs text-gray-500">Concepto</label>
                                                    <input :id="'con-'+i+'-'+j" type="text" maxlength="150"
                                                           :name="'lineas['+i+'][conceptos]['+j+'][concepto]'" x-model="x.concepto"
                                                           list="conceptos-sugeridos" class="{{ $campo }}">
                                                </div>
                                                <div>
                                                    <label :for="'imp-'+i+'-'+j" class="block text-xs text-gray-500">Importe</label>
                                                    <input :id="'imp-'+i+'-'+j" type="text" inputmode="decimal" placeholder="0.00"
                                                           :name="'lineas['+i+'][conceptos]['+j+'][importe]'" x-model="x.importe"
                                                           @blur="x.importe = fmt(x.importe)" class="{{ $importe }}">
                                                </div>
                                            </div>

                                            <template x-if="x.tipo === 'descuento'">
                                                <div class="mt-2">
                                                    <label :for="'des-'+i+'-'+j" class="block text-xs text-gray-500">¿Qué es este descuento?</label>
                                                    <select :id="'des-'+i+'-'+j" :name="'lineas['+i+'][conceptos]['+j+'][destino]'" x-model="x.destino"
                                                            class="{{ $campo }}">
                                                        <option value="">Elegir…</option>
                                                        @foreach (PlanillaConcepto::DESTINOS as $clave => $texto)
                                                            <option value="{{ $clave }}">{{ $texto }}</option>
                                                        @endforeach
                                                    </select>

                                                    <div x-show="x.destino === 'tercero'" x-cloak class="mt-2">
                                                        <label :for="'ter-'+i+'-'+j" class="block text-xs text-gray-500">¿A quién se le entrega?</label>
                                                        <input :id="'ter-'+i+'-'+j" type="text" maxlength="180"
                                                               :name="'lineas['+i+'][conceptos]['+j+'][tercero]'" x-model="x.tercero"
                                                               placeholder="Cooperativa, banco, juzgado…" class="{{ $campo }}">
                                                    </div>

                                                    {{-- ── Anticipos: la resta a la vista ──
                                                         Se ELIGE el anticipo, no se escribe: una referencia a
                                                         mano no impide descontar el mismo dinero otra vez el
                                                         mes que viene. Y se muestra la resta completa —saldo
                                                         antes, cuánto se descuenta ahora, cuánto queda—
                                                         porque decidir «cuánto descontar» sin ver el saldo es
                                                         adivinar. --}}
                                                    <div x-show="x.destino === 'anticipo'" x-cloak class="mt-2">
                                                        <label :for="'ant-'+i+'-'+j" class="block text-xs text-gray-500">¿Cuál anticipo?</label>
                                                        <select :id="'ant-'+i+'-'+j" :name="'lineas['+i+'][conceptos]['+j+'][planilla_anticipo_id]'"
                                                                x-model="x.planilla_anticipo_id" class="{{ $campo }}">
                                                            <option value="">Elegir anticipo…</option>
                                                            <template x-for="a in (anticipos[l.planilla_empleado_id] || [])" :key="a.id">
                                                                <option :value="a.id" x-text="a.etiqueta"></option>
                                                            </template>
                                                        </select>

                                                        <dl x-show="x.planilla_anticipo_id !== ''" x-cloak
                                                            class="mt-2 grid grid-cols-3 gap-px overflow-hidden rounded border border-violet-200 bg-violet-200 text-center">
                                                            <div class="bg-violet-50 px-2 py-1.5">
                                                                <dt class="text-[0.65rem] uppercase tracking-wide text-violet-700">Saldo pendiente</dt>
                                                                <dd class="text-sm tabular-nums text-violet-900" x-text="m(saldoAnticipo(l, x))"></dd>
                                                            </div>
                                                            <div class="bg-violet-50 px-2 py-1.5">
                                                                <dt class="text-[0.65rem] uppercase tracking-wide text-violet-700">Se descuenta</dt>
                                                                <dd class="text-sm tabular-nums text-violet-900" x-text="m(n(x.importe))"></dd>
                                                            </div>
                                                            <div class="bg-violet-50 px-2 py-1.5">
                                                                <dt class="text-[0.65rem] uppercase tracking-wide text-violet-700">Queda</dt>
                                                                <dd class="text-sm font-semibold tabular-nums"
                                                                    :class="quedaAnticipo(l, x) < 0 ? 'text-red-700' : 'text-violet-900'"
                                                                    x-text="m(quedaAnticipo(l, x))"></dd>
                                                            </div>
                                                        </dl>

                                                        <p x-show="x.planilla_anticipo_id !== '' && quedaAnticipo(l, x) < 0" x-cloak
                                                           class="mt-1 text-xs text-red-700">
                                                            Se está descontando más de lo que queda por recuperar.
                                                        </p>

                                                        <p x-show="(anticipos[l.planilla_empleado_id] || []).length === 0" x-cloak
                                                           class="mt-1 text-xs text-amber-700">
                                                            Esta persona no tiene anticipos con saldo.
                                                            <a href="{{ route('planilla.anticipos') }}" class="underline">Registrar uno</a>.
                                                        </p>
                                                        <input :id="'ref-'+i+'-'+j" type="text" maxlength="180"
                                                               :name="'lineas['+i+'][conceptos]['+j+'][referencia]'" x-model="x.referencia"
                                                               placeholder="Nota (opcional)" class="{{ $campo }} mt-1 text-xs">
                                                    </div>

                                                    <p x-show="x.destino !== ''" x-cloak class="mt-1 text-xs text-gray-500"
                                                       x-text="@js(PlanillaConcepto::DESTINOS_AYUDA)[x.destino] || ''"></p>
                                                </div>
                                            </template>

                                            <div class="mt-2 flex justify-end">
                                                <button type="button" @click="delConcepto(i, j)" class="{{ $boton }} border-transparent text-red-700 hover:bg-red-50"
                                                        :aria-label="'Quitar ' + (x.concepto || (x.tipo === 'ingreso' ? 'este extra' : 'este descuento'))">
                                                    Quitar
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button type="button" @click="addConcepto(i, 'ingreso')" class="{{ $boton }} border-emerald-300 text-emerald-800 hover:bg-emerald-50">
                                            Agregar extra
                                        </button>
                                        <button type="button" @click="addConcepto(i, 'descuento')" class="{{ $boton }} border-rose-300 text-rose-800 hover:bg-rose-50">
                                            Agregar descuento
                                        </button>
                                    </div>
                                </div>

                                {{-- ── Nota y quitar ── --}}
                                <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                                    <div class="min-w-0 flex-1 sm:max-w-md">
                                        <label :for="'nota-'+i" class="block text-xs text-gray-500">Nota de esta quincena (opcional)</label>
                                        <input :id="'nota-'+i" type="text" :name="'lineas['+i+'][observaciones]'" x-model="l.observaciones"
                                               maxlength="500" class="{{ $campo }} text-xs">
                                    </div>
                                    <button type="button" @click="quitar(i)" class="{{ $boton }} text-gray-600"
                                            :aria-label="'Quitar a ' + l.nombre + ' de esta quincena'">
                                        Quitar de esta quincena
                                    </button>
                                </div>
                            </div>
                        </section>
                    </template>
                </div>

                <div x-show="lineas.length === 0" x-cloak class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-10 text-center">
                    <p class="text-sm text-gray-600">Todavía no hay nadie en esta planilla.</p>
                </div>

                {{-- Agregar personas. Si no queda nadie por agregar NO se muestra un
                     selector vacío: se ofrece la única acción que tiene sentido. --}}
                <div class="rounded-b-lg border border-gray-200 bg-gray-50 p-3 lg:rounded-t-none">
                    <template x-if="disponibles.length > 0">
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-0 flex-1 sm:max-w-sm">
                                <label for="agregar-persona" class="block text-xs text-gray-500">Agregar a la planilla</label>
                                <select id="agregar-persona" x-model="nuevo" class="{{ $campo }} min-h-11">
                                    <option value="">Elegir persona…</option>
                                    <template x-for="d in disponibles" :key="d.id">
                                        <option :value="d.id" x-text="d.nombre + (d.cargo ? ' · ' + d.cargo : '')"></option>
                                    </template>
                                </select>
                            </div>
                            <button type="button" @click="agregar()" :disabled="nuevo === ''"
                                    class="{{ $boton }} disabled:opacity-50">Agregar</button>
                        </div>
                    </template>

                    <template x-if="disponibles.length === 0">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-gray-600">Ya están en la planilla todas las personas registradas.</p>
                            <a href="{{ route('planilla.empleados') }}" class="{{ $boton }}">Registrar o vincular una persona</a>
                        </div>
                    </template>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-gray-500">
                        Los importes se escriben a mano. El sistema <strong>no calcula</strong> ISSS, AFP, renta,
                        vacaciones, aguinaldo, indemnización ni horas extra.
                    </p>
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 sm:w-auto">
                        Guardar borrador
                    </button>
                </div>
            </form>

            <datalist id="conceptos-sugeridos">
                @foreach (array_merge(config('planilla.ingresos_sugeridos'), config('planilla.descuentos_sugeridos')) as $s)
                    <option value="{{ $s }}"></option>
                @endforeach
            </datalist>

            <p class="text-xs text-gray-500">
                Confirmar la planilla —que es lo que crea las obligaciones— llega en el siguiente paso, junto
                con los pagos, los lotes y los documentos firmados.
            </p>
        </div>
    </div>
</x-app-layout>
