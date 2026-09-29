<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Contracts\ProteccionDeGastos;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La pantalla principal de Gastos: filtros, pestañas y totales.
 *
 * Todo el cálculo baja a SQL y SIEMPRE en centavos enteros
 * (`CAST(ROUND(importe * 100) AS INTEGER)`). No es cosmética: sumar dinero en
 * coma flotante produce diferencias de un centavo que después nadie encuentra.
 *
 * El ámbito se filtra en la CONSULTA, no al pintar. Quien no tiene
 * `gastos.personales` no recibe esas filas, así que tampoco puede deducirlas de
 * un total, de un contador de pestaña ni de una exportación.
 *
 * Lo mismo vale para las obligaciones que protege otro módulo —hoy, los sueldos que
 * genera una planilla—: {@see ocultarProtegidos()} las recorta en la consulta por la
 * misma razón. Ver el importe de un sueldo y deducirlo de un total son la misma fuga.
 */
final class ConsultaGastos
{
    public function __construct(private ProteccionDeGastos $proteccion) {}

    public const PESTANAS = [
        'pendientes' => 'Pendientes',
        'vencidos' => 'Vencidos',
        'hoy' => 'Vencen hoy',
        'proximos' => 'Próximos',
        // Incluye los saldados por NOTA DE CRÉDITO, no solo los pagados: la pestaña
        // pregunta «¿esto ya no se debe?», y hay dos maneras de dejar de deberlo. La
        // fila distingue cuál fue; el nombre no puede decir «Pagados» y mentir.
        'pagados' => 'Ya saldados',
        'por_completar' => 'Por completar',
    ];

    /**
     * Expresión SQL que convierte una columna DECIMAL en CENTAVOS ENTEROS.
     *
     * El nombre del tipo NO es el mismo en los dos motores: MySQL solo acepta
     * `SIGNED` en un CAST y SQLite usa `INTEGER`. Escribirlo en un único sitio evita
     * que la consulta funcione en las pruebas (SQLite) y reviente en desarrollo y
     * producción (MySQL), que es exactamente lo que pasó.
     */
    private function centavos(string $columna): string
    {
        $tipo = DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        return "CAST(ROUND($columna * 100) AS $tipo)";
    }

    /**
     * Subconsulta: una fila por cuota con su saldo, su pagado y su fecha, en centavos.
     *
     * `$corte` convierte esto en una foto HISTÓRICA: el saldo tal como era al cerrar
     * ese día. Sin él, la consulta describe el estado de ahora.
     *
     * Por qué importa. «Pendiente al 31 de enero» no puede cambiar porque en febrero
     * alguien pague: el 31 de enero ese dinero no había salido. Y al revés: un pago de
     * enero que se revirtió en marzo SÍ contaba el 31 de enero, así que revertirlo
     * después no puede reescribir hacia atrás lo que el informe decía. Antes no había
     * ningún filtro de fecha acá y el informe «a una fecha» mostraba, en realidad, el
     * saldo de hoy con una etiqueta de otro día.
     *
     * Las dos condiciones, para cada movimiento:
     *   - ocurrió en o antes del corte, y
     *   - al cerrar ese día TODAVÍA no estaba revertido.
     *
     * Los pagos usan su fecha REAL (`fecha`), que es cuando salió el dinero. Los
     * ajustes usan `created_at`, que es lo único que tienen: cuando se les agregue una
     * fecha de efecto habrá que usarla acá.
     */
    private function cuotasConSaldo(?string $corte = null): Builder
    {
        $centavos = fn (string $col) => $this->centavos($col);
        $finDelCorte = $corte !== null ? $corte.' 23:59:59' : null;

        $aplicado = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->when($corte === null,
                fn ($q) => $q->whereNull('p.revertido_at'),
                // `whereDate` y no `where`: `p.fecha` es DATE en MySQL, pero SQLite
                // guarda lo que Laravel le manda —«2026-09-11 00:00:00»— y comparado
                // como cadena contra «2026-09-11» queda FUERA. Es decir: los pagos del
                // PROPIO día de corte desaparecían, y «pendiente al 31 de enero»
                // ignoraba lo que se pagó el 31. Los ajustes de abajo no necesitan esto
                // porque `created_at` sí es un instante y se compara contra el fin del
                // día.
                fn ($q) => $q->whereDate('p.fecha', '<=', $corte)
                    ->where(fn ($w) => $w->whereNull('p.revertido_at')->orWhere('p.revertido_at', '>', $finDelCorte)))
            ->groupBy('a.cuota_id')
            ->select('a.cuota_id')
            ->selectRaw('SUM('.$centavos('a.importe').') as total');

        $ajuste = fn (string $direccion) => DB::table('gastos_ajustes')
            ->where('direccion', $direccion)
            ->when($corte === null,
                fn ($q) => $q->whereNull('revertido_at'),
                fn ($q) => $q->where('created_at', '<=', $finDelCorte)
                    ->where(fn ($w) => $w->whereNull('revertido_at')->orWhere('revertido_at', '>', $finDelCorte)))
            ->groupBy('cuota_id')
            ->select('cuota_id')
            ->selectRaw('SUM('.$centavos('importe').') as total');

        return DB::table('gastos_cuotas as c')
            ->leftJoinSub($aplicado, 'ap', 'ap.cuota_id', '=', 'c.id')
            ->leftJoinSub($ajuste('credito'), 'cr', 'cr.cuota_id', '=', 'c.id')
            ->leftJoinSub($ajuste('debito'), 'db', 'db.cuota_id', '=', 'c.id')
            ->select('c.gasto_id', 'c.vence')
            ->selectRaw($centavos('c.importe').' + COALESCE(db.total, 0) - COALESCE(cr.total, 0) - COALESCE(ap.total, 0) as saldo')
            ->selectRaw('COALESCE(ap.total, 0) as pagado');
    }

