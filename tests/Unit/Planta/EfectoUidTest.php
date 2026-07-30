<?php

namespace Tests\Unit\Planta;

use App\Enums\Planta\EstadoDisponibilidad;
use App\Enums\Planta\TipoMovimientoPlanta;
use App\Support\Planta\BucketInventario;
use App\Support\Planta\ContextoMovimiento;
use App\Support\Planta\EfectoUid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Diseño del `efecto_uid`: qué lo cambia y qué no.
 *
 * Cada pieza que entra en el hash tiene aquí su prueba de que efectivamente lo
 * cambia, y cada pieza deliberadamente EXCLUIDA tiene la suya de que no. Las
 * segundas son las que importan: una exclusión sin prueba se convierte en una
 * inclusión accidental en cuanto alguien reordena el método, y con ella se abre
 * un hueco por el que se cuelan efectos duplicados.
 *
 * Prueba pura: no necesita base de datos ni aplicación.
 */
class EfectoUidTest extends TestCase
{
    private function bucket(
        int $insumo = 1,
        int $lote = 2,
        int $ubicacion = 3,
        EstadoDisponibilidad $estado = EstadoDisponibilidad::Disponible,
        int $traslado = 0,
    ): BucketInventario {
        return new BucketInventario($insumo, $lote, $ubicacion, $estado, $traslado);
    }

    private function contexto(array $cambios = []): ContextoMovimiento
    {
        return new ContextoMovimiento(
            tipo: $cambios['tipo'] ?? TipoMovimientoPlanta::Recepcion,
            documentoType: $cambios['documentoType'] ?? 'App\\Models\\Planta\\PlantaRecepcion',
            documentoId: $cambios['documentoId'] ?? 10,
            documentoDetalleId: array_key_exists('documentoDetalleId', $cambios) ? $cambios['documentoDetalleId'] : 5,
            transicion: $cambios['transicion'] ?? 'confirmar',
            fechaEfectiva: $cambios['fechaEfectiva'] ?? '2026-07-30',
            grupoUuid: $cambios['grupoUuid'] ?? 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            secuencia: $cambios['secuencia'] ?? 0,
            userId: $cambios['userId'] ?? null,
            responsableNombre: $cambios['responsableNombre'] ?? null,
            movimientoRevertidoId: $cambios['movimientoRevertidoId'] ?? null,
            metadata: $cambios['metadata'] ?? [],
        );
    }

    // --- Formato ---

