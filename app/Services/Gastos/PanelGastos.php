<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\Recurrencia\CalendarioRecurrencia;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La pantalla principal: qué debemos, qué viene, qué falta completar y qué se pagó.
 *
 * ═════════════════ LA SEPARACIÓN QUE SOSTIENE TODA ESTA PANTALLA ═════════════════
 *
 * Hay DOS clases de cifra acá, y no se suman nunca:
 *
 *   OBLIGACIÓN CONFIRMADA  una fila real en `gastos`, con su cuota. Alguien la
 *                          registró o la generó una regla. Se debe.
 *   PREVISIÓN              una fecha que TODAVÍA NO EXISTE en la base. Sale de
 *                          proyectar una regla activa hacia adelante. No se debe:
 *                          es lo que va a pasar si nada cambia.
 *
 * No están en la misma caja, no comparten color y en ningún lugar de la pantalla
 * aparece la suma de las dos. Tampoco comparten camino: obligaciones() lee la base,
 * previsiones() calcula fechas y no toca `gastos`.
 *
 * Y CUANDO UNA PREVISIÓN SE VUELVE OBLIGACIÓN, DESAPARECE COMO PREVISIÓN. En cuanto
 * la regla genera el período existe una `Ocurrencia` con esa clave lógica, y la
 * proyección de ese mismo período se descarta (ver periodosYaGenerados()). Es
 * reemplazo, no acumulación: si no se descartara, el mes en que se genera el alquiler
 * se vería dos veces —una como previsión y otra como deuda— y el operador pensaría
 * que debe el doble.
 *
 * ════════════════════ NO SE FABRICA DEUDA HACIA ATRÁS. NUNCA ════════════════════
 *
 * Una proyección empieza en el MAYOR de dos días: hoy y el arranque de la regla
 * (`vigente_desde`). Las dos cotas hacen falta y ninguna sobra:
 *
 *   - el arranque, porque una regla cargada el 15 de septiembre no describe lo que
 *     pasó el 10. Si se cargó el 15, rige desde el 15.
 *   - hoy, porque una fecha ya pasada que nadie generó no es una previsión: o se
 *     debe —y entonces alguien tiene que decirlo— o no se debe. Adivinarlo es
 *     inventar deuda.
 *
 * Caso concreto, el que motivó esta regla: Auto Fácil y el seguro del N400 vencen el
 * día 10 y se cargaron el 15 de septiembre. La mensualidad del 10 de septiembre NO
 * aparece como deuda de septiembre. La primera que se proyecta es la del 10 de
 * octubre. Lo que quedó del lado de atrás se informa aparte y sin cifra, en
 * anterioresAlArranque(), para que la omisión se vea en vez de ser silenciosa.
 *
 * ══════════════════ SIN FECHA ES «POR COMPLETAR», NO UN RECIBO ══════════════════
 *
 * ANDA, CAES, DELSUR, Netflix y las demás reglas sin día son BORRADORES. No se les
 * inventa un período ni un vencimiento con tal de poder mostrarles una fila con
 * botón. Se quedan en «Por completar» diciendo qué les falta, que es la verdad.
 *
 * Distinto es una obligación que YA EXISTE y espera su importe (`importe` NULL): esa
 * sí tiene período y sí aparece, en «Esperando el recibo». No suma a ninguna cifra,
 * porque un monto desconocido no es cero.
 */
final class PanelGastos
{
    public function __construct(
        private ConsultaGastos $consulta,
        private CalendarioRecurrencia $calendario,
    ) {}