    /**
     * Subconsulta: una fila por gasto con pendiente, vencido, pagado y próxima fecha.
     *
     * `$hoy` decide qué cuenta como VENCIDO. `$corte` decide qué movimientos existen
     * ya: pasalo solo cuando quieras una foto histórica, no en el listado del día.
     */
    public function saldosPorGasto(string $hoy, ?string $corte = null): Builder
    {
        return DB::query()->fromSub($this->cuotasConSaldo($corte), 'x')
            ->groupBy('x.gasto_id')
            ->select('x.gasto_id')
            ->selectRaw('SUM(x.saldo) as pendiente')
            ->selectRaw('SUM(CASE WHEN x.saldo > 0 AND x.vence IS NOT NULL AND x.vence < ? THEN x.saldo ELSE 0 END) as vencido', [$hoy])
            ->selectRaw('SUM(x.pagado) as pagado')
            ->selectRaw('MIN(CASE WHEN x.saldo > 0 THEN x.vence END) as proxima');
    }

    /**
     * Consulta base ya recortada por permiso y por los filtros del usuario.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function base(User $usuario, array $filtros, string $hoy): \Illuminate\Database\Eloquent\Builder
    {
        $consulta = Gasto::query()
            ->leftJoinSub($this->saldosPorGasto($hoy), 's', 's.gasto_id', '=', 'gastos.id')
            ->select('gastos.*')
            ->addSelect(['s.pendiente', 's.vencido', 's.pagado', 's.proxima']);

        // Candado de ámbito EN LA CONSULTA. No es una decisión de presentación.
        if (! $usuario->can('gastos.personales')) {
            $consulta->where('gastos.ambito', 'empresarial');
        } elseif (in_array($filtros['ambito'] ?? null, ['empresarial', 'personal'], true)) {
            $consulta->where('gastos.ambito', $filtros['ambito']);
        }

        $this->ocultarProtegidos($consulta, $usuario, 'gastos.id');

        if (filled($filtros['moneda'] ?? null)) {
            $consulta->where('gastos.moneda', $filtros['moneda']);
        }

        if (filled($filtros['categoria'] ?? null)) {
            $consulta->where('gastos.categoria', $filtros['categoria']);
        }

        if (filled($filtros['responsable_id'] ?? null)) {
            $consulta->where('gastos.responsable_id', $filtros['responsable_id']);
        }

        if (filled($filtros['q'] ?? null)) {
            // `escape` evita que un % o un _ escritos por el usuario se conviertan en
            // comodines silenciosos y devuelvan de más.
            $texto = '%'.addcslashes(trim((string) $filtros['q']), '%_\\').'%';
            $consulta->where(function ($q) use ($texto) {
                $q->where('gastos.concepto', 'like', $texto)
                    ->orWhere('gastos.beneficiario', 'like', $texto)
                    ->orWhere('gastos.categoria', 'like', $texto)
                    ->orWhere('gastos.persona', 'like', $texto);
            });
        }

        return $this->aplicarPestana($consulta, $filtros['pestana'] ?? 'pendientes', $hoy);
    }

    private function aplicarPestana(\Illuminate\Database\Eloquent\Builder $consulta, string $pestana, string $hoy): \Illuminate\Database\Eloquent\Builder
    {
        return match ($pestana) {
            // Esperando monto: NO entra en ninguna pestaña de saldo, porque no tiene uno.
            'por_completar' => $consulta->whereNull('gastos.importe')->orderBy('gastos.created_at', 'desc'),

            'vencidos' => $consulta->whereNotNull('gastos.importe')
                ->where('s.vencido', '>', 0)
                ->orderBy('s.proxima'),

            'hoy' => $consulta->whereNotNull('gastos.importe')
                ->where('s.pendiente', '>', 0)
                ->whereDate('s.proxima', '=', $hoy)
                ->orderBy('s.proxima'),

            'proximos' => $consulta->whereNotNull('gastos.importe')
                ->where('s.pendiente', '>', 0)
                ->where('s.proxima', '>', $hoy)
                ->orderBy('s.proxima'),

            // «Pagados» son los que ya no tienen saldo. Los saldados por ajuste se
            // distinguen en la fila: no se venden como pagados.
            'pagados' => $consulta->whereNotNull('gastos.importe')
                ->where('s.pendiente', '<=', 0)
                ->orderBy('gastos.updated_at', 'desc'),

            default => $consulta->whereNotNull('gastos.importe')
                ->where('s.pendiente', '>', 0)
                // Sin fecha al final: no se puede reclamar lo que no vence.
                ->orderByRaw('CASE WHEN s.proxima IS NULL THEN 1 ELSE 0 END, s.proxima'),
        };
    }

    /** @param  array<string, mixed>  $filtros */
    public function pagina(User $usuario, array $filtros, string $hoy, int $porPagina = 25): LengthAwarePaginator
    {
        return $this->base($usuario, $filtros, $hoy)->paginate($porPagina)->withQueryString();
    }

