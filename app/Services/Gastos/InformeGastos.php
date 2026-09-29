<?php

namespace App\Services\Gastos;

use App\Models\Gastos\Gasto;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes básicos de fase 1: qué falta pagar a una fecha, y qué se pagó en un
 * período.
 *
 * Tres reglas que los hacen legibles y no engañosos:
 *
 *  1. PENDIENTE y PAGADO no son la misma pregunta. El primero es un saldo A UNA
 *     FECHA DE CORTE; el segundo, dinero que salió DENTRO DE UN PERÍODO. No se
 *     suman, y por eso son dos informes y no dos columnas del mismo.
 *  2. Un pago que cubre varias obligaciones se reparte por sus APLICACIONES. No se
 *     repite el importe completo en cada categoría: eso multiplicaría el gasto.
 *  3. Cada moneda lleva sus propios totales. No hay conversión en V1.
 *
 * El ámbito se recorta en la consulta, así que una exportación nunca saca lo que
 * la pantalla oculta.
 */
final class InformeGastos
{
    public function __construct(private ConsultaGastos $consulta) {}

    /**
     * Saldos abiertos a una fecha de corte, por obligación.
     *
     * @return Collection<int, object>
     */
    public function pendientes(User $usuario, string $corte, array $filtros = []): Collection
    {
        $consulta = Gasto::query()
            ->leftJoinSub($this->consulta->saldosPorGasto($corte, corte: $corte), 's', 's.gasto_id', '=', 'gastos.id')
            ->leftJoin('users as r', 'r.id', '=', 'gastos.responsable_id')
            ->whereNotNull('gastos.importe')
            ->where('s.pendiente', '>', 0);

        if (! $usuario->can('gastos.personales')) {
            $consulta->where('gastos.ambito', 'empresarial');
        } elseif (in_array($filtros['ambito'] ?? null, ['empresarial', 'personal'], true)) {
            $consulta->where('gastos.ambito', $filtros['ambito']);
        }

        // El mismo recorte que la pantalla, porque un informe y una exportación filtran
        // exactamente igual o no filtran nada: el CSV es la salida más fácil de llevarse.
        $this->consulta->ocultarProtegidos($consulta, $usuario, 'gastos.id');

        return $consulta
            ->orderByRaw('CASE WHEN s.proxima IS NULL THEN 1 ELSE 0 END, s.proxima')
            ->get([
                'gastos.id', 'gastos.beneficiario', 'gastos.concepto', 'gastos.categoria',
                'gastos.ambito', 'gastos.moneda', 'gastos.persona',
                's.pendiente', 's.vencido', 's.pagado', 's.proxima', 'r.name as responsable',
            ]);
    }