    /**
     * Todo lo que la pantalla necesita, ya recortado por permiso y por el filtro de ámbito.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function armar(User $usuario, array $filtros, CarbonImmutable $hoy): array
    {
        $finDeMes = $hoy->endOfMonth();
        $obligaciones = $this->obligaciones($usuario, $filtros, $hoy);
        $previsiones = $this->previsiones($usuario, $filtros, $hoy, $finDeMes);

        return [
            'hoy' => $hoy,
            'mes' => $this->calendario->nombreMes((int) $hoy->format('n')).' '.$hoy->format('Y'),

            // ── Las dos cifras. En dos claves distintas, a propósito: no existe
            //    ninguna que las contenga sumadas, ni siquiera para uso interno.
            'se_debe' => $obligaciones['vencido']->sum('pendiente')
                + $obligaciones['este_mes']->sum('pendiente')
                + $obligaciones['mas_adelante']->sum('pendiente')
                + $obligaciones['sin_fecha']->sum('pendiente'),
            // Parte del total de arriba, no un sumando aparte: lo que está registrado
            // pero acordado de palabra. Se dice para no presentar como respaldado algo
            // que todavía espera su papel.
            'se_debe_provisional' => $this->provisionalDe($obligaciones),
            'se_espera' => $previsiones->sum('importe'),

            'vencido' => $obligaciones['vencido'],
            'este_mes' => $obligaciones['este_mes'],
            'mas_adelante' => $obligaciones['mas_adelante'],
            'sin_fecha' => $obligaciones['sin_fecha'],
            'esperando_recibo' => $obligaciones['esperando_recibo'],
            'pagado_en_el_mes' => $this->pagadoEnElMes($usuario, $filtros, $hoy),

            'previsiones' => $previsiones,
            // Ni deuda confirmada ni previsión: fechas que pasaron sin que nadie
            // generara la obligación. Grupo propio, sin sumar a ninguna cifra.
            'fecha_pasada' => $this->fechasPasadasSinConfirmar($usuario, $filtros, $hoy),
            'anteriores_al_arranque' => $this->anterioresAlArranque($usuario, $filtros, $hoy),
            'por_completar' => $this->porCompletar($usuario, $filtros),
        ];
    }

    // ═══════════════════════ OBLIGACIONES (lo que se debe) ═══════════════════════

    /**
     * Las obligaciones reales, agrupadas por lo que hay que hacer con cada una.
     *
     * Un gasto cae en UN solo grupo, decidido por su próxima fecha con saldo. No se
     * reparte entre dos: una fila que apareciera en «vencido» y en «este mes» sumaría
     * su pendiente dos veces en la cifra de arriba.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, Collection<int, Gasto>>
     */
    public function obligaciones(User $usuario, array $filtros, CarbonImmutable $hoy): array
    {
        $dia = $hoy->toDateString();
        $finDeMes = $hoy->endOfMonth()->toDateString();

        $filas = $this->recortado($usuario, $filtros)
            ->leftJoinSub($this->consulta->saldosPorGasto($dia), 's', 's.gasto_id', '=', 'gastos.id')
            ->select('gastos.*')
            ->addSelect(['s.pendiente', 's.vencido', 's.proxima'])
            ->whereNotNull('gastos.importe')
            ->where('s.pendiente', '>', 0)
            ->orderByRaw('CASE WHEN s.proxima IS NULL THEN 1 ELSE 0 END, s.proxima')
            ->get();

        $esperando = $this->recortado($usuario, $filtros)
            ->whereNull('gastos.importe')
            ->orderBy('gastos.created_at', 'desc')
            ->get();

        $proxima = fn (Gasto $g) => $g->proxima === null ? null : substr((string) $g->proxima, 0, 10);

        return [
            'vencido' => $filas->filter(fn ($g) => $proxima($g) !== null && $proxima($g) < $dia)->values(),
            'este_mes' => $filas->filter(fn ($g) => $proxima($g) !== null && $proxima($g) >= $dia && $proxima($g) <= $finDeMes)->values(),
            'mas_adelante' => $filas->filter(fn ($g) => $proxima($g) !== null && $proxima($g) > $finDeMes)->values(),
            // Cuentas abiertas: se debe, pero no hay fecha que reclamar. Acá viven
            // Proveedor A, Proveedor B y Distribuidora Ejemplo, S.A. de C.V., y de acá sale el botón «Abonar».
            'sin_fecha' => $filas->filter(fn ($g) => $proxima($g) === null)->values(),
            // Existen y tienen período; lo que no tienen es importe. No suman.
            'esperando_recibo' => $esperando,
        ];
    }

    // ═══════════════════════ PREVISIONES (lo que viene) ═══════════════════════