    /**
     * Cuántas filas tiene cada pestaña CON LOS FILTROS PUESTOS. Se calcula aparte de
     * la paginación para que el usuario vea si su búsqueda tiene resultados en otra
     * pestaña antes de cambiarla.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, int>
     */
    public function conteos(User $usuario, array $filtros, string $hoy): array
    {
        $conteos = [];
        foreach (array_keys(self::PESTANAS) as $pestana) {
            $conteos[$pestana] = $this->base($usuario, ['pestana' => $pestana] + $filtros, $hoy)
                ->reorder()->count();
        }

        return $conteos;
    }

    /**
     * Totales de la cabecera, SEPARADOS por ámbito y por moneda.
     *
     * Pendiente, vencido y pagado NO se suman entre sí: vencido es un subconjunto de
     * pendiente y pagado pertenece al período, no al saldo. Se devuelven por separado
     * justamente para que nadie los sume.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function totales(User $usuario, array $filtros, string $hoy): Collection
    {
        // Sin pestaña: los totales describen la cartera filtrada, no la página abierta.
        $sinPestana = ['pestana' => 'todos'] + $filtros;

        $consulta = Gasto::query()
            ->leftJoinSub($this->saldosPorGasto($hoy), 's', 's.gasto_id', '=', 'gastos.id')
            ->whereNotNull('gastos.importe');

        $this->recortarPorAmbitoYFiltros($consulta, $usuario, $sinPestana);

        return $consulta->groupBy('gastos.moneda', 'gastos.ambito')
            ->select('gastos.moneda', 'gastos.ambito')
            ->selectRaw('SUM(CASE WHEN s.pendiente > 0 THEN s.pendiente ELSE 0 END) as pendiente')
            ->selectRaw('SUM(s.vencido) as vencido')
            ->selectRaw('SUM(s.pagado) as pagado')
            ->selectRaw('COUNT(*) as cantidad')
            ->orderBy('gastos.moneda')->orderBy('gastos.ambito')
            ->get();
    }

    /** Cuántos gastos están esperando monto: se informan aparte, no dentro de los totales. */
    public function esperandoMonto(User $usuario, array $filtros): int
    {
        $consulta = Gasto::query()->whereNull('gastos.importe');
        $this->recortarPorAmbitoYFiltros($consulta, $usuario, $filtros);

        return $consulta->count();
    }

