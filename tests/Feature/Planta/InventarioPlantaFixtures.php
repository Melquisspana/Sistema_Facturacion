<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaLote;
use App\Models\Planta\PlantaMovimiento;
use App\Models\Planta\PlantaUbicacion;
use App\Services\Planta\PlantaInventarioService;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Andamiaje compartido por las pruebas del motor de inventario.
 *
 * Dos decisiones que conviene no perder de vista al leer las pruebas:
 *
 *  - {@see aplicar()} envuelve SIEMPRE la llamada en `DB::transaction`, porque
 *    el servicio lo exige. La prueba de que lo exige llama al servicio a pelo,
 *    sin este ayudante.
 *  - {@see contexto()} incrementa `documento_id` en cada llamada. Sin eso, dos
 *    movimientos consecutivos del mismo tipo sobre el mismo bucket compartirían
 *    `efecto_uid` y el segundo sería rechazado como duplicado, que es justo el
 *    comportamiento correcto pero no lo que casi ninguna prueba quiere medir.
 */
trait InventarioPlantaFixtures
{
    private int $secuenciaDocumento = 0;

    protected function servicio(): PlantaInventarioService
    {
        return app(PlantaInventarioService::class);
    }

    /** Insumo que controla lotes y admite fracción: el caso normal de materia prima. */
    protected function insumo(array $atributos = []): PlantaInsumo
    {
        return PlantaInsumo::factory()->create($atributos);
    }

    /** Bolsa: no controla lotes y se cuenta entera. */
    protected function insumoBolsa(array $atributos = []): PlantaInsumo
    {
        return PlantaInsumo::factory()->bolsa()->create($atributos);
    }

    protected function lote(PlantaInsumo $insumo, array $atributos = []): PlantaLote
    {
        return PlantaLote::factory()->create(array_merge(
            ['planta_insumo_id' => $insumo->id],
            $atributos,
        ));
    }

    protected function ubicacion(array $atributos = []): PlantaUbicacion
    {
        return PlantaUbicacion::factory()->create($atributos);
    }

    protected function transito(): PlantaUbicacion
    {
        return PlantaUbicacion::factory()->transito()->create();
    }

    protected function bucket(
        PlantaInsumo $insumo,
        PlantaLote $lote,
        PlantaUbicacion $ubicacion,
        EstadoDisponibilidad $estado = EstadoDisponibilidad::Disponible,
        int $trasladoId = 0,
    ): BucketInventario {
        return new BucketInventario(
            insumoId: $insumo->id,
            loteId: $lote->id,
            ubicacionId: $ubicacion->id,
            estado: $estado,
            trasladoId: $trasladoId,
        );
    }

    /** Insumo + lote + ubicación + bucket listos de una vez. */
    protected function escenarioBasico(): array
    {
        $insumo = $this->insumo();
        $lote = $this->lote($insumo);
        $ubicacion = $this->ubicacion();

        return [
            'insumo' => $insumo,
            'lote' => $lote,
            'ubicacion' => $ubicacion,
            'bucket' => $this->bucket($insumo, $lote, $ubicacion),
        ];
    }

    protected function contexto(
        TipoMovimientoPlanta $tipo = TipoMovimientoPlanta::CargaInicial,
        ?int $documentoId = null,
        ?int $detalleId = null,
        string $transicion = 'confirmar',
        int $secuencia = 0,
        ?string $grupoUuid = null,
        array $metadata = [],
        ?int $revertidoId = null,
    ): ContextoMovimiento {
        return ContextoMovimiento::para(
            tipo: $tipo,
            documentoType: 'Tests\\Documento',
            documentoId: $documentoId ?? ++$this->secuenciaDocumento,
            transicion: $transicion,
            fechaEfectiva: '2026-07-30',
            documentoDetalleId: $detalleId,
            grupoUuid: $grupoUuid ?? (string) Str::uuid(),
            secuencia: $secuencia,
            movimientoRevertidoId: $revertidoId,
            metadata: $metadata,
        );
    }

    /** Aplica un movimiento dentro de su transacción, como exige el servicio. */
    protected function aplicar(
        BucketInventario $bucket,
        string $cantidad,
        ?ContextoMovimiento $contexto = null,
    ): PlantaMovimiento {
        return DB::transaction(fn () => $this->servicio()->aplicarMovimiento(
            $bucket,
            $cantidad,
            $contexto ?? $this->contexto(),
        ));
    }

    /** Saldo proyectado del bucket, leído directo de la tabla. */
    protected function saldoProyectado(BucketInventario $bucket): ?string
    {
        $valor = DB::table('planta_existencias')->where($bucket->aColumnas())->value('cantidad');

        return $valor === null ? null : bcadd((string) $valor, '0', 4);
    }

    /** Suma del mayor para el bucket, calculada sin pasar por el servicio. */
    protected function sumaMayor(BucketInventario $bucket): string
    {
        $total = DB::table('planta_movimientos')
            ->where($bucket->aColumnas())
            ->sum(DB::raw('CAST(ROUND(cantidad * 10000) AS INTEGER)'));

        return bcdiv((string) (int) $total, '10000', 4);
    }

    /**
     * Huella del mayor: filas, suma y mayor id. Es lo que permite afirmar que
     * una operación NO tocó el libro mayor.
     *
     * @return array{filas: int, suma: string, max_id: int}
     */
    protected function huellaMayor(): array
    {
        $fila = DB::table('planta_movimientos')
            ->selectRaw(
                'COUNT(*) as filas, '
                .'COALESCE(SUM(CAST(ROUND(cantidad * 10000) AS INTEGER)), 0) as suma, '
                .'COALESCE(MAX(id), 0) as max_id'
            )
            ->first();

        return [
            'filas' => (int) $fila->filas,
            'suma' => bcdiv((string) (int) $fila->suma, '10000', 4),
            'max_id' => (int) $fila->max_id,
        ];
    }

    /**
     * Escribe una fila de existencias saltándose el servicio, para SIMULAR
     * corrupción. Es la puerta trasera documentada del modelo y aquí se usa a
     * propósito: sin ella no habría forma de probar que la reconciliación
     * detecta lo que el dominio no puede producir.
     */
    protected function corromperExistencia(BucketInventario $bucket, string $cantidad): void
    {
        DB::table('planta_existencias')->updateOrInsert(
            $bucket->aColumnas(),
            ['cantidad' => $cantidad, 'actualizado_en' => now(), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    protected function borrarExistencia(BucketInventario $bucket): void
    {
        DB::table('planta_existencias')->where($bucket->aColumnas())->delete();
    }
}