    /**
     * Lo que van a generar las reglas activas entre hoy y el fin de la ventana.
     *
     * No lee `gastos` y no crea nada: son fechas calculadas. Cada fila lleva
     * `es_prevision` en true para que ninguna vista pueda confundirla con una deuda
     * ni ofrecerle un botón de pagar —no se puede pagar lo que todavía no existe—.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function previsiones(User $usuario, array $filtros, CarbonImmutable $hoy, CarbonImmutable $hasta): Collection
    {
        $previstas = collect();

        foreach ($this->reglasVisibles($usuario, $filtros) as $regla) {
            $arranque = CarbonImmutable::parse($regla->vigente_desde->toDateString());

            // LAS DOS COTAS. Ver la cabecera de la clase: sin la de arranque se
            // inventan mensualidades anteriores a la carga de la regla; sin la de hoy
            // se presenta como «lo que viene» algo que ya pasó.
            $desde = $arranque->gt($hoy) ? $arranque : $hoy;

            $tope = $hasta;
            if ($regla->vigente_hasta !== null) {
                $fin = CarbonImmutable::parse($regla->vigente_hasta->toDateString());
                $tope = $tope->gt($fin) ? $fin : $tope;
            }

            $yaGenerados = $this->periodosYaGenerados($regla);

            foreach ($this->calendario->periodos($regla->calendario(), $desde, $tope) as $periodo) {
                // Reemplazo, no acumulación: si el período ya se generó, la obligación
                // real es la que manda y esta previsión deja de existir.
                if (in_array($periodo['periodo'], $yaGenerados, true)) {
                    continue;
                }

                $previstas->push((object) [
                    'es_prevision' => true,
                    'regla_id' => $regla->id,
                    'beneficiario' => $regla->beneficiario,
                    'concepto' => $regla->concepto,
                    'ambito' => $regla->ambito,
                    'moneda' => $regla->moneda,
                    'periodo' => $periodo['periodo'],
                    'vence' => $periodo['vence']->toDateString(),
                    'monto_modo' => $regla->monto_modo,
                    // Una regla de monto variable proyecta la FECHA y no el importe:
                    // todavía no llegó el recibo. Cero sería una cifra inventada.
                    'importe' => $regla->monto_modo === 'variable' || $regla->importe === null
                        ? null
                        : Dinero::centavos((string) $regla->importe),
                ]);
            }
        }

        return $previstas->sortBy('vence')->values();
    }

    /**
     * Vencimientos anteriores al ARRANQUE de la regla. Quedaron fuera para siempre.
     *
     * Son los que la aritmética del calendario produciría pero que caen antes de que
     * la regla rigiera: la mensualidad del 10 de septiembre de una regla cargada el
     * 15. NO SON DEUDA, no llevan cifra y no se van a generar solos nunca.
     *
     * Se listan por una sola razón: que la omisión se vea. Un sistema que
     * simplemente no los muestra deja al operador sin saber si el 10 de septiembre se
     * debe o no; este bloque dice «esto quedó fuera, decidilo vos». Confirmarlo es un
     * acto humano —generar el período desde la regla— y no ocurre solo.
     *
     * Se acota al MES DEL ARRANQUE, y no al mes en curso: es una nota sobre cómo
     * empezó esta regla, así que dice lo mismo en septiembre que en marzo en vez de
     * ir cambiando. Lo posterior al arranque que ya venció es otra cosa y vive en
     * {@see fechasPasadasSinConfirmar()}.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function anterioresAlArranque(User $usuario, array $filtros, CarbonImmutable $hoy): Collection
    {
        $pendientes = collect();

        foreach ($this->reglasVisibles($usuario, $filtros) as $regla) {
            $yaGenerados = $this->periodosYaGenerados($regla);
            $arranque = CarbonImmutable::parse($regla->vigente_desde->toDateString());

            // Del principio del mes del arranque hasta el día anterior al arranque.
            // Si la regla arrancó un día 1 no hay nada que decir y el rango sale vacío.
            foreach ($this->calendario->periodos($regla->calendario(), $arranque->startOfMonth(), $arranque->subDay()) as $periodo) {
                if (in_array($periodo['periodo'], $yaGenerados, true)) {
                    continue;
                }

                $pendientes->push((object) [
                    'regla_id' => $regla->id,
                    'beneficiario' => $regla->beneficiario,
                    'concepto' => $regla->concepto,
                    'ambito' => $regla->ambito,
                    'periodo' => $periodo['periodo'],
                    'vence' => $periodo['vence']->toDateString(),
                    'motivo' => 'Vence antes del arranque de la regla ('.$arranque->format('d/m/Y').').',
                ]);
            }
        }

        return $pendientes->sortBy('vence')->values();
    }

    /**
     * Previsiones POSTERIORES al arranque cuya fecha ya pasó y que nadie generó.
     *
     * ─────────────────────── Por qué esto tiene que existir ───────────────────────
     *
     * Una previsión no puede desaparecer solo porque el calendario avanzó. Con la
     * generación automática apagada —que es como está hoy— nadie crea la obligación
     * cuando llega el día: si el 28 de septiembre de Starlink dejara de mostrarse el
     * 29, esa cuenta se evaporaría de la pantalla sin que nadie decidiera nada. El
     * dinero seguiría debiéndose y el sistema habría dejado de mencionarlo. Ese es el
     * peor fallo posible en una pantalla cuyo trabajo es decir qué se debe.
     *
     * Así que se queda visible, en su propio grupo, hasta que una persona resuelva:
     * generar el período —y entonces pasa a ser deuda confirmada, y esta fila
     * desaparece porque ya existe la `Ocurrencia`— o pausar la regla.
     *
     * ─────────────────────────── Lo que NO es este grupo ───────────────────────────
     *
     * NO ES DEUDA CONFIRMADA y va separado de ella: nadie verificó que se deba. No
     * suma a `se_debe`. Tampoco suma a `se_espera`, porque ya no es «lo que viene».
     * No tiene botón de pagar: no se puede pagar algo que no existe como obligación.
     *
     * Y NO SE SOLAPA CON NADA. Si el período ya se generó hay una `Ocurrencia` y esta
     * fila no se emite, así que la obligación real y esta nunca conviven. Como no
     * existe el gasto, tampoco puede haber un pago aplicado contra él: la ausencia de
     * `Ocurrencia` es la comprobación exacta, no una aproximación.
     *
     * ──────────────────────────── Hasta dónde mira atrás ────────────────────────────
     *
     * Hasta el arranque de la regla, sin recorte por antigüedad. Recortarlo sería
     * reintroducir el mismo fallo con otro nombre: una fila que se borra sola al
     * cumplir X días es igual de invisible que una que nunca se mostró. Si una regla
     * vieja acumula muchas, esa lista larga es información correcta —hay muchas
     * decisiones pendientes—, no un defecto. El cálculo es aritmética de fechas sin
     * base de datos, así que el costo es de la regla, no de la cartera.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function fechasPasadasSinConfirmar(User $usuario, array $filtros, CarbonImmutable $hoy): Collection
    {
        $pasadas = collect();
        $ayer = $hoy->subDay();

        foreach ($this->reglasVisibles($usuario, $filtros) as $regla) {
            $yaGenerados = $this->periodosYaGenerados($regla);
            $arranque = CarbonImmutable::parse($regla->vigente_desde->toDateString());

            // Desde el arranque —nunca antes— hasta ayer. Lo de hoy en adelante sigue
            // siendo previsión y se cuenta allá.
            $tope = $ayer;
            if ($regla->vigente_hasta !== null) {
                $fin = CarbonImmutable::parse($regla->vigente_hasta->toDateString());
                $tope = $tope->gt($fin) ? $fin : $tope;
            }

            foreach ($this->calendario->periodos($regla->calendario(), $arranque, $tope) as $periodo) {
                // Ya existe la obligación: manda ella y esta fila no se emite.
                if (in_array($periodo['periodo'], $yaGenerados, true)) {
                    continue;
                }

                $pasadas->push((object) [
                    'es_prevision' => false,
                    'regla_id' => $regla->id,
                    'beneficiario' => $regla->beneficiario,
                    'concepto' => $regla->concepto,
                    'ambito' => $regla->ambito,
                    'moneda' => $regla->moneda,
                    'periodo' => $periodo['periodo'],
                    'vence' => $periodo['vence']->toDateString(),
                    'monto_modo' => $regla->monto_modo,
                    // El importe que la regla PREVEÍA, a título informativo. No se
                    // suma a ninguna cifra de la pantalla.
                    'importe_previsto' => $regla->monto_modo === 'variable' || $regla->importe === null
                        ? null
                        : Dinero::centavos((string) $regla->importe),
                ]);
            }
        }

        return $pasadas->sortBy('vence')->values();
    }

    /**
     * ¿Es `$periodo` un período que esta regla de verdad produce y que se puede pagar
     * desde la pantalla? Devuelve su vencimiento, o null si no lo es.
     *
     * ES EL CANDADO DEL LADO DEL SERVIDOR, y no una comodidad. La pantalla manda la
     * clave del período en un campo oculto; sin esta comprobación, alguien podría
     * enviar «2026-08» a mano y fabricar —y pagar— una mensualidad de agosto que la
     * regla nunca debió generar. La exclusión de lo anterior al arranque dejaría de
     * ser una regla del sistema para ser una decoración de la vista.
     *
     * La ventana es EXACTAMENTE la que la pantalla puede mostrar: del arranque de la
     * regla al fin del mes en curso. Ni un día antes —ahí está la exclusión— ni un mes
     * después, que sería adelantar deuda que nadie pidió.
     */
    public function periodoPagable(Regla $regla, string $periodo, CarbonImmutable $hoy): ?CarbonImmutable
    {
        if (! $regla->activa()) {
            return null;
        }

        $desde = CarbonImmutable::parse($regla->vigente_desde->toDateString());
        $hasta = $hoy->endOfMonth();

        if ($regla->vigente_hasta !== null) {
            $fin = CarbonImmutable::parse($regla->vigente_hasta->toDateString());
            $hasta = $hasta->gt($fin) ? $fin : $hasta;
        }

        foreach ($this->calendario->periodos($regla->calendario(), $desde, $hasta) as $candidato) {
            if ($candidato['periodo'] === $periodo) {
                return $candidato['vence'];
            }
        }

        return null;
    }