    /**
     * Cuotas PAGABLES de un destinatario y moneda: las que aún tienen saldo, dentro
     * del alcance del usuario. Es lo que alimenta la pantalla de «Registrar pago»,
     * donde un pago puede cubrir varias obligaciones a la vez.
     *
     * Un gasto sin monto conocido NO aparece: no se puede pagar lo que todavía no se
     * sabe cuánto es.
     *
     * @return Collection<int, object>
     */
    public function cuotasPagables(User $usuario, string $beneficiario, string $moneda, string $hoy): Collection
    {
        $consulta = DB::table('gastos_cuotas as c')
            ->join('gastos as g', 'g.id', '=', 'c.gasto_id')
            ->leftJoinSub($this->saldoPorCuota(), 'sc', 'sc.cuota_id', '=', 'c.id')
            ->where('g.beneficiario', $beneficiario)
            ->where('g.moneda', $moneda)
            ->whereNotNull('g.importe')
            ->havingRaw('saldo > 0');

        if (! $usuario->can('gastos.personales')) {
            $consulta->where('g.ambito', 'empresarial');
        }

        $this->ocultarProtegidos($consulta, $usuario, 'c.gasto_id');

        return $consulta
            ->select('c.id as cuota_id', 'c.numero', 'c.vence', 'c.importe as cuota_importe',
                'g.id as gasto_id', 'g.concepto', 'g.ambito', 'g.categoria', 'g.moneda')
            ->selectRaw('COALESCE(sc.saldo, '.$this->centavos('c.importe').') as saldo')
            ->selectRaw('CASE WHEN c.vence IS NOT NULL AND c.vence < ? THEN 1 ELSE 0 END as vencida', [$hoy])
            // Más antiguas primero: es el orden en que se propone cubrirlas.
            ->orderByRaw('CASE WHEN c.vence IS NULL THEN 1 ELSE 0 END, c.vence, g.id, c.numero')
            ->get();
    }

