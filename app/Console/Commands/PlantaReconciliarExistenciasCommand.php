<?php

namespace App\Console\Commands;

use App\Enums\Planta\TipoDiferenciaReconciliacion;
use App\Exceptions\Planta\ReconciliacionBloqueadaException;
use App\Services\Planta\ReconciliacionExistenciasService;
use App\Support\Planta\ResultadoReconciliacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba que el saldo proyectado de Planta coincida con el libro mayor y,
 * con `--apply`, lo reconstruye desde él.
 *
 * DRY-RUN POR DEFECTO. Sin `--apply` no escribe una sola fila: sirve para medir
 * el daño antes de decidir nada, y para vigilar de forma programada que no lo
 * hay. Por eso termina con código distinto de cero cuando encuentra
 * diferencias: así un cron o un pipeline se entera sin que nadie lea la salida.
 *
 * NUNCA TOCA `planta_movimientos`, ni con `--apply`. El mayor es la fuente de
 * verdad; lo que se corrige es la proyección. El informe incluye la huella del
 * mayor (filas, suma, mayor id) antes y después precisamente para poder
 * demostrarlo, y el servicio aborta la transacción si esa huella cambia.
 *
 * ADVERTENCIA OPERATIVA. Con `--apply`, el comando BLOQUEA TEMPORALMENTE TODAS
 * LAS ESCRITURAS DE INVENTARIO mientras corre: bloquea `planta_existencias`
 * entera para que ningún movimiento se cuele entre la lectura del mayor y la
 * reescritura del saldo. Sobre una base grande, las recepciones y traslados que
 * se estén registrando quedarán EN ESPERA hasta que termine. Es mantenimiento,
 * no operación: conviene lanzarlo fuera de horario.
 *
 * EN PRODUCCIÓN, `--apply` EXIGE ADEMÁS `--force`. La reconstrucción es
 * correcta, pero reescribe saldos que alguien está mirando en pantalla y detiene
 * la operación mientras tanto; en producción eso no puede ser el resultado de un
 * comando escrito de memoria. El dry-run no necesita `--force`: no escribe.
 *
 * CÓDIGOS DE SALIDA:
 *   0 — todo cuadra (o `--apply` corrigió todo lo corregible y no quedó nada).
 *   1 — hay diferencias sin corregir: en dry-run, todas; con `--apply`, las que
 *       tienen su defecto EN EL MAYOR y por tanto no se pueden reparar aquí.
 *   2 — no llegó a ejecutarse: ya hay otra reconciliación en curso, o falta
 *       `--force` en producción. En ambos casos no se escribió nada.
 */
class PlantaReconciliarExistenciasCommand extends Command
{
    protected $signature = 'planta:reconciliar-existencias
        {--apply : Reconstruye planta_existencias desde el mayor. Sin esta opción no se escribe nada}
        {--force : Obligatorio junto a --apply en producción. Sin efecto en el resto de entornos}
        {--detalle=30 : Cuántas diferencias listar (0 = todas)}';

    protected $description = 'Compara el saldo proyectado de Planta con su libro mayor y opcionalmente lo reconstruye';

