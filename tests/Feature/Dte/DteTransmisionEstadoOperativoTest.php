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
        $this->assertStringContainsString('Modo paralelo seguro', $estado['detalle']);
    }

    public function test_modo_respaldo_sin_confirmacion_queda_bloqueado_ambar(): void
    {
        config()->set('dte.transmision.modo_operacion', 'respaldo');

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('RESPALDO BLOQUEADO', $estado['etiqueta']);
        $this->assertSame('advertencia', $estado['color']);
        $this->assertFalse($estado['transmision_real_posible']);
    }

    public function test_produccion_principal_con_todos_los_candados_abiertos_es_estado_correcto(): void
    {
        // En el sistema oficial, poder transmitir a producción es el estado esperado.
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.ambiente', 'produccion');
        config()->set('dte.transmision.allow_production', true);

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('PRINCIPAL LISTO', $estado['etiqueta']);
        $this->assertSame('ok', $estado['color']);
        $this->assertTrue($estado['transmision_real_posible']);
        $this->assertFalse($estado['apitest_posible']);
        $this->assertStringContainsString('Producción activa', $estado['detalle']);
        $this->assertStringContainsString('sistema principal', $estado['detalle']);
    }

    public function test_principal_en_testing_con_candados_abiertos_es_apitest_ambar_no_rojo(): void
    {
        // Todos los candados abiertos pero en TESTING → apitest, no producción: ámbar,
        // nunca la alerta roja de "transmite REAL a producción".
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.ambiente', 'testing');

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('advertencia', $estado['color']);
        $this->assertFalse($estado['transmision_real_posible']); // NO es alerta roja
        $this->assertTrue($estado['apitest_posible']);
        $this->assertStringContainsString('apitest', $estado['detalle']);
    }

    public function test_paralelo_con_via_de_pruebas_apitest_no_dispara_alerta_de_produccion(): void
    {
        // Escenario reportado: modo paralelo + dry-run sí + confirmación no, pero con la
        // vía dedicada de pruebas encendida (DTE_TRANSMISION_TEST_ENABLED). Antes daba un
        // falso "puede transmitir REALES a Hacienda"; ahora es ámbar apitest, no rojo.
        config()->set('dte.transmision.modo_operacion', 'paralelo');
        config()->set('dte.transmision.dry_run', true);
        config()->set('dte.transmision.real_confirmation', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', true);

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('PARALELO SEGURO', $estado['etiqueta']);
        $this->assertFalse($estado['transmision_real_posible']); // sin alerta roja de producción
        $this->assertTrue($estado['apitest_posible']);
        $this->assertSame('advertencia', $estado['color']);
        $this->assertStringNotContainsString('PRODUCCIÓN', $estado['detalle']);
    }

    public function test_paralelo_con_dry_run_y_sin_via_de_pruebas_es_verde_sin_alertas(): void
    {
        // El estado que el operador espera ver "seguro" en el piloto.
        config()->set('dte.transmision.modo_operacion', 'paralelo');
        config()->set('dte.transmision.enabled', true);       // interruptor encendido…
        config()->set('dte.transmision.dry_run', true);       // …pero dry-run activo
        config()->set('dte.transmision.real_confirmation', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.test_enabled', false); // sin vía de pruebas

        $estado = $this->servicio()->estadoOperativo();

        $this->assertSame('PARALELO SEGURO', $estado['etiqueta']);
        $this->assertSame('ok', $estado['color']);
        $this->assertFalse($estado['transmision_real_posible']);
        $this->assertFalse($estado['apitest_posible']);
        $this->assertStringContainsString('NO transmite', $estado['detalle']);
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
        $this->assertFalse($estado['apitest_posible']);
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