    /**
     * HISTORIAL DE PAGOS: el dinero que salió, recortado por lo que cada quien alcanza.
     *
     * Responde otra pregunta que «Por pagar». Allá se listan OBLIGACIONES —lo que se
     * debe—; acá PAGOS —lo que se pagó—. No son la misma cosa y por eso no son la
     * misma pantalla: una deuda saldada con nota de crédito aparece como saldada en
     * Por pagar y no aparece acá, porque no salió dinero.
     *
     * EL CANDADO DE ÁMBITO VA EN LA CONSULTA. Un pago entra en el listado solo si
     * toca al menos una obligación que este usuario alcanza, y las columnas traen:
     *
     *   importe_visible    lo aplicado a obligaciones que SÍ alcanza
     *   gastos_visibles    cuántas de ellas ve
     *   gastos_totales     cuántas cubre el pago en total
     *
     * Cuando `gastos_visibles < gastos_totales` el pago es MIXTO y el usuario no lo
     * alcanza entero: la pantalla muestra su subtotal y esconde el importe total, la
     * referencia y el resto de la cabecera. Mostrar el total delataría el importe
     * personal aunque se ocultara su fila —es el acuerdo de fase 1, el mismo que
     * defiende PagoMixtoTest—.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function pagosVisibles(User $usuario, array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        return $this->basePagos($usuario, $filtros)
            ->orderByDesc('gastos_pagos.fecha')
            ->orderByDesc('gastos_pagos.id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * Totales del historial, SEPARADOS POR MONEDA y sin sumar lo revertido.
     *
     * Cada moneda lleva sus propios totales y no hay conversión: sumar dólares con
     * otra cosa daría una cifra que no significa nada. Y un pago revertido no es
     * dinero que salió —se deshizo—, así que no entra en el total; se cuenta aparte
     * para que nadie crea que desapareció sin más.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function totalesPagos(User $usuario, array $filtros): Collection
    {
        return $this->basePagos($usuario, $filtros)
            ->reorder()
            ->groupBy('gastos_pagos.moneda')
            ->select('gastos_pagos.moneda')
            ->selectRaw('SUM(CASE WHEN gastos_pagos.revertido_at IS NULL THEN v.importe_visible ELSE 0 END) as vigente')
            ->selectRaw('SUM(CASE WHEN gastos_pagos.revertido_at IS NOT NULL THEN v.importe_visible ELSE 0 END) as revertido')
            ->selectRaw('SUM(CASE WHEN gastos_pagos.revertido_at IS NULL THEN 1 ELSE 0 END) as pagos')
            ->selectRaw('SUM(CASE WHEN gastos_pagos.revertido_at IS NOT NULL THEN 1 ELSE 0 END) as pagos_revertidos')
            ->orderBy('gastos_pagos.moneda')
            ->get();
    }

    /** @param  array<string, mixed>  $filtros */
    private function basePagos(User $usuario, array $filtros): \Illuminate\Database\Eloquent\Builder
    {
        $centavos = fn (string $col) => $this->centavos($col);

        $ambitos = ['empresarial'];
        if ($usuario->can('gastos.personales')) {
            $ambitos[] = 'personal';
        }

        // Lo que este usuario alcanza de cada pago.
        $visible = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->join('gastos as g', 'g.id', '=', 'c.gasto_id')
            ->whereIn('g.ambito', $ambitos)
            ->groupBy('a.pago_id');

        // Un pago de planilla no es visible para quien no alcanza los salarios. Como
        // `$visible` entra con JOIN y no con leftJoin, el pago que solo cubre
        // obligaciones protegidas desaparece entero del historial, sin dejar ni su
        // fecha ni su importe.
        $this->ocultarProtegidos($visible, $usuario, 'g.id');

        $visible
            ->select('a.pago_id')
            ->selectRaw('SUM('.$centavos('a.importe').') as importe_visible')
            ->selectRaw('COUNT(DISTINCT g.id) as gastos_visibles');

        // Cuántas obligaciones cubre en total, sin recortar. Solo se usa para saber si
        // el pago es mixto; no expone ni un importe.
        $todos = DB::table('gastos_pago_aplicaciones as a2')
            ->join('gastos_cuotas as c2', 'c2.id', '=', 'a2.cuota_id')
            ->groupBy('a2.pago_id')
            ->select('a2.pago_id')
            ->selectRaw('COUNT(DISTINCT c2.gasto_id) as gastos_totales');

        $consulta = Pago::query()
            // JOIN y no leftJoin: un pago del que no se alcanza NADA no aparece.
            ->joinSub($visible, 'v', 'v.pago_id', '=', 'gastos_pagos.id')
            ->joinSub($todos, 't', 't.pago_id', '=', 'gastos_pagos.id')
            ->select('gastos_pagos.*')
            ->addSelect(['v.importe_visible', 'v.gastos_visibles', 't.gastos_totales'])
            // Cuántos comprobantes tiene colgados. Solo el NÚMERO: el archivo sigue
            // sirviéndose por el controlador autorizado, que exige alcanzar el pago
            // COMPLETO. Saber que existe un comprobante no revela su contenido, y sin
            // este dato la lista no puede decir cuáles quedaron sin respaldo.
            ->selectSub(
                DB::table('gastos_adjuntos')->whereColumn('gastos_adjuntos.pago_id', 'gastos_pagos.id')
                    ->selectRaw('COUNT(*)'),
                'comprobantes'
            );

        if (filled($filtros['desde'] ?? null)) {
            $consulta->whereDate('gastos_pagos.fecha', '>=', $filtros['desde']);
        }

        if (filled($filtros['hasta'] ?? null)) {
            $consulta->whereDate('gastos_pagos.fecha', '<=', $filtros['hasta']);
        }

        if (filled($filtros['metodo'] ?? null)) {
            $consulta->where('gastos_pagos.metodo', $filtros['metodo']);
        }

        if (filled($filtros['moneda'] ?? null)) {
            $consulta->where('gastos_pagos.moneda', $filtros['moneda']);
        }

        // Revertidos: se ven por defecto —esconderlos haría desaparecer dinero que
        // alguien recuerda haber registrado— pero se pueden aislar o excluir.
        if (($filtros['revertidos'] ?? 'incluir') === 'excluir') {
            $consulta->whereNull('gastos_pagos.revertido_at');
        } elseif (($filtros['revertidos'] ?? null) === 'solo') {
            $consulta->whereNotNull('gastos_pagos.revertido_at');
        }

        // Empresa / Personal. Es un filtro de COMODIDAD sobre lo que ya se alcanza: un
        // pago entra si toca alguna obligación de ese ámbito que este usuario pueda
        // ver. Quien no tiene `gastos.personales` no lo ve y tampoco lo necesita,
        // porque `$ambitos` ya lo dejó fuera arriba.
        if (in_array($filtros['ambito'] ?? null, ['empresarial', 'personal'], true)
            && in_array($filtros['ambito'], $ambitos, true)) {
            $consulta->whereExists(function ($q) use ($filtros, $usuario) {
                $q->select(DB::raw(1))
                    ->from('gastos_pago_aplicaciones as fa')
                    ->join('gastos_cuotas as fc', 'fc.id', '=', 'fa.cuota_id')
                    ->join('gastos as fg', 'fg.id', '=', 'fc.gasto_id')
                    ->whereColumn('fa.pago_id', 'gastos_pagos.id')
                    ->where('fg.ambito', $filtros['ambito']);
                $this->ocultarProtegidos($q, $usuario, 'fg.id');
            });
        }

        if (filled($filtros['q'] ?? null)) {
            $texto = '%'.addcslashes(trim((string) $filtros['q']), '%_\\').'%';

            $consulta->where(function ($q) use ($texto, $ambitos, $usuario) {
                $q->where('gastos_pagos.beneficiario', 'like', $texto)
                    ->orWhere('gastos_pagos.referencia', 'like', $texto)
                    // Buscar POR CONCEPTO: «universidad», «pepitoria», «luz». Es lo que
                    // una persona recuerda de un pago, mucho más que su referencia
                    // bancaria. Va por EXISTS y no por join para no multiplicar filas
                    // cuando un pago cubre varias obligaciones.
                    //
                    // El candado de siempre viaja dentro del EXISTS: solo se busca
                    // dentro de las obligaciones que este usuario alcanza, así que
                    // nadie puede deducir el concepto de un gasto ajeno tanteando
                    // palabras y mirando cuáles devuelven resultados.
                    ->orWhereExists(function ($sub) use ($texto, $ambitos, $usuario) {
                        $sub->select(DB::raw(1))
                            ->from('gastos_pago_aplicaciones as qa')
                            ->join('gastos_cuotas as qc', 'qc.id', '=', 'qa.cuota_id')
                            ->join('gastos as qg', 'qg.id', '=', 'qc.gasto_id')
                            ->whereColumn('qa.pago_id', 'gastos_pagos.id')
                            ->whereIn('qg.ambito', $ambitos)
                            ->where(function ($w) use ($texto) {
                                $w->where('qg.concepto', 'like', $texto)
                                    ->orWhere('qg.beneficiario', 'like', $texto)
                                    ->orWhere('qg.categoria', 'like', $texto);
                            });
                        $this->ocultarProtegidos($sub, $usuario, 'qg.id');
                    });
            });
        }

        return $consulta;
    }