    /**
     * Dinero que salió en el período, fila por APLICACIÓN.
     *
     * Se lista por aplicación y no por pago justamente para que un pago que cubre
     * dos categorías aporte a cada una lo suyo y no el total a las dos. El id del
     * pago viaja en cada fila para poder reagrupar sin perder de vista que fue una
     * sola salida de dinero.
     *
     * REVERSIONES Y FECHA DE CORTE. Un pago revertido DENTRO del período no aparece:
     * al cerrar ese período ya se sabía que el registro estaba mal. Uno revertido
     * DESPUÉS sí aparece, porque durante el período ese dinero constaba como salido y
     * el informe de entonces lo incluía; reescribirlo hacia atrás haría que el mismo
     * mes diera cifras distintas según el día en que se consulte. Para que nadie lo
     * lea como vigente, esas filas vienen marcadas con `revertido_despues`.
     *
     * @return Collection<int, object>
     */
    public function pagos(User $usuario, string $desde, string $hasta, array $filtros = []): Collection
    {
        $consulta = DB::table('gastos_pago_aplicaciones as a')
            ->join('gastos_pagos as p', 'p.id', '=', 'a.pago_id')
            ->join('gastos_cuotas as c', 'c.id', '=', 'a.cuota_id')
            ->join('gastos as g', 'g.id', '=', 'c.gasto_id')
            ->leftJoin('users as pagador', 'pagador.id', '=', 'p.pagado_por')
            ->leftJoin('users as registrador', 'registrador.id', '=', 'p.registrado_por')
            // `whereDate` en los DOS extremos, y no `whereBetween`, por una razón que
            // solo se ve cuando el informe llega HASTA HOY: MySQL guarda `fecha` como
            // DATE, pero SQLite —dinámicamente tipado— guarda lo que Laravel le manda,
            // que es «2026-09-11 00:00:00». Comparado como cadena contra el límite
            // «2026-09-11», ese pago queda FUERA, y el informe pierde el último día sin
            // avisar. `basePagos()` ya lo hacía así; esto los pone de acuerdo.
            ->whereDate('p.fecha', '>=', $desde)
            ->whereDate('p.fecha', '<=', $hasta)
            ->where(fn ($w) => $w->whereNull('p.revertido_at')->orWhere('p.revertido_at', '>', $hasta.' 23:59:59'));

        if (! $usuario->can('gastos.personales')) {
            $consulta->where('g.ambito', 'empresarial');
        } elseif (in_array($filtros['ambito'] ?? null, ['empresarial', 'personal'], true)) {
            $consulta->where('g.ambito', $filtros['ambito']);
        }

        $this->consulta->ocultarProtegidos($consulta, $usuario, 'g.id');

        return $consulta
            ->orderBy('p.fecha')->orderBy('p.id')->orderBy('g.id')
            ->get([
                'p.id as pago_id', 'p.fecha', 'p.metodo', 'p.referencia', 'p.moneda',
                'a.importe as aplicado', 'g.id as gasto_id', 'g.beneficiario', 'g.concepto',
                'g.categoria', 'g.ambito', 'g.naturaleza',
                'pagador.name as pagador', 'registrador.name as registrador',
                'p.revertido_at as revertido_despues', 'p.motivo_reversion',
            ]);
    }

    /**
     * Totales por moneda y ámbito de un conjunto de filas ya recortado por permiso.
     *
     * `$enCentavos` NO se adivina, se declara. Las columnas calculadas en SQL
     * (`pendiente`, `vencido`) ya vienen en centavos enteros, mientras que las que
     * salen de una columna DECIMAL (`aplicado`) vienen como «60.00». Detectarlo con
     * `is_int()` no sirve: el driver devuelve los enteros de SQL como CADENA, así que
     * el importe se multiplicaba por cien y los totales salían disparatados.
     *
     * @return array<string, array<string, int>>
     */
    public function totalesPorAmbito(Collection $filas, string $campo, bool $enCentavos): array
    {
        $totales = [];

        foreach ($filas as $fila) {
            $moneda = $fila->moneda;
            $totales[$moneda] ??= ['empresarial' => 0, 'personal' => 0, 'total' => 0];

            $centavos = $enCentavos
                ? (int) $fila->{$campo}
                : Dinero::centavos((string) $fila->{$campo});

            $totales[$moneda][$fila->ambito] += $centavos;
            $totales[$moneda]['total'] += $centavos;
        }

        return $totales;
    }

    /**
     * CSV con separador de coma y BOM UTF-8, para que Excel en Windows no rompa las
     * tildes.
     *
     * NEUTRALIZA LA INYECCIÓN DE FÓRMULAS: un concepto que empiece por `=`, `+`, `-`
     * o `@` se antepone con una comilla simple. Un proveedor puede llamarse
     * «=SUMA(...)» sin querer, y también queriendo; en ninguno de los dos casos debe
     * ejecutarse al abrir el archivo.
     *
     * @param  array<int, string>  $cabeceras
     * @param  iterable<int, array<int, mixed>>  $filas
     */
    public function csv(string $nombre, array $cabeceras, iterable $filas, array $notas = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($cabeceras, $filas, $notas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");

            foreach ($notas as $nota) {
                fputcsv($salida, [$this->neutralizar($nota)]);
            }
            if ($notas !== []) {
                fputcsv($salida, []);
            }

            fputcsv($salida, $cabeceras);
            foreach ($filas as $fila) {
                fputcsv($salida, array_map(fn ($v) => $this->neutralizar((string) $v), $fila));
            }

            fclose($salida);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function neutralizar(string $valor): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valor) === 1 ? "'".$valor : $valor;
    }
}
