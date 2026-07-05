<?php

namespace Tests\Feature\Dte;

use App\Services\Dte\DteTransmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `DteTransmisionService::estadoOperativo()`: resumen SOLO LECTURA pensado para
 * pantalla (badge del navbar / panel "Salud del sistema"). Reutiliza evaluarCandados();
 * no agrega candados nuevos ni cambia cuándo se bloquea/permite transmitir.
 */
class DteTransmisionEstadoOperativoTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): DteTransmisionService
    {
        return app(DteTransmisionService::class);
    }

    public function test_modo_paralelo_por_defecto_es_seguro_verde(): void
    {
        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('PARALELO SEGURO', $estado['etiqueta']);
        $this->assertSame('ok', $estado['color']);
        $this->assertFalse($estado['transmision_real_posible']);
        $this->assertStringContainsString('Conta Portable', $estado['detalle']);
    }

    public function test_modo_respaldo_sin_confirmacion_queda_bloqueado_ambar(): void
    {
        config()->set('dte.transmision.modo_operacion', 'respaldo');

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('RESPALDO BLOQUEADO', $estado['etiqueta']);
        $this->assertSame('advertencia', $estado['color']);
        $this->assertFalse($estado['transmision_real_posible']);
    }

    public function test_modo_principal_con_todos_los_candados_abiertos_es_critico_rojo(): void
    {
        // Único combo que hace evaluarCandados() bloqueado=false (transmisión real posible).
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.ambiente', 'testing');

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('PRINCIPAL LISTO', $estado['etiqueta']);
        $this->assertSame('critico', $estado['color']);
        $this->assertTrue($estado['transmision_real_posible']);
        $this->assertStringContainsString('REALES a Hacienda', $estado['detalle']);
    }

    public function test_modo_principal_bloqueado_por_dry_run_no_es_critico(): void
    {
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', true); // candado sigue cerrado

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('PRINCIPAL BLOQUEADO', $estado['etiqueta']);
        $this->assertSame('advertencia', $estado['color']);
        $this->assertFalse($estado['transmision_real_posible']);
    }

    public function test_modo_desconocido_se_reporta_bloqueado(): void
    {
        config()->set('dte.transmision.modo_operacion', 'x-invalido');

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('X-INVALIDO BLOQUEADO', $estado['etiqueta']);
        $this->assertSame('advertencia', $estado['color']);
        $this->assertFalse($estado['transmision_real_posible']);
    }

    // --- Mocks ---

    public function test_sin_mocks_activos_por_defecto(): void
    {
        $estado = $this->servicio()->estadoOperativo();

        $this->assertFalse($estado['mocks']['firma']);
        $this->assertFalse($estado['mocks']['transmision']);
        $this->assertFalse($estado['mocks']['invalidacion']);
        $this->assertFalse($estado['mocks']['alguno']);
    }

    public function test_mock_de_firma_activo_se_refleja_sin_afectar_el_modo(): void
    {
        config()->set('dte.firma.mock', true);

        $estado = $this->servicio()->estadoOperativo();

        $this->assertTrue($estado['mocks']['firma']);
        $this->assertTrue($estado['mocks']['alguno']);
        // El modo sigue paralelo/seguro: el mock de firma no habilita transmisión real.
        $this->assertSame('PARALELO SEGURO', $estado['etiqueta']);
        $this->assertFalse($estado['transmision_real_posible']);
    }

    public function test_mock_de_transmision_activo_se_refleja_sin_afectar_el_modo(): void
    {
        config()->set('dte.transmision.mock', true);

        $estado = $this->servicio()->estadoOperativo();

        $this->assertTrue($estado['mocks']['transmision']);
        $this->assertTrue($estado['mocks']['alguno']);
        $this->assertSame('PARALELO SEGURO', $estado['etiqueta']);
    }

    public function test_mock_de_invalidacion_activo_se_refleja_sin_afectar_el_modo(): void
    {
        config()->set('dte.invalidacion.mock', true);

        $estado = $this->servicio()->estadoOperativo();

        $this->assertTrue($estado['mocks']['invalidacion']);
        $this->assertTrue($estado['mocks']['alguno']);
        $this->assertSame('PARALELO SEGURO', $estado['etiqueta']);
    }

    /** No expone ningún secreto (mismo candados[flags] que evaluarCandados(), sin credenciales). */
    public function test_no_expone_secretos(): void
    {
        config()->set('dte.transmision.usuario_api', 'facturador01');
        config()->set('dte.transmision.password', 'PASSWORD_SECRETO_X');
        config()->set('dte.transmision.token', 'TOKEN_SECRETO_X');

        $estado = $this->servicio()->estadoOperativo();
        $volcado = json_encode($estado);

        $this->assertStringNotContainsString('PASSWORD_SECRETO_X', (string) $volcado);
        $this->assertStringNotContainsString('TOKEN_SECRETO_X', (string) $volcado);
        $this->assertStringNotContainsString('facturador01', (string) $volcado);
    }
}