    /**
     * Cuánto del saldo registrado es PROVISIONAL: acordado de palabra, sin papel.
     *
     * Va como cifra aparte para poder decir «de este total, tanto está pendiente de
     * confirmar». Se debe igual —por eso suma dentro de `se_debe` y no fuera—, pero
     * presentar el total como si todo estuviera respaldado sería afirmar de más.
     *
     * @param  array<string, Collection<int, Gasto>>  $obligaciones
     */
    private function provisionalDe(array $obligaciones): int
    {
        return collect(['vencido', 'este_mes', 'mas_adelante', 'sin_fecha'])
            ->sum(fn (string $grupo) => $obligaciones[$grupo]
                ->filter(fn (Gasto $g) => $g->esProvisional())
                ->sum('pendiente'));
    }

    /**
     * Reglas a las que les falta un dato para poder repetirse: el día, el importe o
     * los dos. Son borradores y se quedan así hasta que alguien los complete.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function porCompletar(User $usuario, array $filtros): Collection
    {
        return $this->reglasVisibles($usuario, $filtros, ['borrador'])
            ->map(function (Regla $regla) {
                $falta = $regla->faltantes();

                return (object) [
                    'regla_id' => $regla->id,
                    'beneficiario' => $regla->beneficiario,
                    'concepto' => $regla->concepto,
                    'ambito' => $regla->ambito,
                    'moneda' => $regla->moneda,
                    'monto_modo' => $regla->monto_modo,
                    'importe' => $regla->importe === null ? null : Dinero::centavos((string) $regla->importe),
                    // Con el día y el importe puestos, lo que falta es OTRA cosa —el
                    // nombre de la institución, por ejemplo— y está escrito en las
                    // observaciones de la regla. Decir «falta la programación» sería
                    // mentir sobre un dato que sí está. La lista la arma
                    // {@see Regla::faltantes()}, la misma que usa el detalle.
                    'falta' => $falta === [] ? 'un dato por confirmar' : implode(' y ', $falta),
                ];
            })
            ->values();
    }

    // ═══════════════════════════ Lo que ya salió ═══════════════════════════

    /**
     * Pagos y abonos del mes en curso, por su fecha REAL —cuando salió el dinero—, no
     * por cuándo se registraron.
     *
     * El candado de ámbito es el de siempre: un pago entra si toca al menos una
     * obligación que este usuario alcanza, y el importe que se muestra es el subtotal
     * visible, no el total del pago. Un pago mixto no revela por acá su cifra completa.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function pagadoEnElMes(User $usuario, array $filtros, CarbonImmutable $hoy): Collection
    {
        $alcanzables = $this->recortado($usuario, $filtros)->select('gastos.id');

        return collect(DB::table('gastos_pagos as p')
            ->join('gastos_pago_aplicaciones as a', 'a.pago_id', '=', 'p.id')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->joinSub($alcanzables, 'vis', 'vis.id', '=', 'c.gasto_id')
            ->whereNull('p.revertido_at')
            ->whereBetween('p.fecha', [$hoy->startOfMonth()->toDateString(), $hoy->endOfMonth()->toDateString()])
            ->groupBy('p.id', 'p.fecha', 'p.metodo', 'p.moneda', 'p.referencia')
            ->select('p.id', 'p.fecha', 'p.metodo', 'p.moneda', 'p.referencia')
            ->selectRaw('SUM('.$this->centavos('a.importe').') as visible')
            ->orderByDesc('p.fecha')->orderByDesc('p.id')
            ->get());
    }

    // ═══════════════════════════ Piezas compartidas ═══════════════════════════

    /**
     * La consulta de gastos ya recortada por permiso de ámbito, protección y filtro.
     *
     * @param  array<string, mixed>  $filtros
     */
    private function recortado(User $usuario, array $filtros): Builder
    {
        $consulta = Gasto::query();

        // Candado real: quien no tiene el permiso no RECIBE las filas personales. El
        // chip de arriba es comodidad; esto es el candado.
        if (! $usuario->can('gastos.personales')) {
            $consulta->where('gastos.ambito', 'empresarial');
        } elseif (in_array($filtros['ambito'] ?? null, ['empresarial', 'personal'], true)) {
            $consulta->where('gastos.ambito', $filtros['ambito']);
        }

        $this->consulta->ocultarProtegidos($consulta, $usuario, 'gastos.id');

        return $consulta;
    }

