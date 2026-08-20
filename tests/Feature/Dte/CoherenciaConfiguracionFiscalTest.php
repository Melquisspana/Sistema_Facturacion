<?php

namespace Tests\Feature\Dte;

use App\Models\Empresa;
use App\Support\Dte\CoherenciaConfiguracionFiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verificador de coherencia de la configuración fiscal. Es SOLO DIAGNÓSTICO: ningún
 * test de aquí transmite, firma, hace HTTP ni escribe configuración.
 *
 * Cubre las tres incoherencias que hasta ahora podían convivir en silencio:
 *  - el ambiente que va DENTRO del JSON contra el ambiente que elige las CREDENCIALES;
 *  - el NIT que nombra el certificado de firma contra el NIT del emisor del documento;
 *  - mocks activos con APP_ENV=production.
 */
class CoherenciaConfiguracionFiscalTest extends TestCase
{
    use RefreshDatabase;

    private function clave(array $checks, string $clave): array
    {
        foreach ($checks as $check) {
            if ($check['clave'] === $clave) {
                return $check;
            }
        }

        $this->fail('No se encontró el check "'.$clave.'".');
    }

    // -----------------------------------------------------------------------
    // Ambientes cruzados
    // -----------------------------------------------------------------------

    public static function ambientesCoherentes(): array
    {
        return [
            'pruebas con credenciales de pruebas' => ['00', 'testing'],
            'producción con credenciales de producción' => ['01', 'produccion'],
            'producción con el rótulo alterno "prod"' => ['01', 'prod'],
        ];
    }

    /** @dataProvider ambientesCoherentes */
    public function test_ambientes_que_concuerdan_pasan(string $ambiente, string $transmision): void
    {
        config(['dte.ambiente' => $ambiente, 'dte.transmision.ambiente' => $transmision]);

        $this->assertTrue(CoherenciaConfiguracionFiscal::checkAmbientes()['ok']);
    }

    public function test_json_de_produccion_con_credenciales_de_pruebas_es_incoherente(): void
    {
        config(['dte.ambiente' => '01', 'dte.transmision.ambiente' => 'testing']);

        $check = CoherenciaConfiguracionFiscal::checkAmbientes();

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('credenciales de apitest', $check['detalle']);
    }

    public function test_json_de_pruebas_con_credenciales_de_produccion_es_incoherente(): void
    {
        config(['dte.ambiente' => '00', 'dte.transmision.ambiente' => 'produccion']);

        $check = CoherenciaConfiguracionFiscal::checkAmbientes();

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('cuenta REAL', $check['detalle']);
    }

    public function test_un_ambiente_fuera_de_cat_001_se_reporta_como_invalido_no_como_pruebas(): void
    {
        config(['dte.ambiente' => '99', 'dte.transmision.ambiente' => 'testing']);

        $check = CoherenciaConfiguracionFiscal::checkAmbientes();

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('no es un valor válido de CAT-001', $check['detalle']);
    }

    // -----------------------------------------------------------------------
    // NIT de firma vs NIT del emisor
    // -----------------------------------------------------------------------

    public function test_sin_nit_en_ningun_lado_no_hay_nada_que_comparar(): void
    {
        config(['dte.firma.nit' => '']);
        Empresa::create(['razon_social' => 'Dulces La Negrita', 'activo' => true]);

        $this->assertTrue(CoherenciaConfiguracionFiscal::checkNitFirma()['ok']);
    }

