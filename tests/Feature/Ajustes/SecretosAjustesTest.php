<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Definicion\FuenteAjuste;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * LA REGLA: un secreto nunca vuelve al navegador.
 *
 * Estos tests son la parte de la fase que hay que poder releer dentro de un año y
 * seguir confiando. Cubren las cuatro salidas por las que un secreto podría
 * escaparse —la pantalla, el JSON, la serialización del modelo y la auditoría— y
 * la garantía de que en la base no queda texto plano.
 */
class SecretosAjustesTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'mail.smtp.password';

    private const SECRETO = 'sup3r-s3cr3t0-de-prueba';

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    // ------------------------------------------------------------- cifrado

    public function test_el_secreto_se_guarda_cifrado_y_no_en_texto_plano(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, self::SECRETO);

        $fila = AjusteSistema::query()->where('clave', self::CLAVE)->firstOrFail();

        $this->assertTrue((bool) $fila->cifrado, 'La fila debe quedar marcada como cifrada.');
        $this->assertNotSame(self::SECRETO, $fila->valor, 'El valor guardado no puede ser el texto plano.');
        $this->assertStringNotContainsString(self::SECRETO, (string) $fila->valor);

        // Y tampoco en ninguna otra columna de la fila.
        $this->assertStringNotContainsString(self::SECRETO, json_encode($fila->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_el_secreto_se_recupera_correctamente_en_runtime(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, self::SECRETO);

        $this->assertSame(self::SECRETO, Ajustes::secretoParaRuntime(self::CLAVE));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente(self::CLAVE));
    }

    /** Un secreto puede terminar en espacio: recortarlo rompería la autenticación. */
    public function test_el_secreto_no_se_recorta(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, '  con espacios  ');

        $this->assertSame('  con espacios  ', Ajustes::secretoParaRuntime(self::CLAVE));
    }

    // -------------------------------------------------------------- pantalla

    public function test_el_estado_para_pantalla_no_lleva_el_valor(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, self::SECRETO);

        $estado = Ajustes::estadoParaPantalla(self::CLAVE);

        $this->assertTrue($estado->esSecreto);
        $this->assertTrue($estado->configurado);
        $this->assertSame(FuenteAjuste::BaseDeDatos, $estado->fuente);
        $this->assertNull($estado->valor, 'El DTO de pantalla no puede llevar el secreto.');
    }

    public function test_el_estado_para_pantalla_no_filtra_el_secreto_al_serializarse(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, self::SECRETO);

        $json = json_encode(Ajustes::estadoParaPantalla(self::CLAVE), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRETO, (string) $json);
        $this->assertStringContainsString('"configurado":true', (string) $json);
        $this->assertStringContainsString('"fuente":"base_de_datos"', (string) $json);
    }

    public function test_toda_la_seccion_de_secretos_puede_publicarse_sin_filtrar_nada(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, self::SECRETO);

        $json = json_encode(Ajustes::estadosDeSeccion('correo_saliente'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRETO, (string) $json);
    }

    /** El modelo tampoco arrastra el criptograma en un toArray()/toJson() accidental. */
    public function test_el_modelo_no_serializa_el_valor(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, self::SECRETO);

        $fila = AjusteSistema::query()->where('clave', self::CLAVE)->firstOrFail();

        $this->assertArrayNotHasKey('valor', $fila->toArray());
        $this->assertStringNotContainsString(self::SECRETO, $fila->toJson());
    }

    /** Pedir un secreto con `get()` es un error de programación, no un descuido silencioso. */
    public function test_get_rechaza_un_secreto(): void
    {
        $this->actingAs($this->admin());
        Ajustes::guardar(self::CLAVE, self::SECRETO);

        $this->expectException(\LogicException::class);

        Ajustes::get(self::CLAVE);
    }

    public function test_secreto_para_runtime_rechaza_un_ajuste_normal(): void
    {
        $this->expectException(\LogicException::class);

        Ajustes::secretoParaRuntime('contabilidad.correo');
    }

    // -------------------------------------------------------------- auditoría

    public function test_la_auditoria_registra_el_hecho_pero_no_el_secreto(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, self::SECRETO);

        $actividad = Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();

        $this->assertStringContainsString('reemplazó el secreto', (string) $actividad->description);
        $this->assertStringContainsString(self::CLAVE, (string) $actividad->description);
        $this->assertSame('reemplazo_secreto', $actividad->getExtraProperty('accion'));

        // Ni el valor, ni el anterior, ni un hash de ninguno.
        $propiedades = json_encode($actividad->properties, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::SECRETO, (string) $propiedades);
        $this->assertStringNotContainsString(md5(self::SECRETO), (string) $propiedades);
        $this->assertStringNotContainsString(sha1(self::SECRETO), (string) $propiedades);
        $this->assertNull($actividad->getExtraProperty('valor_antes'));
        $this->assertNull($actividad->getExtraProperty('valor_despues'));
    }

    public function test_ningun_registro_de_auditoria_contiene_el_secreto(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar(self::CLAVE, self::SECRETO);
        Ajustes::guardar(self::CLAVE, self::SECRETO.'-otro');
        Ajustes::quitarOverride(self::CLAVE);

        foreach (Activity::all() as $actividad) {
            $volcado = $actividad->description.' '.json_encode($actividad->properties, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString(self::SECRETO, $volcado);
        }
    }

    // ------------------------------------------------------------- fallback

    public function test_sin_override_el_secreto_se_resuelve_desde_config(): void
    {
        config(['mail.mailers.smtp.password' => 'clave-del-env']);

        $this->assertSame('clave-del-env', Ajustes::secretoParaRuntime(self::CLAVE));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente(self::CLAVE));

        // Y de eso solo se publica que está configurado y de dónde sale.
        $estado = Ajustes::estadoParaPantalla(self::CLAVE);
        $this->assertTrue($estado->configurado);
        $this->assertNull($estado->valor);
    }

    public function test_sin_override_ni_config_el_secreto_figura_como_no_configurado(): void
    {
        config(['mail.mailers.smtp.password' => null]);

        $estado = Ajustes::estadoParaPantalla(self::CLAVE);

        $this->assertFalse($estado->configurado);
        $this->assertSame(FuenteAjuste::NoConfigurado, $estado->fuente);
        $this->assertNotNull($estado->advertencia);
        $this->assertNull(Ajustes::secretoParaRuntime(self::CLAVE));
    }

    /** Crear la infraestructura NO copia los secretos que hoy viven en el .env. */
    public function test_la_infraestructura_no_migra_secretos_existentes(): void
    {
        config(['mail.mailers.smtp.password' => 'clave-del-env']);

        Ajustes::secretoParaRuntime(self::CLAVE);
        Ajustes::estadoParaPantalla(self::CLAVE);

        // Leer un secreto no puede persistirlo en la base.
        $this->assertDatabaseCount('ajustes_sistema', 0);
    }
}
