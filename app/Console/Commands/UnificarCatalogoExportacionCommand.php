<?php

namespace App\Console\Commands;

use App\Support\Exportaciones\PlanUnificacionCatalogo;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Agrupa el catálogo de exportación existente en productos base con sus
 * presentaciones, según la tabla aprobada en {@see PlanUnificacionCatalogo}.
 *
 * Sin `--aplicar` solo SIMULA: verifica y muestra lo que haría. Con `--aplicar`,
 * dentro de una transacción:
 *
 *   1. Verifica que cada ID del plan exista y coincida en nombre, unidades y
 *      gramos, y que NINGUNA presentación activa quede fuera del plan. Si algo no
 *      cuadra, aborta sin escribir.
 *   2. Guarda un respaldo JSON de las filas que va a tocar.
 *   3. Crea los productos base y les cuelga sus presentaciones, con nombres
 *      limpios, empaque normalizado y onzas/libras recalculadas.
 *   4. Funde duplicados: re-apunta precios de cliente e items de listas a la
 *      presentación que sobrevive y borra la duplicada, que queda vacía. Los items
 *      conservan su copia (snapshot): las listas pasadas no cambian. Si un cliente
 *      tiene precio en ambas y los precios DIFIEREN, aborta.
 *   5. Archiva los registros basura, crea las presentaciones que faltaban con su
 *      precio vigente y anota, en cada precio de cliente, qué lista lo fijó.
 *
 * Se ejecuta una sola vez: si ya existen productos base, se niega.
 */
class UnificarCatalogoExportacionCommand extends Command
{
    protected $signature = 'exportacion:unificar-catalogo {--aplicar : Escribe los cambios (sin esto solo simula)}';

    protected $description = 'Agrupa el catálogo de exportación en productos base con presentaciones y funde duplicados (simula por defecto).';

    /** @var list<string> */
    private array $errores = [];

    public function handle(): int
    {
        if (DB::table('exportacion_productos_base')->exists()) {
            $this->error('Ya hay productos base: la unificación ya se aplicó. No se hizo nada.');

            return self::FAILURE;
        }

        $productos = DB::table('exportacion_productos')->get()->keyBy('id');
        $this->verificar($productos);

        if ($this->errores !== []) {
            $this->error('La base no coincide con el plan aprobado. No se hizo nada:');
            foreach ($this->errores as $error) {
                $this->line("  · {$error}");
            }

            return self::FAILURE;
        }

        $this->mostrarPlan($productos);

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->info('Simulación: no se escribió nada. Para aplicar: php artisan exportacion:unificar-catalogo --aplicar');

            return self::SUCCESS;
        }

        $respaldo = $this->respaldar();

        try {
            DB::transaction(fn () => $this->aplicar());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage().' Se revirtió todo; la base quedó como estaba.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Catálogo unificado. Respaldo previo en storage/app/'.$respaldo);