    public function test_es_un_sha256_hexadecimal_de_64_caracteres(): void
    {
        $uid = EfectoUid::calcular($this->bucket(), $this->contexto());

        $this->assertSame(64, strlen($uid));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $uid);
    }

    public function test_es_determinista(): void
    {
        $this->assertSame(
            EfectoUid::calcular($this->bucket(), $this->contexto()),
            EfectoUid::calcular($this->bucket(), $this->contexto()),
        );
    }

    public function test_no_depende_de_la_barra_inicial_del_documento(): void
    {
        // `Foo::class` y `'\Foo'` son la misma clase; el uid no puede depender de
        // cómo la haya escrito el llamador.
        $this->assertSame(
            EfectoUid::calcular($this->bucket(), $this->contexto(['documentoType' => 'App\\Modelo'])),
            EfectoUid::calcular($this->bucket(), $this->contexto(['documentoType' => '\\App\\Modelo'])),
        );
    }

    // --- Lo que SÍ cambia el uid ---

    public static function piezasQueCambianElUid(): array
    {
        return [
            'documento distinto' => [['documentoId' => 11]],
            'clase de documento distinta' => [['documentoType' => 'App\\Otro']],
            'detalle distinto' => [['documentoDetalleId' => 6]],
            'sin detalle' => [['documentoDetalleId' => null]],
            'transición distinta' => [['transicion' => 'reversar']],
            'tipo distinto' => [['tipo' => TipoMovimientoPlanta::Ajuste]],
            'secuencia distinta' => [['secuencia' => 1]],
        ];
    }

    #[DataProvider('piezasQueCambianElUid')]
    public function test_cada_pieza_del_efecto_cambia_el_uid(array $cambios): void
    {
        $this->assertNotSame(
            EfectoUid::calcular($this->bucket(), $this->contexto()),
            EfectoUid::calcular($this->bucket(), $this->contexto($cambios)),
        );
    }

    public static function dimensionesDelBucket(): array
    {
        return [
            'insumo' => [['insumo' => 99]],
            'lote' => [['lote' => 99]],
            'ubicación' => [['ubicacion' => 99]],
            'estado' => [['estado' => EstadoDisponibilidad::Retenido]],
            'traslado' => [['traslado' => 7]],
        ];
    }

    #[DataProvider('dimensionesDelBucket')]
    public function test_las_cinco_dimensiones_del_bucket_cambian_el_uid(array $cambios): void
    {
        $otro = $this->bucket(
            insumo: $cambios['insumo'] ?? 1,
            lote: $cambios['lote'] ?? 2,
            ubicacion: $cambios['ubicacion'] ?? 3,
            estado: $cambios['estado'] ?? EstadoDisponibilidad::Disponible,
            traslado: $cambios['traslado'] ?? 0,
        );

        $this->assertNotSame(
            EfectoUid::calcular($this->bucket(), $this->contexto()),
            EfectoUid::calcular($otro, $this->contexto()),
        );
    }

    public function test_el_detalle_nulo_no_se_confunde_con_el_detalle_cero(): void
    {
        // Se codifican distinto a propósito: '-' para el nulo, '0' para el cero.
        $this->assertNotSame(
            EfectoUid::calcular($this->bucket(), $this->contexto(['documentoDetalleId' => null])),
            EfectoUid::calcular($this->bucket(), $this->contexto(['documentoDetalleId' => 0])),
        );
    }

    // --- Lo que NO cambia el uid ---

    public static function piezasIrrelevantes(): array
    {
        return [
            'grupo_uuid' => [['grupoUuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff']],
            'usuario' => [['userId' => 42]],
            'responsable' => [['responsableNombre' => 'Otra persona']],
            'fecha efectiva' => [['fechaEfectiva' => '2020-01-01']],
            'metadata' => [['metadata' => ['nota' => 'lo que sea']]],
            'movimiento revertido' => [['movimientoRevertidoId' => 77]],
        ];
    }

    #[DataProvider('piezasIrrelevantes')]
    public function test_lo_excluido_del_hash_no_altera_el_uid(array $cambios): void
    {
        // Si alguna de estas entrara en el hash, el mismo efecto reintentado con
        // otro usuario, otra fecha u otro grupo se colaría como un efecto nuevo.
        $this->assertSame(
            EfectoUid::calcular($this->bucket(), $this->contexto()),
            EfectoUid::calcular($this->bucket(), $this->contexto($cambios)),
        );
    }

    public function test_la_cantidad_no_forma_parte_del_efecto(): void
    {
        // La cantidad no está en ContextoMovimiento justamente por esto: el mismo
        // efecto con otro importe debe ser RECHAZADO como duplicado, no aceptado
        // como una segunda fila.
        $uid = EfectoUid::calcular($this->bucket(), $this->contexto());

        $this->assertSame($uid, EfectoUid::calcular($this->bucket(), $this->contexto()));
    }

    public function test_la_secuencia_es_lo_que_permite_dos_efectos_sobre_el_mismo_bucket(): void
    {
        $base = $this->contexto();

        $this->assertNotSame(
            EfectoUid::calcular($this->bucket(), $base->conSecuencia(0)),
            EfectoUid::calcular($this->bucket(), $base->conSecuencia(1)),
        );
    }

    public function test_con_secuencia_conserva_todo_lo_demas(): void
    {
        $base = $this->contexto(['userId' => 3, 'metadata' => ['a' => 1]]);
        $otro = $base->conSecuencia(2);

        $this->assertSame($base->grupoUuid, $otro->grupoUuid);
        $this->assertSame($base->documentoId, $otro->documentoId);
        $this->assertSame($base->userId, $otro->userId);
        $this->assertSame($base->metadata, $otro->metadata);
        $this->assertSame(2, $otro->secuencia);
    }
}