    /**
     * QUÉ SE PAGÓ en cada pago: el reparto, con el concepto de cada obligación.
     *
     * El historial decía «1 obligación» y esa es una etiqueta que no responde ninguna
     * pregunta: quien busca un pago recuerda «la universidad de septiembre» o «lo de
     * la pepitoria», no cuántas filas tenía. Acá viene el concepto de cada obligación
     * cubierta, con su período cuando lo tiene.
     *
     * ─────────────────────────── Lo que el saldo significa ───────────────────────────
     *
     * `pendiente_actual` es el saldo de HOY de esa cuota, no el que quedó justo
     * después de este pago. Son cosas distintas en cuanto haya un segundo movimiento,
     * y por eso la pantalla lo dice con esas palabras en vez de dejar una cifra
     * ambigua al lado de una fecha vieja. Reconstruir el saldo histórico exigiría
     * recorrer todos los movimientos anteriores a este pago; no se hace aquí y no se
     * finge que se hizo.
     *
     * El candado es el de siempre: solo se devuelven las aplicaciones que este usuario
     * alcanza. Un pago mixto enseña sus filas de empresa y calla las personales.
     *
     * @param  array<int, int>  $pagoIds
     * @return Collection<int, Collection<int, object>> indexada por pago_id
     */
    public function repartoDePagos(User $usuario, array $pagoIds): Collection
    {
        if ($pagoIds === []) {
            return collect();
        }

        $consulta = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->join('gastos as g', 'g.id', '=', 'c.gasto_id')
            ->leftJoinSub($this->saldoPorCuota(), 'sc', 'sc.cuota_id', '=', 'c.id')
            ->whereIn('a.pago_id', $pagoIds);

        if (! $usuario->can('gastos.personales')) {
            $consulta->where('g.ambito', 'empresarial');
        }

        $this->ocultarProtegidos($consulta, $usuario, 'g.id');

        return $consulta
            ->select('a.pago_id', 'a.importe as aplicado', 'c.id as cuota_id', 'c.numero', 'c.vence',
                'g.id as gasto_id', 'g.concepto', 'g.beneficiario', 'g.ambito', 'g.categoria',
                'g.naturaleza', 'g.moneda', 'g.periodo_desde')
            ->selectRaw('COALESCE(sc.saldo, '.$this->centavos('c.importe').') as pendiente_actual')
            ->orderBy('a.pago_id')->orderBy('g.id')->orderBy('c.numero')
            ->get()
            ->groupBy('pago_id');
    }

