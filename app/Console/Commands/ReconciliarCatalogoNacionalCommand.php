<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconcilia el catálogo NACIONAL de productos: deja activos los productos del mercado
 * nacional (14 de la lista + MIX, que se vende en otras tiendas) con su precio base SIN
 * IVA, y archiva (activo=false) los que ya no se venden. Idempotente y por defecto en
 * DRY-RUN: no cambia nada hasta pasar --apply.
 *
 * Alcance deliberadamente acotado (seguro para producción):
 *  - Empareja por `codigo_barra` (estable; no se tocan los códigos de barra).
 *  - Solo toca `activo` y `precio_unitario` de productos. NO renombra, NO borra, NO
 *    hard-delete, NO toca precios especiales, unidades, DTE, firma, PDF ni colas.
 *  - Los precios base son SIN IVA (así los usa el CCF; el IVA se calcula aparte).
 *  - Historial intacto: las líneas de DTE ya generadas usan snapshots propios; archivar
 *    un producto no las altera.
 *  - Guarda: NO archiva un producto que tenga precio especial ACTIVO de OTRO cliente
 *    (distinto de Calleja) — "uso en otros clientes".
 *
 * Cambios auditados vía spatie/activitylog (se usa Eloquent, no SQL crudo).
 */
class ReconciliarCatalogoNacionalCommand extends Command
{
    protected $signature = 'productos:reconciliar-nacional {--apply : Aplica los cambios (por defecto es dry-run, no muta nada)}';

    protected $description = 'Deja activos los productos nacionales (precio SIN IVA) y archiva los que ya no se venden';

    /**
     * Productos nacionales que quedan ACTIVOS, con su precio base SIN IVA.
     * Clave = codigo_barra (no se modifica); valor = precio sin IVA.
     */
    private const NACIONALES = [
        '7412201700031' => '1.0500', // CANILLITAS
        '7412201700079' => '1.0000', // COCO RALLADO
        '7412201700109' => '0.9500', // DULCE DE MIEL
        '7412201700055' => '0.9500', // DULCE DE NANCE
        '7412201700062' => '0.9800', // DULCE DE TAMARINDO
        '7412201700185' => '0.9000', // HUEVITOS
        '7412201700154' => '1.0400', // MANI CON AJONJOLI
        '7412201700147' => '1.0400', // MANI DULCE
        '7412201700123' => '1.0400', // MANI HORNEADO
        '7412201700130' => '1.0000', // PEPIAYOTE (en BD: "PEPIAYIOTE / PEPITORIA", nombre se conserva)
        '7412201700017' => '1.0400', // QUEBRADIENTE
        '7412201700222' => '1.0500', // SEMILLA DE MARAÑON DULCE
        '7412201700178' => '1.2700', // SEMILLA DE MARAÑON HORNEADA
        '7412201700024' => '1.0900', // LECHE DE BURRA
    ];

    /** Se mantiene ACTIVO aunque no sea de la lista nacional (se vende en otras tiendas). */
    private const MANTENER_ACTIVOS = [
        '7412201700135', // MIX
    ];

