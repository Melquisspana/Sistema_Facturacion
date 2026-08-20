<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Rotacion\RotacionAppKey;
use App\Ajustes\Rotacion\RotacionImposibleException;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\GmailCuenta;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Rotación de APP_KEY.
 *
 * Lo que se fija acá es lo que separa una rotación de una pérdida de datos: que
 * NO escribe si algo no se descifra, que verifica el round-trip antes de tocar
 * nada, que cubre TODOS los orígenes cifrados (no solo los ajustes), y que en
 * ningún momento imprime una clave ni un secreto.
 *
 * Ninguna prueba de este archivo cambia la APP_KEY del entorno: se construye un
 * cifrador con la clave nueva en memoria y se compara contra él.
 */
class RotacionAppKeyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'contrasena-smtp-para-rotar-42';

    private const TOKEN = 'ya29.token-de-gmail-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    private function rotacion(): RotacionAppKey
    {
        return app(RotacionAppKey::class);
    }

    /** Una clave válida distinta de la del entorno, en el formato del .env. */
    private function claveNueva(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));
    }

    private function cifradorDe(string $clave): Encrypter
    {
        return new Encrypter(RotacionAppKey::normalizar($clave), (string) config('app.cipher', 'AES-256-CBC'));
    }

    /** Deja un secreto guardado por la vía normal (cifrado con la clave actual). */
    private function conSecreto(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar('mail.smtp.password', self::SECRETO);
    }

    private function conTokenGmail(): GmailCuenta
    {
        return GmailCuenta::create([
            'email' => 'ppq@ejemplo.com',
            'access_token' => self::TOKEN,
            'refresh_token' => self::TOKEN.'-refresh',
            'scopes' => 'gmail.readonly',
        ]);
    }

    // ------------------------------------------------------------ inventario

    public function test_sin_secretos_no_hay_nada_que_rotar(): void
    {
        $informe = $this->rotacion()->analizar($this->claveNueva());

        $this->assertTrue($informe->sinSecretos());
        $this->assertTrue($informe->puedeAplicarse());
        $this->assertSame([], $this->rotacion()->afectados());
    }

    /** La rotación cubre los dos orígenes cifrados, no solo los ajustes. */
    public function test_inventaria_ajustes_y_tokens_de_gmail(): void
    {
        $this->conSecreto();
        $this->conTokenGmail();

        $afectados = $this->rotacion()->afectados();

        $this->assertCount(3, $afectados, 'Un ajuste + dos tokens de Gmail.');
        $this->assertNotEmpty(array_filter($afectados, fn ($e) => str_contains($e, 'mail.smtp.password')));
        $this->assertNotEmpty(array_filter($afectados, fn ($e) => str_contains($e, 'access_token')));
        $this->assertNotEmpty(array_filter($afectados, fn ($e) => str_contains($e, 'refresh_token')));
    }

    /** El inventario nombra los secretos; jamás los contiene. */
    public function test_el_inventario_no_contiene_ningun_secreto(): void
    {
        $this->conSecreto();
        $this->conTokenGmail();

        $volcado = implode(' ', $this->rotacion()->afectados());

        $this->assertStringNotContainsString(self::SECRETO, $volcado);
        $this->assertStringNotContainsString(self::TOKEN, $volcado);
    }

    // ------------------------------------------------------------- análisis

    public function test_el_analisis_no_escribe_nada(): void
    {
        $this->conSecreto();
        $antes = AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor');

        $informe = $this->rotacion()->analizar($this->claveNueva());

        $this->assertTrue($informe->puedeAplicarse());
        $this->assertSame(
            $antes,
            AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor'),
            'El análisis no puede tocar el criptograma guardado.',
        );
    }

    public function test_el_informe_no_contiene_secretos(): void
    {
        $this->conSecreto();
        $this->conTokenGmail();

        $informe = $this->rotacion()->analizar($this->claveNueva());
        $volcado = json_encode([$informe->legibles, $informe->ilegibles, $informe->noVerificados], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRETO, (string) $volcado);
        $this->assertStringNotContainsString(self::TOKEN, (string) $volcado);
    }

    // -------------------------------------------------------------- aborto

    /**
     * EL caso que justifica el paso de análisis: una fila cifrada con OTRA clave.
     * Rotar la destruiría, así que no se rota nada.
     */
    public function test_aborta_si_algo_no_se_descifra_con_la_clave_actual(): void
    {
        $this->conSecreto();

        // Se ensucia una fila con un criptograma de otra clave, como pasaría si
        // alguien hubiera cambiado APP_KEY a mano en algún momento.
        $ajena = $this->cifradorDe($this->claveNueva());
        DB::table('ajustes_sistema')
            ->where('clave', 'mail.smtp.password')
            ->update(['valor' => $ajena->encryptString('valor-de-otra-clave')]);

        $intacto = AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor');

        try {
            $this->rotacion()->ejecutar($this->claveNueva());
            $this->fail('Debería haber abortado.');
        } catch (RotacionImposibleException $e) {
            $this->assertStringContainsString('no se pueden descifrar', $e->getMessage());
            $this->assertStringContainsString('mail.smtp.password', $e->getMessage());
            $this->assertFalse($e->informe->puedeAplicarse());
        }

        $this->assertSame(
            $intacto,
            AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor'),
            'Una rotación abortada no puede haber escrito nada.',
        );
    }

    /** Una fila rota no impide informar del resto: se listan todas de una vez. */
    public function test_informa_todas_las_filas_ilegibles(): void
    {
        $this->conSecreto();
        $cuenta = $this->conTokenGmail();

        DB::table('gmail_cuentas')->where('id', $cuenta->id)->update([
            'access_token' => 'esto-no-es-un-criptograma',
            'refresh_token' => 'esto-tampoco',
        ]);

        $informe = $this->rotacion()->analizar($this->claveNueva());

        $this->assertCount(2, $informe->ilegibles);
        $this->assertCount(1, $informe->legibles, 'El ajuste sano sigue siendo legible.');
        $this->assertFalse($informe->puedeAplicarse());
    }

    public function test_una_clave_nueva_invalida_se_rechaza_antes_de_tocar_nada(): void
    {
        $this->conSecreto();

        $this->expectException(\InvalidArgumentException::class);

        $this->rotacion()->analizar('base64:'.base64_encode('demasiado-corta'));
    }

    public function test_una_clave_vacia_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RotacionAppKey::normalizar('   ');
    }

    // ------------------------------------------------------------ ejecución

    /** El re-cifrado deja los valores legibles con la clave NUEVA y solo con ella. */
    public function test_la_rotacion_recifra_con_la_clave_nueva(): void
    {
        $this->conSecreto();
        $clave = $this->claveNueva();

        $informe = $this->rotacion()->ejecutar($clave);

        $this->assertTrue($informe->aplicada);

        $criptograma = (string) AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor');

        // Con la clave nueva se lee el original…
        $this->assertSame(self::SECRETO, $this->cifradorDe($clave)->decryptString($criptograma));

        // …y con la actual ya no.
        $this->expectException(DecryptException::class);
        Crypt::decryptString($criptograma);
    }

    public function test_la_rotacion_recifra_tambien_los_tokens_de_gmail(): void
    {
        $cuenta = $this->conTokenGmail();
        $clave = $this->claveNueva();

        $this->rotacion()->ejecutar($clave);

        $fila = DB::table('gmail_cuentas')->where('id', $cuenta->id)->first();
        $cifrador = $this->cifradorDe($clave);

        $this->assertSame(self::TOKEN, $cifrador->decryptString((string) $fila->access_token));
        $this->assertSame(self::TOKEN.'-refresh', $cifrador->decryptString((string) $fila->refresh_token));
    }

    /** Nunca queda texto plano en la base, ni antes ni después de rotar. */
    public function test_nunca_queda_texto_plano_en_la_base(): void
    {
        $this->conSecreto();
        $this->conTokenGmail();

        $this->rotacion()->ejecutar($this->claveNueva());

        $volcado = json_encode([
            DB::table('ajustes_sistema')->get(),
            DB::table('gmail_cuentas')->get(),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRETO, (string) $volcado);
        $this->assertStringNotContainsString(self::TOKEN, (string) $volcado);
    }

    /** Una columna vacía no es un secreto y no bloquea la rotación. */
    public function test_las_columnas_vacias_no_bloquean_la_rotacion(): void
    {
        GmailCuenta::create(['email' => 'a-medio-conectar@ejemplo.com', 'scopes' => 'gmail.readonly']);

        $informe = $this->rotacion()->analizar($this->claveNueva());

        $this->assertTrue($informe->puedeAplicarse());
        $this->assertTrue($informe->sinSecretos());
    }

    // -------------------------------------------------------------- comando

    public function test_el_comando_simula_por_defecto_y_no_escribe(): void
    {
        $this->conSecreto();
        $antes = AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor');

        $this->artisan('ajustes:rotar-app-key', ['--nueva-key' => $this->claveNueva()])
            ->expectsOutputToContain('SIMULACIÓN')
            ->assertSuccessful();

        $this->assertSame($antes, AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor'));
    }

    public function test_el_comando_no_imprime_claves_ni_secretos(): void
    {
        $this->conSecreto();
        $this->conTokenGmail();
        $clave = $this->claveNueva();

        $this->artisan('ajustes:rotar-app-key', ['--nueva-key' => $clave])->assertSuccessful();

        // La salida del comando la captura el runner; se comprueba sobre el buffer.
        $salida = Artisan::output();

        $this->assertStringNotContainsString(self::SECRETO, $salida);
        $this->assertStringNotContainsString(self::TOKEN, $salida);
        $this->assertStringNotContainsString($clave, $salida);
        $this->assertStringNotContainsString((string) config('app.key'), $salida);
    }

    public function test_el_comando_falla_sin_clave_nueva(): void
    {
        $this->conSecreto();

        $this->artisan('ajustes:rotar-app-key')
            ->expectsOutputToContain('Falta la clave nueva')
            ->assertFailed();
    }

    /** Sin la frase exacta no se escribe, aunque se pida --ejecutar. */
    public function test_el_comando_exige_la_frase_para_ejecutar(): void
    {
        $this->conSecreto();
        $antes = AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor');

        $this->artisan('ajustes:rotar-app-key', ['--nueva-key' => $this->claveNueva(), '--ejecutar' => true])
            ->expectsQuestion('Escribí «ROTAR CLAVE DE CIFRADO» para continuar', 'si')
            ->expectsOutputToContain('Confirmación incorrecta')
            ->assertFailed();

        $this->assertSame($antes, AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor'));
    }

    public function test_el_comando_ejecuta_con_la_frase_correcta(): void
    {
        $this->conSecreto();
        $clave = $this->claveNueva();

        $this->artisan('ajustes:rotar-app-key', ['--nueva-key' => $clave, '--ejecutar' => true])
            ->expectsQuestion('Escribí «ROTAR CLAVE DE CIFRADO» para continuar', 'ROTAR CLAVE DE CIFRADO')
            ->expectsOutputToContain('Secretos re-cifrados: 1')
            ->assertSuccessful();

        $criptograma = (string) AjusteSistema::query()->where('clave', 'mail.smtp.password')->value('valor');
        $this->assertSame(self::SECRETO, $this->cifradorDe($clave)->decryptString($criptograma));
    }

    /** El comando recuerda que el .env es cosa de una persona, no suya. */
    public function test_el_comando_no_toca_el_env_y_lo_dice(): void
    {
        $this->conSecreto();

        $this->artisan('ajustes:rotar-app-key', ['--nueva-key' => $this->claveNueva()])
            ->expectsOutputToContain('Pasos que este comando NO hace')
            ->expectsOutputToContain('APP_KEY=<la clave nueva>')
            ->assertSuccessful();
    }
}