    /**
     * Cuotas con saldo abierto de TODO el sistema, para armar avisos.
     *
     * No recibe un usuario porque los avisos se arman por lote, para muchas personas
     * a la vez; el recorte de ámbito viaja en `$ambitos` y lo decide quien llama,
     * cruzando la preferencia de cada quien con su permiso. Devolver una cuota acá NO
     * autoriza a nadie a verla.
     *
     * Un gasto sin importe conocido no aparece: no tiene saldo, y avisar de una deuda
     * cuya cifra nadie sabe sería inventarla. Esos van por su propio camino
     * («falta el monto»), que no habla de dinero.
     *
     * @param  array<int, string>  $ambitos
     * @return Collection<int, object>
     */
    public function cuotasAbiertas(string $hoy, array $ambitos): Collection
    {
        if ($ambitos === []) {
            return collect();
        }

        $consulta = DB::table('gastos_cuotas as c')
            ->join('gastos as g', 'g.id', '=', 'c.gasto_id')
            ->leftJoinSub($this->saldoPorCuota(), 'sc', 'sc.cuota_id', '=', 'c.id')
            ->whereNotNull('g.importe')
            ->whereIn('g.ambito', $ambitos)
            // WHERE y no HAVING: esta consulta no agrupa, y SQLite rechaza un HAVING en
            // una consulta sin agregación («HAVING clause on a non-aggregate query»).
            // Se repite la expresión en vez de usar el alias porque un alias del SELECT
            // tampoco es visible en el WHERE.
            ->whereRaw('COALESCE(sc.saldo, '.$this->centavos('c.importe').') > 0')
            ->select('c.id as cuota_id', 'c.numero', 'c.vence',
                'g.id as gasto_id', 'g.beneficiario', 'g.concepto', 'g.categoria',
                'g.ambito', 'g.moneda', 'g.responsable_id')
            ->selectRaw('COALESCE(sc.saldo, '.$this->centavos('c.importe').') as saldo')
            ->selectRaw('CASE WHEN c.vence IS NOT NULL AND c.vence < ? THEN 1 ELSE 0 END as vencida', [$hoy])
            ->orderByRaw('CASE WHEN c.vence IS NULL THEN 1 ELSE 0 END, c.vence, g.id, c.numero');

        $this->sinProtegidos($consulta, 'c.gasto_id');

        return $consulta->get();
    }

    /**
     * Gastos que siguen esperando su monto, para el aviso «falta el recibo». No son
     * deuda: no tienen importe, no suman pendiente y jamás se reclaman como vencidos.
     *
     * @param  array<int, string>  $ambitos
     * @return Collection<int, object>
     */
    public function esperandoMontoAbiertos(array $ambitos): Collection
    {
        if ($ambitos === []) {
            return collect();
        }

        $consulta = DB::table('gastos as g')
            ->leftJoin('gastos_ocurrencias as o', 'o.gasto_id', '=', 'g.id')
            ->whereNull('g.importe')
            ->whereIn('g.ambito', $ambitos)
            ->select('g.id as gasto_id', 'g.beneficiario', 'g.concepto', 'g.categoria',
                'g.ambito', 'g.moneda', 'g.responsable_id', 'g.created_at',
                'o.vence as vence_esperado', 'o.periodo')
            ->orderBy('g.created_at');

        $this->sinProtegidos($consulta, 'g.id');

        return $consulta->get();
    }

    /**
     * Saca de una tubería que NO es por usuario las obligaciones que otro módulo
     * protege. Hoy la usa solo la de avisos.
     *
     * Los avisos se arman una vez por ámbito y después se reparten entre destinatarios
     * que pueden no alcanzar los salarios: recortar por usuario llegaría tarde, el
     * aviso ya estaría escrito con el nombre y el importe dentro. Por eso las
     * obligaciones de planilla quedan FUERA de los avisos para todo el mundo, incluido
     * quien sí podría verlas. No se pierden: tienen su propia pantalla, que dice
     * exactamente lo mismo y solo la abre quien tiene el permiso laboral.
     */
    private function sinProtegidos(object $consulta, string $columna): void
    {
        if ($protegidos = $this->proteccion->protegidos()) {
            $consulta->whereNotIn($columna, $protegidos);
        }
    }