    public function test_nits_iguales_pasan_aunque_uno_lleve_guiones(): void
    {
        // El emisor guarda el NIT con guiones; el firmador lo espera solo en dígitos.
        // Comparar en crudo daría un falso positivo de incoherencia todos los días.
        config(['dte.firma.nit' => '06140000000000']);
        Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'activo' => true]);

        $this->assertTrue(CoherenciaConfiguracionFiscal::checkNitFirma()['ok']);
    }

    public function test_nits_distintos_fallan_y_lo_dicen(): void
    {
        config(['dte.firma.nit' => '06140000000000']);
        Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-999999-999-9', 'activo' => true]);

        $check = CoherenciaConfiguracionFiscal::checkNitFirma();

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('certificado de otro NIT', $check['detalle']);
    }

    public function test_el_check_no_corrige_ni_toca_ningun_valor(): void
    {
        config(['dte.firma.nit' => '06140000000000']);
        $empresa = Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-999999-999-9', 'activo' => true]);

        CoherenciaConfiguracionFiscal::checks();

        // Ni el NIT del emisor ni el de firma se "arreglan" solos: el diagnóstico avisa,
        // la corrección es una decisión humana.
        $this->assertSame('0614-999999-999-9', $empresa->fresh()->nit);
        $this->assertSame('06140000000000', config('dte.firma.nit'));
    }

    public function test_firma_sin_nit_con_emisor_con_nit_falla(): void
    {
        config(['dte.firma.nit' => '']);
        Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'activo' => true]);

        $check = CoherenciaConfiguracionFiscal::checkNitFirma();

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('DTE_FIRMA_NIT está vacío', $check['detalle']);
    }

    public function test_usa_la_empresa_activa_cuando_hay_varias(): void
    {
        Empresa::create(['razon_social' => 'Vieja', 'nit' => '0614-111111-111-1', 'activo' => false]);
        Empresa::create(['razon_social' => 'Dulces La Negrita', 'nit' => '0614-000000-000-0', 'activo' => true]);
        config(['dte.firma.nit' => '06140000000000']);

        $this->assertTrue(CoherenciaConfiguracionFiscal::checkNitFirma()['ok']);
    }

    // -----------------------------------------------------------------------
    // Mocks en producción
    // -----------------------------------------------------------------------

    public function test_los_mocks_son_aceptables_fuera_de_produccion(): void
    {
        config(['app.env' => 'local', 'dte.firma.mock' => true, 'dte.transmision.mock' => true]);

        $this->assertTrue(CoherenciaConfiguracionFiscal::checkMocksProduccion()['ok']);
    }

    public static function mocks(): array
    {
        return [
            'firmador' => ['dte.firma.mock', 'DTE_FIRMADOR_MOCK'],
            'Hacienda' => ['dte.transmision.mock', 'MH_MOCK'],
            'invalidación' => ['dte.invalidacion.mock', 'DTE_INVALIDACION_MOCK'],
        ];
    }

    /** @dataProvider mocks */
    public function test_cualquier_mock_con_app_env_production_es_critico(string $clave, string $variable): void
    {
        config(['app.env' => 'production', $clave => true]);

        $check = CoherenciaConfiguracionFiscal::checkMocksProduccion();

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString($variable, $check['detalle']);
        $this->assertContains($variable, CoherenciaConfiguracionFiscal::mocksActivos());
    }

    public function test_produccion_sin_mocks_pasa(): void
    {
        config([
            'app.env' => 'production',
            'dte.firma.mock' => false,
            'dte.transmision.mock' => false,
            'dte.invalidacion.mock' => false,
        ]);

        $this->assertTrue(CoherenciaConfiguracionFiscal::checkMocksProduccion()['ok']);
        $this->assertSame([], CoherenciaConfiguracionFiscal::mocksActivos());
    }

    // -----------------------------------------------------------------------
    // Agregado
    // -----------------------------------------------------------------------

    public function test_devuelve_los_tres_checks_siempre(): void
    {
        $checks = CoherenciaConfiguracionFiscal::checks();

        $this->assertCount(3, $checks);
        $this->assertSame('coherencia_ambiente', $this->clave($checks, 'coherencia_ambiente')['clave']);
        $this->assertSame('coherencia_nit', $this->clave($checks, 'coherencia_nit')['clave']);
        $this->assertSame('mocks_produccion', $this->clave($checks, 'mocks_produccion')['clave']);
    }

    public function test_una_configuracion_sana_no_reporta_problemas(): void
    {
        config([
            'app.env' => 'testing',
            'dte.ambiente' => '00',
            'dte.transmision.ambiente' => 'testing',
            'dte.firma.nit' => '',
            'dte.firma.mock' => false,
            'dte.transmision.mock' => false,
            'dte.invalidacion.mock' => false,
        ]);

        $this->assertSame([], CoherenciaConfiguracionFiscal::problemas());
        $this->assertTrue(CoherenciaConfiguracionFiscal::todoCoherente());
    }

    public function test_problemas_solo_lista_los_checks_que_fallan(): void
    {
        config(['dte.ambiente' => '01', 'dte.transmision.ambiente' => 'testing']);

        $problemas = CoherenciaConfiguracionFiscal::problemas();

        $this->assertSame(['coherencia_ambiente'], array_column($problemas, 'clave'));
        $this->assertFalse(CoherenciaConfiguracionFiscal::todoCoherente());
    }
}
