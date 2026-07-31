<?php

namespace Tests\Feature\Planta;

use App\Exceptions\Planta\RecepcionInvalidaException;
use App\Models\Planta\PlantaLote;
use App\Services\Planta\LoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resolución del lote al confirmar una recepción.
 *
 * LA REGLA, para insumos que controlan lotes:
 *
 *   1. por DEFECTO cada recepción crea un lote interno NUEVO `INT-AAAAMMDD-####`;
 *   2. solo se REUTILIZA uno existente si el usuario lo selecciona de forma
 *      explícita, y si es del mismo insumo, activo y no genérico;
 *   3. NUNCA se deduce reutilización porque coincida el texto del proveedor.
 *
 * El punto 3 tiene su prueba propia y es el que evita el error caro: dos
 * entregas del mismo proveedor con el mismo código impreso son dos llegadas
 * distintas, con fechas y vencimientos potencialmente distintos. Fundirlas por
 * el texto haría imposible saber de qué entrega salió un saldo.
 */
class PlantaRecepcionLotesTest extends TestCase
{
    use RecepcionPlantaFixtures;
    use RefreshDatabase;

    // --- Insumos que controlan lotes ---

    public function test_crea_un_lote_interno_nuevo_por_defecto(): void
    {
        $recepcion = $this->borrador();

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $lote = $recepcion->refresh()->detalles->first()->lote;

        $this->assertNotNull($lote);
        $this->assertFalse($lote->es_generico);
        $this->assertStringStartsWith('INT-20260730-', $lote->codigo_interno);
    }