        return self::SUCCESS;
    }

    // ------------------------------------------------------------- verificación

    private function verificar($productos): void
    {
        $vistos = [];

        $revisar = function (array $esperado) use ($productos, &$vistos): void {
            [$id, $clave, $unidades, $gramos] = $esperado;
            $vistos[] = $id;
            $p = $productos->get($id);

            if ($p === null) {
                $this->errores[] = "#{$id} no existe.";

                return;
            }
            if (! str_contains($this->normalizar($p->nombre_es), $clave)) {
                $this->errores[] = "#{$id} «{$p->nombre_es}» no contiene «{$clave}».";
            }
            if ((int) $p->unidades_por_caja !== $unidades || abs((float) $p->gramos_por_unidad - $gramos) > 0.005) {
                $this->errores[] = "#{$id} «{$p->nombre_es}» es {$p->unidades_por_caja} u × {$p->gramos_por_unidad} g; el plan espera {$unidades} u × {$gramos} g.";
            }
        };

        foreach (PlanUnificacionCatalogo::productos() as $base) {
            foreach ($base['presentaciones'] as $pres) {
                $revisar($pres['conservar']);
                foreach ($pres['fusionar'] ?? [] as $dup) {
                    $revisar($dup);
                }
            }
        }
        foreach (PlanUnificacionCatalogo::archivar() as $basura) {
            $revisar($basura);
        }

        if (count($vistos) !== count(array_unique($vistos))) {
            $this->errores[] = 'El plan menciona algún ID más de una vez.';
        }

        foreach ($productos as $p) {
            if (! in_array($p->id, $vistos, true) && $p->activo) {
                $this->errores[] = "#{$p->id} «{$p->nombre_es}» está activo y no figura en el plan.";
            }
        }

        foreach (PlanUnificacionCatalogo::nuevas() as $nueva) {
            if ($this->clienteId($nueva['cliente']) === null) {
                $this->errores[] = "No se encontró un único cliente de exportación «{$nueva['cliente']}».";
            }
        }
    }

    private function mostrarPlan($productos): void
    {
        $filas = [];
        foreach (PlanUnificacionCatalogo::productos() as $base) {
            foreach ($base['presentaciones'] as $pres) {
                $id = $pres['conservar'][0];
                $fusion = collect($pres['fusionar'] ?? [])->map(fn ($d) => '#'.$d[0])->implode(', ');
                $filas[] = [
                    $base['nombre_es'],
                    "#{$id} {$productos[$id]->unidades_por_caja} u × ".(float) ($pres['gramos'] ?? $productos[$id]->gramos_por_unidad).' g',
                    $fusion === '' ? '—' : $fusion,
                ];
            }
        }

        $this->table(['Producto', 'Presentación', 'Se le funden'], $filas);
        $this->line('Se archivan: '.collect(PlanUnificacionCatalogo::archivar())->map(fn ($a) => '#'.$a[0])->implode(', '));
        $this->line('Se crean: '.collect(PlanUnificacionCatalogo::nuevas())
            ->map(fn ($n) => "{$n['base'][0]} ({$n['unidades']} u) a \${$n['precio']}")->implode(', '));
    }

    // ---------------------------------------------------------------- aplicación

    private function respaldar(): string
    {
        $ruta = 'respaldos/unificacion-catalogo-exportacion-'.now()->format('Ymd-His').'.json';

        Storage::disk('local')->put($ruta, json_encode([
            'creado' => now()->toIso8601String(),
            'exportacion_productos' => DB::table('exportacion_productos')->get(),
            'exportacion_cliente_productos' => DB::table('exportacion_cliente_productos')->get(),
            'exportacion_items' => DB::table('exportacion_items')->get(['id', 'exportacion_id', 'exportacion_producto_id']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $ruta;
    }

    private function aplicar(): void
    {
        $ahora = now();
        $bases = [];

        foreach (PlanUnificacionCatalogo::productos() as $base) {
            $baseId = $this->crearBase($base['nombre_es'], $base['nombre_en'], $base['categoria']);
            $bases[$base['nombre_es']] = $baseId;

            foreach ($base['presentaciones'] as $pres) {
                $conservar = $pres['conservar'][0];

                foreach ($pres['fusionar'] ?? [] as $dup) {
                    $this->fundir($dup[0], $conservar);
                }

                $p = DB::table('exportacion_productos')->where('id', $conservar)->first();
                $gramos = (float) ($pres['gramos'] ?? $p->gramos_por_unidad);

                DB::table('exportacion_productos')->where('id', $conservar)->update([
                    'exportacion_producto_base_id' => $baseId,
                    'nombre_es' => $base['nombre_es'],
                    'nombre_en' => $base['nombre_en'],
                    'unidad' => $this->unidadNormalizada((string) $p->unidad, (int) $p->unidades_por_caja),
                    'gramos_por_unidad' => $gramos,
                    'onzas_por_unidad' => round($gramos * 0.035274, 2),
                    'peso_neto_caja_lb' => round((float) $p->peso_neto_caja_kg * 2.20462, 2),
                    'peso_bruto_caja_lb' => round((float) $p->peso_bruto_caja_kg * 2.20462, 2),
                    'activo' => true,
                    'updated_at' => $ahora,
                ]);
                $this->line("  ✓ {$base['nombre_es']} ← #{$conservar}");
            }
        }

        foreach (PlanUnificacionCatalogo::archivar() as [$id]) {
            DB::table('exportacion_productos')->where('id', $id)->update(['activo' => false, 'updated_at' => $ahora]);
        }

        foreach (PlanUnificacionCatalogo::nuevas() as $nueva) {
            [$es, $en, $categoria] = $nueva['base'];
            $baseId = $bases[$es] ?? $this->crearBase($es, $en, $categoria);
            $neto = (float) $nueva['neto'];
            $bruto = $neto + 1;

            $productoId = DB::table('exportacion_productos')->insertGetId([
                'exportacion_producto_base_id' => $baseId,
                'nombre_es' => $es,
                'nombre_en' => $en,
                'unidad' => $nueva['unidad'],
                'unidades_por_caja' => $nueva['unidades'],
                'gramos_por_unidad' => $nueva['gramos'],
                'onzas_por_unidad' => round($nueva['gramos'] * 0.035274, 2),
                'precio_caja' => $nueva['precio'],
                'peso_neto_caja_kg' => $neto,
                'peso_bruto_caja_kg' => $bruto,
                'peso_neto_caja_lb' => round($neto * 2.20462, 2),
                'peso_bruto_caja_lb' => round($bruto * 2.20462, 2),
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            DB::table('exportacion_cliente_productos')->insert([
                'exportacion_cliente_id' => $this->clienteId($nueva['cliente']),
                'exportacion_producto_id' => $productoId,
                'precio_caja' => $nueva['precio'],
                'precio_fijado_en' => $nueva['fecha'],
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            $this->line("  + {$es} ({$nueva['unidades']} u)");
        }

        $this->anotarOrigenDePrecios();
    }

    private function crearBase(string $es, string $en, string $categoria): int
    {
        return DB::table('exportacion_productos_base')->insertGetId([
            'nombre_es' => $es,
            'nombre_en' => $en,
            'categoria' => $categoria,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Pasa todo lo de $duplicado a $conservar y borra $duplicado, que queda vacío. */
    private function fundir(int $duplicado, int $conservar): void
    {
        $asignaciones = DB::table('exportacion_cliente_productos')->where('exportacion_producto_id', $duplicado)->get();

        foreach ($asignaciones as $a) {
            $existente = DB::table('exportacion_cliente_productos')
                ->where('exportacion_cliente_id', $a->exportacion_cliente_id)
                ->where('exportacion_producto_id', $conservar)
                ->first();

            if ($existente === null) {
                DB::table('exportacion_cliente_productos')->where('id', $a->id)
                    ->update(['exportacion_producto_id' => $conservar, 'updated_at' => now()]);

                continue;
            }

            if (round((float) $existente->precio_caja, 2) !== round((float) $a->precio_caja, 2)) {
                throw new RuntimeException(
                    "El cliente {$a->exportacion_cliente_id} tiene precios distintos en #{$conservar} (\${$existente->precio_caja}) y #{$duplicado} (\${$a->precio_caja})."
                );
            }

            if ($a->activo && ! $existente->activo) {
                DB::table('exportacion_cliente_productos')->where('id', $existente->id)->update(['activo' => true]);
            }
            DB::table('exportacion_cliente_productos')->where('id', $a->id)->delete();
        }

        DB::table('exportacion_items')->where('exportacion_producto_id', $duplicado)
            ->update(['exportacion_producto_id' => $conservar]);
        DB::table('exportacion_productos')->where('id', $duplicado)->delete();
    }

    /**
     * Cada precio de cliente queda ligado a la lista más reciente de ese cliente
     * que lleva esa presentación a ese mismo precio. Sin coincidencia, se deja vacío.
     */
    private function anotarOrigenDePrecios(): void
    {
        $asignaciones = DB::table('exportacion_cliente_productos')->whereNull('precio_fijado_en')->get();

        foreach ($asignaciones as $a) {
            $lista = DB::table('exportacion_items as i')
                ->join('exportaciones as e', 'e.id', '=', 'i.exportacion_id')
                ->where('e.exportacion_cliente_id', $a->exportacion_cliente_id)
                ->where('i.exportacion_producto_id', $a->exportacion_producto_id)
                ->where('i.precio_caja', $a->precio_caja)
                ->orderByDesc('e.fecha')->orderByDesc('e.id')
                ->first(['e.id', 'e.fecha']);

            if ($lista !== null) {
                DB::table('exportacion_cliente_productos')->where('id', $a->id)->update([
                    'precio_fijado_en' => Carbon::parse($lista->fecha)->toDateString(),
                    'precio_desde_exportacion_id' => $lista->id,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------- apoyo

    private function unidadNormalizada(string $unidad, int $unidades): string
    {
        $limpia = trim(preg_replace('/\s+/', ' ', $unidad));
        $esBolsa = preg_match('/polipro|^caja \d+ ?x ?\d+$|^\d+ ?x ?\d+$/i', $limpia) === 1;

        if ($esBolsa && isset(PlanUnificacionCatalogo::UNIDAD_ESTANDAR[$unidades])) {
            return PlanUnificacionCatalogo::UNIDAD_ESTANDAR[$unidades];
        }

        return $limpia;
    }

    private function clienteId(string $nombre): ?int
    {
        $ids = DB::table('exportacion_clientes')->where('nombre', 'like', '%'.$nombre.'%')->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii($texto));
    }
}
