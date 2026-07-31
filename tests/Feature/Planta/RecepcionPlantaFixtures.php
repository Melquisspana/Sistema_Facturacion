<?php

namespace Tests\Feature\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoUbicacion;
use App\Enums\Planta\UnidadBase;
use App\Models\Planta\PlantaInsumo;
use App\Models\Planta\PlantaProveedor;
use App\Models\Planta\PlantaRecepcion;
use App\Models\Planta\PlantaUbicacion;
use App\Models\User;
use App\Services\Planta\PlantaRecepcionService;
use App\Support\Planta\BucketInventario;
use Illuminate\Support\Facades\DB;

/**
 * Andamiaje compartido por las pruebas de recepciones.
 *
 * Los escenarios se construyen con datos MÍNIMOS y explícitos: una ubicación
 * física operable, un insumo por libra que controla lotes y otro por unidad que
 * no. Es exactamente la combinación que distingue los dos caminos de resolución
 * de lote, y tenerla a mano evita repetirla en cada prueba.
 */
trait RecepcionPlantaFixtures
{
    protected function servicioRecepcion(): PlantaRecepcionService
    {
        return app(PlantaRecepcionService::class);
    }

    protected function usuarioConRol(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    protected function admin(): User
    {
        return $this->usuarioConRol('administrador');
    }

    protected function encenderModulo(): void
    {
        config()->set('planta.enabled', true);
    }

    /** Ubicación física que sí puede recibir mercancía. */
    protected function bodega(array $atributos = []): PlantaUbicacion
    {
        return PlantaUbicacion::factory()->create(array_merge([
            'tipo' => TipoUbicacion::Fisica->value,
            'activo' => true,
            'permite_operacion_manual' => true,
        ], $atributos));
    }

    protected function transito(): PlantaUbicacion
    {
        return PlantaUbicacion::factory()->transito()->create();
    }

    protected function proveedor(array $atributos = []): PlantaProveedor
    {
        return PlantaProveedor::factory()->create($atributos);
    }

    /** Materia prima por libra, con control de lotes: el caso trazable. */
    protected function insumoConLotes(array $atributos = []): PlantaInsumo
    {
        return PlantaInsumo::factory()->create(array_merge([
            'unidad_base' => UnidadBase::Libra->value,
            'controla_lotes' => true,
            'permite_fraccion' => true,
            'activo' => true,
        ], $atributos));
    }

    /** Bolsa por unidad, sin control de lotes: el caso del lote genérico. */
    protected function insumoSinLotes(array $atributos = []): PlantaInsumo
    {
        return PlantaInsumo::factory()->bolsa()->create(array_merge(['activo' => true], $atributos));
    }

    /**
     * Payload de una línea, con la conversión 5 × 100 × 1 = 500 por defecto.
     *
     * @return array<string, mixed>
     */
    protected function linea(PlantaInsumo $insumo, array $atributos = []): array
    {
        return array_merge([
            'planta_insumo_id' => $insumo->id,
            'cantidad_recibida' => '5',
            'unidad_recibida' => 'saco',
            'contenido_por_unidad' => '100',
            'factor_conversion' => '1',
            'estado_destino' => EstadoDisponibilidad::Disponible->value,
        ], $atributos);
    }

    /**
     * Payload de cabecera + líneas.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array<string, mixed>
     */
    protected function payload(PlantaUbicacion $ubicacion, array $lineas, array $cabecera = []): array
    {
        return array_merge([
            'fecha' => '2026-07-30',
            'planta_ubicacion_id' => $ubicacion->id,
            'planta_proveedor_id' => null,
            'documento_referencia' => 'FAC-001',
            'responsable_nombre' => 'Quien recibió',
            'observaciones' => null,
            'detalles' => $lineas,
        ], $cabecera);
    }

    /** Borrador ya creado con una línea estándar. */
    protected function borrador(?User $usuario = null, array $cabecera = [], ?array $lineas = null): PlantaRecepcion
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        return $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, $lineas ?? [$this->linea($insumo)], $cabecera),
            $usuario ?? $this->admin(),
        );
    }

    /** Saldo proyectado de un bucket, leído directo de la tabla. */
    protected function saldo(BucketInventario $bucket): ?string
    {
        $valor = DB::table('planta_existencias')->where($bucket->aColumnas())->value('cantidad');

        return $valor === null ? null : bcadd((string) $valor, '0', 4);
    }

    /** Bucket de una línea ya confirmada. */
    protected function bucketDe(PlantaRecepcion $recepcion, int $indice = 0): BucketInventario
    {
        $detalle = $recepcion->detalles()->orderBy('id')->get()->get($indice);

        return new BucketInventario(
            insumoId: (int) $detalle->planta_insumo_id,
            loteId: (int) $detalle->planta_lote_id,
            ubicacionId: (int) $recepcion->planta_ubicacion_id,
            estado: $detalle->estado_destino,
            trasladoId: 0,
        );
    }

    /**
     * Huella del mayor: filas, suma y mayor id. Demuestra que una operación NO
     * tocó el libro mayor.
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
}