    /**
     * Reglas que este usuario puede ver, con el mismo criterio de ámbito que los gastos.
     *
     * @param  array<string, mixed>  $filtros
     * @param  array<int, string>  $estados
     * @return Collection<int, Regla>
     */
    private function reglasVisibles(User $usuario, array $filtros, array $estados = ['activa']): Collection
    {
        // Sin el esquema de fase 2 no hay reglas que proyectar, y preguntar por una
        // tabla que no existe tumbaría la pantalla entera —que fue exactamente lo que
        // pasó el día que una vista compartida consultó una tabla de módulo—.
        if (! Schema::hasTable('gastos_reglas')) {
            return collect();
        }

        $consulta = Regla::query()->whereIn('estado', $estados);

        if (! $usuario->can('gastos.personales')) {
            $consulta->where('ambito', 'empresarial');
        } elseif (in_array($filtros['ambito'] ?? null, ['empresarial', 'personal'], true)) {
            $consulta->where('ambito', $filtros['ambito']);
        }

        return $consulta->orderBy('beneficiario')->get();
    }

    /**
     * Las claves de período que esta regla YA generó.
     *
     * Incluye las omitidas a propósito: omitir un mes es una decisión tomada, y
     * volver a proyectarlo la desharía en la pantalla.
     *
     * @return array<int, string>
     */
    private function periodosYaGenerados(Regla $regla): array
    {
        if (! Schema::hasTable('gastos_ocurrencias')) {
            return [];
        }

        return Ocurrencia::where('regla_id', $regla->id)->pluck('periodo')->all();
    }

    /** Mismo CAST que ConsultaGastos: SQLite no acepta SIGNED y MySQL no acepta INTEGER. */
    private function centavos(string $columna): string
    {
        $tipo = DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        return "CAST(ROUND($columna * 100) AS $tipo)";
    }
}