    public function test_el_lote_nuevo_toma_la_fecha_de_la_recepcion(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, [
                'lote_codigo_proveedor' => 'PROV-77',
                'fecha_elaboracion' => '2026-06-01',
                'fecha_vencimiento' => '2027-06-01',
            ]),
        ], ['fecha' => '2026-05-15']), $this->admin());

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $lote = $recepcion->refresh()->detalles->first()->lote;

        // La fecha del lote es la de la ENTRADA física, no la de captura.
        $this->assertSame('2026-05-15', $lote->fecha_recepcion->toDateString());
        $this->assertSame('2026-06-01', $lote->fecha_elaboracion->toDateString());
        $this->assertSame('2027-06-01', $lote->fecha_vencimiento->toDateString());
        $this->assertSame('PROV-77', $lote->codigo_proveedor);
        $this->assertStringStartsWith('INT-20260515-', $lote->codigo_interno);
    }

    public function test_dos_recepciones_con_el_mismo_codigo_de_proveedor_crean_lotes_distintos(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $usuario = $this->admin();

        foreach ([1, 2] as $vez) {
            $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
                $this->linea($insumo, ['lote_codigo_proveedor' => 'MISMO-TEXTO']),
            ]), $usuario);

            $this->servicioRecepcion()->confirmar($recepcion, $usuario);
        }

        $lotes = PlantaLote::where('planta_insumo_id', $insumo->id)->get();

        // Coincidir en el texto del proveedor NO implica ser el mismo lote físico.
        $this->assertCount(2, $lotes);
        $this->assertNotSame($lotes[0]->codigo_interno, $lotes[1]->codigo_interno);
        $this->assertSame(['MISMO-TEXTO', 'MISMO-TEXTO'], $lotes->pluck('codigo_proveedor')->all());
    }

    public function test_la_reutilizacion_explicita_funciona(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $usuario = $this->admin();

        $primera = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($insumo)]),
            $usuario,
        );
        $this->servicioRecepcion()->confirmar($primera, $usuario);

        $loteExistente = $primera->refresh()->detalles->first()->lote;

        // Segunda entrada, esta vez señalando explícitamente el lote anterior.
        $segunda = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['planta_lote_id' => $loteExistente->id]),
        ]), $usuario);
        $this->servicioRecepcion()->confirmar($segunda, $usuario);

        $this->assertSame($loteExistente->id, $segunda->refresh()->detalles->first()->planta_lote_id);
        $this->assertCount(1, PlantaLote::where('planta_insumo_id', $insumo->id)->get());
        // Y el saldo se acumula en el MISMO bucket.
        $this->assertSame('1000.0000', $this->saldo($this->bucketDe($segunda)));
    }

    public function test_no_se_puede_reutilizar_el_lote_de_otro_insumo(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $ajeno = $this->insumoConLotes();
        $usuario = $this->admin();

        $primera = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($ajeno)]),
            $usuario,
        );
        $this->servicioRecepcion()->confirmar($primera, $usuario);

        $loteAjeno = $primera->refresh()->detalles->first()->lote;

        $segunda = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['planta_lote_id' => $loteAjeno->id]),
        ]), $usuario);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($segunda, $usuario);
    }

    public function test_no_se_puede_reutilizar_un_lote_inactivo(): void
    {
        $ubicacion = $this->bodega();
        $insumo = $this->insumoConLotes();
        $usuario = $this->admin();

        $primera = $this->servicioRecepcion()->crearBorrador(
            $this->payload($ubicacion, [$this->linea($insumo)]),
            $usuario,
        );
        $this->servicioRecepcion()->confirmar($primera, $usuario);

        $lote = $primera->refresh()->detalles->first()->lote;
        $lote->activo = false;
        $lote->save();

        $segunda = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($insumo, ['planta_lote_id' => $lote->id]),
        ]), $usuario);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($segunda, $usuario);
    }

    public function test_no_se_puede_elegir_a_mano_el_lote_generico(): void
    {
        $ubicacion = $this->bodega();
        $conLotes = $this->insumoConLotes();
        $usuario = $this->admin();

        // Un genérico fabricado a mano para el insumo trazable: LoteService se
        // negaría a crearlo, así que la única vía es saltarse el dominio.
        $generico = PlantaLote::factory()->create([
            'planta_insumo_id' => $conLotes->id,
            'es_generico' => true,
            'codigo_interno' => 'GEN-'.$conLotes->id,
        ]);

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($conLotes, ['planta_lote_id' => $generico->id]),
        ]), $usuario);

        $this->expectException(RecepcionInvalidaException::class);

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);
    }

    // --- Insumos sin control de lotes ---

    public function test_un_insumo_sin_control_de_lotes_usa_el_generico(): void
    {
        $ubicacion = $this->bodega();
        $bolsa = $this->insumoSinLotes();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($bolsa, ['cantidad_recibida' => '20', 'unidad_recibida' => 'paquete']),
        ]), $this->admin());

        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $lote = $recepcion->refresh()->detalles->first()->lote;

        $this->assertTrue($lote->es_generico);
        $this->assertSame('GEN-'.$bolsa->id, $lote->codigo_interno);
    }

    public function test_el_generico_se_reutiliza_entre_recepciones(): void
    {
        $ubicacion = $this->bodega();
        $bolsa = $this->insumoSinLotes();
        $usuario = $this->admin();

        foreach ([1, 2, 3] as $vez) {
            $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
                $this->linea($bolsa, ['cantidad_recibida' => '10', 'unidad_recibida' => 'paquete']),
            ]), $usuario);

            $this->servicioRecepcion()->confirmar($recepcion, $usuario);
        }

        $this->assertCount(1, PlantaLote::where('planta_insumo_id', $bolsa->id)->get());
        $this->assertSame('3000.0000', $this->saldo($this->bucketDe($recepcion)));
    }

    public function test_el_generico_conserva_su_fecha_inicial(): void
    {
        $ubicacion = $this->bodega();
        $bolsa = $this->insumoSinLotes();
        $usuario = $this->admin();

        $primera = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($bolsa, ['cantidad_recibida' => '10', 'unidad_recibida' => 'paquete']),
        ], ['fecha' => '2026-03-01']), $usuario);
        $this->servicioRecepcion()->confirmar($primera, $usuario);

        $segunda = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($bolsa, ['cantidad_recibida' => '10', 'unidad_recibida' => 'paquete']),
        ], ['fecha' => '2026-12-31']), $usuario);
        $this->servicioRecepcion()->confirmar($segunda, $usuario);

        $generico = PlantaLote::genericos()->where('planta_insumo_id', $bolsa->id)->firstOrFail();

        // Es cuándo entró ese insumo por PRIMERA vez, no la última vez que se movió.
        $this->assertSame('2026-03-01', $generico->fecha_recepcion->toDateString());
    }

    public function test_un_lote_enviado_para_un_insumo_sin_control_se_ignora(): void
    {
        $ubicacion = $this->bodega();
        $bolsa = $this->insumoSinLotes();
        $otro = $this->insumoConLotes();
        $usuario = $this->admin();

        $loteReal = PlantaLote::factory()->create(['planta_insumo_id' => $otro->id]);

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($bolsa, [
                'cantidad_recibida' => '10',
                'unidad_recibida' => 'paquete',
                // Intento de sustituir el genérico por un lote real cualquiera.
                'planta_lote_id' => $loteReal->id,
            ]),
        ]), $usuario);

        $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        $lote = $recepcion->refresh()->detalles->first()->lote;

        $this->assertTrue($lote->es_generico);
        $this->assertSame($bolsa->id, $lote->planta_insumo_id);
    }

    public function test_el_generico_no_aparece_entre_los_lotes_reales(): void
    {
        $ubicacion = $this->bodega();
        $bolsa = $this->insumoSinLotes();
        $conLotes = $this->insumoConLotes();
        $usuario = $this->admin();

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($ubicacion, [
            $this->linea($bolsa, ['cantidad_recibida' => '10', 'unidad_recibida' => 'paquete']),
            $this->linea($conLotes),
        ]), $usuario);
        $this->servicioRecepcion()->confirmar($recepcion, $usuario);

        $reales = PlantaLote::reales()->pluck('planta_insumo_id')->all();

        // `reales()` es lo que deben usar los selectores de la interfaz.
        $this->assertContains($conLotes->id, $reales);
        $this->assertNotContains($bolsa->id, $reales);
    }

    public function test_el_lote_generico_se_crea_una_sola_vez_por_insumo(): void
    {
        $bolsa = $this->insumoSinLotes();

        app(LoteService::class)->resolverGenerico($bolsa, '2026-07-30');

        $recepcion = $this->servicioRecepcion()->crearBorrador($this->payload($this->bodega(), [
            $this->linea($bolsa, ['cantidad_recibida' => '10', 'unidad_recibida' => 'paquete']),
        ]), $this->admin());
        $this->servicioRecepcion()->confirmar($recepcion, $this->admin());

        $this->assertCount(1, PlantaLote::genericos()->where('planta_insumo_id', $bolsa->id)->get());
    }
}