    public function handle(ReconciliacionExistenciasService $servicio): int
    {
        $aplicar = (bool) $this->option('apply');

        $this->mostrarContexto($aplicar);

        if ($aplicar && ! $this->autorizadoParaEscribir()) {
            return 2;
        }

        $this->components->info($aplicar
            ? 'Reconciliando existencias de Planta (se ESCRIBIRÁ en planta_existencias)…'
            : 'Analizando existencias de Planta (dry-run: no se escribe nada)…');

        try {
            $resultado = $aplicar
                ? $servicio->aplicar(fn (ResultadoReconciliacion $previo) => $this->anunciarCorreccion($previo))
                : $servicio->analizar();
        } catch (ReconciliacionBloqueadaException $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        $this->mostrarResumen($resultado);

        if ($resultado->sinDiferencias()) {
            $this->components->info('Sin diferencias: la proyección coincide con el libro mayor.');

            return self::SUCCESS;
        }

        $this->mostrarDiferencias($resultado);

        return $aplicar
            ? $this->cerrarAplicacion($resultado)
            : $this->cerrarAnalisis($resultado);
    }

    /**
     * Entorno, conexión y base SIEMPRE a la vista, antes de cualquier decisión.
     * Un comando que reescribe saldos no puede dejar en duda contra qué corre.
     */
    private function mostrarContexto(bool $aplicar): void
    {
        $conexion = DB::connection();

        $this->components->twoColumnDetail('Entorno', app()->environment());
        $this->components->twoColumnDetail('Conexión', $conexion->getName());
        $this->components->twoColumnDetail('Base', (string) $conexion->getDatabaseName());
        $this->components->twoColumnDetail('Modo', $aplicar ? '<fg=yellow>--apply (ESCRIBE)</>' : 'dry-run (solo lectura)');

        if ($aplicar) {
            $this->components->warn(
                'Mientras dure la reconstrucción quedarán BLOQUEADAS todas las escrituras de '
                .'inventario de Planta: las recepciones y traslados en curso esperarán a que termine.'
            );
        }
    }

    /** En producción, escribir exige decirlo dos veces. */
    private function autorizadoParaEscribir(): bool
    {
        if (! app()->environment('production') || $this->option('force')) {
            return true;
        }

        $this->components->error(
            'En producción, --apply exige además --force: reconstruir la proyección reescribe saldos '
            .'en uso y detiene la operación mientras corre. Ejecuta primero sin --apply para ver qué '
            .'cambiaría.'
        );

        return false;
    }

    /**
     * Informe previo a la escritura, emitido YA DENTRO del candado. Se hace aquí y
     * no antes a propósito: analizar fuera y aplicar después abriría justo la
     * ventana que el candado existe para cerrar.
     */
    private function anunciarCorreccion(ResultadoReconciliacion $previo): void
    {
        $this->components->twoColumnDetail('Diferencias detectadas', (string) count($previo->diferencias));
        $this->components->twoColumnDetail('Se corregirán', (string) count($previo->corregibles()));
        $this->components->twoColumnDetail('Quedarán sin corregir', (string) count($previo->irreparables()));
    }

    private function mostrarResumen(ResultadoReconciliacion $resultado): void
    {
        $huella = $resultado->huellaMayorAntes;

        $this->components->twoColumnDetail('Buckets en el mayor', (string) $resultado->bucketsMayor);
        $this->components->twoColumnDetail('Buckets proyectados', (string) $resultado->bucketsProyectados);
        $this->components->twoColumnDetail(
            'Libro mayor',
            sprintf('%d filas · suma %s · max(id) %d', $huella['filas'], $huella['suma'], $huella['max_id'])
        );

        if ($resultado->aplicado) {
            $despues = $resultado->huellaMayorDespues;

            $this->components->twoColumnDetail(
                'Libro mayor tras aplicar',
                sprintf('%d filas · suma %s · max(id) %d', $despues['filas'], $despues['suma'], $despues['max_id'])
            );
            $this->components->twoColumnDetail(
                'Mayor intacto',
                $resultado->mayorIntacto() ? '<fg=green>sí</>' : '<fg=red>NO</>'
            );

            $antes = $resultado->huellaProyeccionAntes;
            $despues = $resultado->huellaProyeccionDespues;

            if ($antes !== null && $despues !== null) {
                $this->components->twoColumnDetail(
                    'Proyección antes → después',
                    sprintf(
                        '%d filas / suma %s  →  %d filas / suma %s',
                        $antes['filas'], $antes['suma'], $despues['filas'], $despues['suma']
                    )
                );
            }
        }
    }

    private function mostrarDiferencias(ResultadoReconciliacion $resultado): void
    {
        $this->newLine();

        foreach ($resultado->conteoPorTipo() as $tipo => $cantidad) {
            $this->components->twoColumnDetail(
                TipoDiferenciaReconciliacion::from($tipo)->label(),
                (string) $cantidad
            );
        }

        $limite = (int) $this->option('detalle');
        $listado = $limite > 0
            ? array_slice($resultado->diferencias, 0, $limite)
            : $resultado->diferencias;

        $this->newLine();

        foreach ($listado as $diferencia) {
            $this->line('  '.$diferencia->describir());
        }

        $omitidas = count($resultado->diferencias) - count($listado);

        if ($omitidas > 0) {
            $this->line("  … y {$omitidas} más (usa --detalle=0 para verlas todas).");
        }

        $this->newLine();
    }

    /** Cierre del dry-run: informa y falla, sin haber escrito nada. */
    private function cerrarAnalisis(ResultadoReconciliacion $resultado): int
    {
        $corregibles = count($resultado->corregibles());
        $irreparables = count($resultado->irreparables());

        $this->components->warn(sprintf(
            '%d diferencia(s): %d corregible(s) con --apply, %d con el defecto en el MAYOR.',
            count($resultado->diferencias),
            $corregibles,
            $irreparables,
        ));

        if ($irreparables > 0) {
            $this->components->warn(
                'Las diferencias del mayor NO las arregla este comando: exigen registrar movimientos nuevos.'
            );
        }

        return self::FAILURE;
    }

    /** Cierre tras aplicar: solo sobrevive lo que tiene su defecto en el mayor. */
    private function cerrarAplicacion(ResultadoReconciliacion $resultado): int
    {
        $this->components->info(sprintf(
            'Proyección reconstruida: %d insertada(s), %d actualizada(s), %d eliminada(s).',
            $resultado->correcciones['insertadas'],
            $resultado->correcciones['actualizadas'],
            $resultado->correcciones['eliminadas'],
        ));

        $irreparables = $resultado->irreparables();

        if ($irreparables === []) {
            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            '%d diferencia(s) NO se corrigieron: su defecto está en el libro mayor, que este comando no toca.',
            count($irreparables),
        ));

        return self::FAILURE;
    }
}
