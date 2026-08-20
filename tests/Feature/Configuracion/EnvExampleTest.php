<?php

namespace Tests\Feature\Configuracion;

use Tests\TestCase;

/**
 * `.env.example` es la única documentación que ve quien monta el sistema en un servidor
 * nuevo. Tenía tres problemas concretos: una clave definida DOS veces (ganaba la
 * segunda, en silencio), flags críticos que el código consume y el archivo no mencionaba,
 * y claves documentadas que ningún consumidor lee.
 *
 * Estos tests no revisan estilo: revisan que el archivo no vuelva a mentir.
 */
class EnvExampleTest extends TestCase
{
    private function contenido(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    /** @return array<int, string> Nombres de clave, en orden de aparición. */
    private function claves(): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $this->contenido(), $m);

        return $m[1];
    }

    public function test_ninguna_clave_esta_definida_dos_veces(): void
    {
        $claves = $this->claves();
        $duplicadas = array_keys(array_filter(array_count_values($claves), fn (int $n) => $n > 1));

        $this->assertSame([], $duplicadas,
            'Claves duplicadas en .env.example: '.implode(', ', $duplicadas)
            .'. Con duplicados gana la última y el lector no tiene forma de saberlo.');
    }

    public static function flagsCriticos(): array
    {
        return [
            // Candado dedicado de producción de la invalidación: mientras sea false, un
            // DTE de producción NUNCA se puede invalidar. No estaba documentado.
            ['DTE_INVALIDACION_PRODUCCION_ENABLED'],
            ['DTE_TRANSMISION_ALLOW_PRODUCTION'],
            ['DTE_TRANSMISION_DRY_RUN'],
            ['DTE_TRANSMISION_REAL_CONFIRMATION'],
            ['DTE_MODO_OPERACION'],
            ['DTE_SISTEMA_ACTUAL_ACTIVO'],
        ];
    }

    /** @dataProvider flagsCriticos */
    public function test_los_flags_criticos_estan_documentados(string $clave): void
    {
        $this->assertContains($clave, $this->claves(),
            $clave.' es un candado fiscal que el código consume pero .env.example no documenta.');
    }

    public function test_la_base_de_datos_esta_documentada(): void
    {
        // El proyecto corre sobre MySQL: sin estas claves un despliegue nuevo no arranca.
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $clave) {
            $this->assertContains($clave, $this->claves(), $clave.' falta en .env.example.');
        }
    }

    public function test_no_documenta_claves_que_ningun_consumidor_lee(): void
    {
        $claves = $this->claves();

        // Eliminadas junto con el bloque `dte.credenciales`, que no leía nadie.
        $this->assertNotContains('DTE_API_USER', $claves);
        $this->assertNotContains('DTE_API_PASSWORD', $claves);
        // El código lee PPQ_SALA_OC_PREFIX; PPQ_SALA_OC_OFFSET no lo lee nadie, así que
        // quien la cambiaba no cambiaba nada.
        $this->assertNotContains('PPQ_SALA_OC_OFFSET', $claves);
        $this->assertContains('PPQ_SALA_OC_PREFIX', $claves);
    }

    public function test_toda_clave_documentada_tiene_un_consumidor_en_config(): void
    {
        // Barrido inverso: cualquier clave del ejemplo que ningún config/*.php lea es
        // documentación que engaña. Se excluyen las que consume el framework o el build
        // fuera de config/ (Vite, PHP CLI).
        // Estas las lee el framework desde config/*.php que este proyecto NO publica
        // (hashing.php, broadcasting.php) o el build de front, fuera de config/.
        $exentas = ['VITE_APP_NAME', 'BCRYPT_ROUNDS', 'BROADCAST_CONNECTION'];

        $configs = '';
        foreach (glob(config_path('*.php')) as $archivo) {
            $configs .= (string) file_get_contents($archivo);
        }

        $huerfanas = [];
        foreach (array_diff($this->claves(), $exentas) as $clave) {
            if (! str_contains($configs, "'".$clave."'")) {
                $huerfanas[] = $clave;
            }
        }

        $this->assertSame([], $huerfanas,
            'Claves en .env.example que ningún config/*.php lee: '.implode(', ', $huerfanas));
    }

    public function test_ningun_secreto_trae_valor(): void
    {
        $contenido = $this->contenido();

        $secretos = [
            'APP_KEY', 'DB_PASSWORD', 'DTE_CERT_PASSWORD', 'DTE_TRANSMISION_PASSWORD',
            'DTE_TRANSMISION_TOKEN', 'DTE_PROD_USER', 'DTE_PROD_PASSWORD',
            'DTE_TEST_USER', 'DTE_TEST_PASSWORD', 'GMAIL_CLIENT_ID', 'GMAIL_CLIENT_SECRET',
            'DOCUMENTOS_RECIBIDOS_MAIL_USERNAME', 'DOCUMENTOS_RECIBIDOS_MAIL_PASSWORD',
            'BACKUP_ARCHIVE_PASSWORD', 'CLOUDFLARE_ACCESS_TEAM_DOMAIN', 'CLOUDFLARE_ACCESS_AUD',
        ];

        foreach ($secretos as $clave) {
            $this->assertMatchesRegularExpression('/^'.$clave.'=\s*$/m', $contenido,
                $clave.' debe estar presente y VACÍA en .env.example.');
        }
    }
}