    /** Se ARCHIVAN (activo=false) si no tienen uso en otros clientes: ya no se venden. */
    private const ARCHIVAR = [
        '7412201700192', // DULCES DE ANIS
        '7412201700048', // CONSERVA DE COCO
        '7412201700115', // MAZAPÁN
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Aplicando reconciliación del catálogo nacional…' : 'DRY-RUN (no se cambia nada). Usá --apply para aplicar.');
        $this->newLine();

        // Cliente Calleja (para la guarda de "uso en otros clientes").
        $callejaId = Cliente::where('nombre', 'like', '%Calleja%')->where('activo', true)->orderBy('id')->value('id');

        $acciones = [];
        $activarBarras = array_merge(array_keys(self::NACIONALES), self::MANTENER_ACTIVOS);

        // Se ejecuta SIEMPRE dentro de una transacción para poder mostrar el estado
        // PROYECTADO real (leído dentro de la tx) y luego commit (--apply) o rollback
        // (dry-run). En dry-run no queda rastro (ni cambios ni auditoría).
        DB::beginTransaction();
        try {
            // 1) Nacionales: asegurar activo=true y precio SIN IVA.
            foreach (self::NACIONALES as $barra => $precioSinIva) {
                $p = Producto::where('codigo_barra', $barra)->first();
                if (! $p) {
                    $acciones[] = ['NO ENCONTRADO', $barra, '(sin producto con ese código de barras)'];

                    continue;
                }
                $cambios = [];
                if (! $p->activo) {
                    $cambios[] = 'activar';
                    $p->activo = true;
                }
                if (! $this->mismoPrecio($p->precio_unitario, $precioSinIva)) {
                    $cambios[] = 'precio '.$p->precio_unitario.'→'.$precioSinIva;
                    $p->precio_unitario = $precioSinIva;
                }
                if ($cambios !== []) {
                    $p->save();
                }
                $acciones[] = [$cambios === [] ? 'OK (sin cambios)' : ('ACTIVO: '.implode(', ', $cambios)), $p->nombre, 'precio sin IVA '.$precioSinIva];
            }

            // 2) MIX: se mantiene activo (se vende en otras tiendas). No se toca su precio.
            foreach (self::MANTENER_ACTIVOS as $barra) {
                $p = Producto::where('codigo_barra', $barra)->first();
                if (! $p) {
                    $acciones[] = ['NO ENCONTRADO', $barra, '(mantener activo)'];

                    continue;
                }
                if (! $p->activo) {
                    $p->activo = true;
                    $p->save();
                    $acciones[] = ['ACTIVO: activar', $p->nombre, 'se mantiene (otras tiendas)'];
                } else {
                    $acciones[] = ['OK (sin cambios)', $p->nombre, 'se mantiene activo (otras tiendas)'];
                }
            }

            // 3) Archivar los que ya no se venden, salvo uso en OTROS clientes.
            foreach (self::ARCHIVAR as $barra) {
                $p = Producto::where('codigo_barra', $barra)->first();
                if (! $p) {
                    $acciones[] = ['NO ENCONTRADO', $barra, '(archivar)'];

                    continue;
                }

                $usoOtros = ProductoPrecioCliente::where('producto_id', $p->id)->where('activo', true)
                    ->when($callejaId !== null, fn ($q) => $q->where('cliente_id', '!=', $callejaId))
                    ->exists();

                if ($usoOtros) {
                    $acciones[] = ['OMITIDO (uso en otros clientes)', $p->nombre, 'tiene precio especial activo de otro cliente'];

                    continue;
                }

                if ($p->activo) {
                    $p->activo = false;
                    $p->save();
                    $acciones[] = ['ARCHIVAR: activo=false', $p->nombre, 'ya no se vende'];
                } else {
                    $acciones[] = ['OK (ya inactivo)', $p->nombre, 'ya estaba archivado'];
                }
            }

            // Estado PROYECTADO (leído dentro de la transacción, antes de decidir commit/rollback).
            $this->table(['Acción', 'Producto / código', 'Detalle'], $acciones);

            $this->newLine();
            $this->info('=== Productos que quedan ACTIVOS ('.Producto::whereIn('codigo_barra', $activarBarras)->where('activo', true)->count().') ===');
            foreach (Producto::whereIn('codigo_barra', $activarBarras)->orderBy('nombre')->get() as $p) {
                $this->line(sprintf('  • %-32s %s  (%s)', $p->nombre, number_format((float) $p->precio_unitario, 4), $p->activo ? 'activo' : 'INACTIVO(!)'));
            }

            $this->newLine();
            $this->warn('=== Productos que quedan INACTIVOS/archivados ===');
            foreach (Producto::whereIn('codigo_barra', self::ARCHIVAR)->orderBy('nombre')->get() as $p) {
                $this->line(sprintf('  • %-32s (%s)', $p->nombre, $p->activo ? 'ACTIVO(!)' : 'inactivo'));
            }

            if ($apply) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->newLine();
        $this->line('Precios especiales: NO se tocaron (Calleja u otros). Recomendación aparte: desactivar los de MIX y de los archivados si Calleja ya no los compra.');
        $this->line($apply ? 'Cambios APLICADOS (auditados en activity log).' : 'DRY-RUN: no se aplicó nada. Repetí con --apply para aplicar.');

        return self::SUCCESS;
    }

    /** Compara dos precios a 4 decimales (evita falsos cambios por formato). */
    private function mismoPrecio(string|float|null $a, string|float $b): bool
    {
        return number_format((float) $a, 4, '.', '') === number_format((float) $b, 4, '.', '');
    }
}