    /** Saldo por cuota, en centavos, como subconsulta reutilizable. */
    private function saldoPorCuota(): Builder
    {
        $centavos = fn (string $col) => $this->centavos($col);

        $aplicado = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->whereNull('p.revertido_at')
            ->groupBy('a.cuota_id')->select('a.cuota_id')
            ->selectRaw('SUM('.$centavos('a.importe').') as total');

        $ajuste = fn (string $direccion) => DB::table('gastos_ajustes')
            ->whereNull('revertido_at')->where('direccion', $direccion)
            ->groupBy('cuota_id')->select('cuota_id')
            ->selectRaw('SUM('.$centavos('importe').') as total');

        return DB::table('gastos_cuotas as c2')
            ->leftJoinSub($aplicado, 'ap', 'ap.cuota_id', '=', 'c2.id')
            ->leftJoinSub($ajuste('credito'), 'cr', 'cr.cuota_id', '=', 'c2.id')
            ->leftJoinSub($ajuste('debito'), 'db', 'db.cuota_id', '=', 'c2.id')
            ->select('c2.id as cuota_id')
            ->selectRaw($centavos('c2.importe').' + COALESCE(db.total, 0) - COALESCE(cr.total, 0) - COALESCE(ap.total, 0) as saldo');
    }

    /**
     * Destinatarios con saldo abierto, dentro del alcance del usuario. Alimenta el
     * selector de «Registrar pago». Sin catálogo canónico todavía, la identidad es el
     * nombre exacto: no se fusiona nada por parecido.
     *
     * @return Collection<int, object>
     */
    public function beneficiariosConSaldo(User $usuario, string $hoy): Collection
    {
        $consulta = Gasto::query()
            ->joinSub($this->saldosPorGasto($hoy), 's', 's.gasto_id', '=', 'gastos.id')
            ->whereNotNull('gastos.importe')
            ->where('s.pendiente', '>', 0);

        if (! $usuario->can('gastos.personales')) {
            $consulta->where('gastos.ambito', 'empresarial');
        }

        $this->ocultarProtegidos($consulta, $usuario, 'gastos.id');

        return $consulta->groupBy('gastos.beneficiario', 'gastos.moneda')
            ->select('gastos.beneficiario', 'gastos.moneda')
            ->selectRaw('SUM(s.pendiente) as pendiente')
            ->selectRaw('COUNT(*) as gastos')
            ->orderBy('gastos.beneficiario')
            ->get();
    }

    /** Categorías presentes en lo que este usuario puede ver, para el desplegable. */
    public function categorias(User $usuario): Collection
    {
        $consulta = Gasto::query()->select('categoria')->distinct()->orderBy('categoria');

        if (! $usuario->can('gastos.personales')) {
            $consulta->where('ambito', 'empresarial');
        }

        $this->ocultarProtegidos($consulta, $usuario, 'gastos.id');

        return $consulta->pluck('categoria');
    }

    /**
     * Saca de la consulta las obligaciones que otro módulo no le deja ver a este
     * usuario. `$columna` es la que lleva el id del gasto en cada consulta, que no
     * siempre se llama igual: acá hay alias `g.`, `gastos.` y `c.gasto_id`.
     *
     * Se hace en SQL —y no al pintar— porque si no, el gasto seguiría contando en los
     * totales, en el número de la pestaña y en cualquier exportación.
     */
    public function ocultarProtegidos(object $consulta, User $usuario, string $columna): void
    {
        if ($ocultos = $this->proteccion->ocultosPara($usuario)) {
            $consulta->whereNotIn($columna, $ocultos);
        }
    }

    /** @param  array<string, mixed>  $filtros */
    private function recortarPorAmbitoYFiltros(\Illuminate\Database\Eloquent\Builder $consulta, User $usuario, array $filtros): void
    {
        if (! $usuario->can('gastos.personales')) {
            $consulta->where('gastos.ambito', 'empresarial');
        } elseif (in_array($filtros['ambito'] ?? null, ['empresarial', 'personal'], true)) {
            $consulta->where('gastos.ambito', $filtros['ambito']);
        }

        $this->ocultarProtegidos($consulta, $usuario, 'gastos.id');

        foreach (['moneda' => 'moneda', 'categoria' => 'categoria', 'responsable_id' => 'responsable_id'] as $filtro => $columna) {
            if (filled($filtros[$filtro] ?? null)) {
                $consulta->where('gastos.'.$columna, $filtros[$filtro]);
            }
        }

        if (filled($filtros['q'] ?? null)) {
            $texto = '%'.addcslashes(trim((string) $filtros['q']), '%_\\').'%';
            $consulta->where(function ($q) use ($texto) {
                $q->where('gastos.concepto', 'like', $texto)
                    ->orWhere('gastos.beneficiario', 'like', $texto)
                    ->orWhere('gastos.categoria', 'like', $texto)
                    ->orWhere('gastos.persona', 'like', $texto);
            });
        }
    }
}
