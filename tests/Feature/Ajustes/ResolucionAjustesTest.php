<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Excepciones\ConflictoDeAjusteException;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Facades\Ajustes;
use App\Models\AjusteSistema;
use App\Models\Configuracion;
use App\Models\User;
use App\Support\Dte\PlantillaCorreo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Orden de resolución: override → config/.env → valor por defecto → nada.
 *
 * Y, sobre todo, la propiedad que evita el problema clásico de estas capas: que
 * el MISMO valor termine guardado en dos sitios y nadie sepa cuál manda. Cada
 * ajuste declara UNA ubicación de escritura, y acá se comprueba que la otra
 * tabla ni se toca.
 */
class ResolucionAjustesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Configuracion::olvidarCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    // ------------------------------------------------------------- fallbacks

    public function test_sin_override_se_usa_config(): void
    {
        config(['backup_diario.dias_retencion' => 45]);

        $this->assertSame(45, Ajustes::entero('respaldos.dias_retencion'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('respaldos.dias_retencion'));
    }

    public function test_sin_override_ni_config_se_usa_el_valor_por_defecto(): void
    {
        config(['backup_diario.dias_retencion' => null]);

        $this->assertSame(30, Ajustes::entero('respaldos.dias_retencion'));
        $this->assertSame(FuenteAjuste::Defecto, Ajustes::fuente('respaldos.dias_retencion'));
    }

    public function test_el_override_de_base_de_datos_gana_sobre_config(): void
    {
        config(['backup_diario.dias_retencion' => 45]);
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 90);

        $this->assertSame(90, Ajustes::entero('respaldos.dias_retencion'));
        $this->assertSame(FuenteAjuste::BaseDeDatos, Ajustes::fuente('respaldos.dias_retencion'));
    }

    public function test_quitar_el_override_devuelve_el_valor_al_fallback(): void
    {
        config(['backup_diario.dias_retencion' => 45]);
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 90);
        $this->assertSame(90, Ajustes::entero('respaldos.dias_retencion'));

        Ajustes::quitarOverride('respaldos.dias_retencion');

        $this->assertSame(45, Ajustes::entero('respaldos.dias_retencion'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('respaldos.dias_retencion'));
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'respaldos.dias_retencion']);
    }

    /**
     * El fallback es EXPLÍCITO por ajuste: solo se consulta la clave de config que
     * la definición declara. No existe un `env()` dinámico que pueda alcanzar
     * cualquier variable de entorno.
     */
    public function test_el_fallback_solo_mira_la_clave_declarada(): void
    {
        $definicion = Ajustes::catalogo()->definicion('respaldos.dias_retencion');

        $this->assertSame('backup_diario.dias_retencion', $definicion->claveConfig);

        $definicionSinFallback = Ajustes::catalogo()->definicion('contabilidad.correo');
        $this->assertNull($definicionSinFallback->claveConfig, 'El correo de contabilidad no debe leerse del .env.');
    }

    // ------------------------------------------- una sola ubicación por clave

    /**
     * Las claves de contabilidad/correo siguen viviendo en `configuraciones`.
     * Guardarlas por la capa nueva NO puede dejar una copia en `ajustes_sistema`:
     * dos filas con el mismo dato son un incidente esperando a ocurrir.
     */
    public function test_una_clave_legacy_no_se_duplica_en_la_tabla_nueva(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('contabilidad.correo', 'conta@ejemplo.com');

        $this->assertDatabaseHas('configuraciones', ['clave' => 'contabilidad.correo', 'valor' => 'conta@ejemplo.com']);
        $this->assertDatabaseMissing('ajustes_sistema', ['clave' => 'contabilidad.correo']);
        $this->assertSame(FuenteAjuste::BaseDeDatosLegacy, Ajustes::fuente('contabilidad.correo'));
    }

    /** Y al revés: una clave de la tabla nueva no escribe en la tabla anterior. */
    public function test_una_clave_nueva_no_escribe_en_la_tabla_anterior(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 60);

        $this->assertDatabaseHas('ajustes_sistema', ['clave' => 'respaldos.dias_retencion']);
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'respaldos.dias_retencion']);
    }

    public function test_la_capa_nueva_lee_lo_que_ya_hay_en_la_tabla_anterior(): void
    {
        // Escrito con el modelo antiguo, como haría cualquier código existente.
        Configuracion::set('correo.auto_envio', true);
        Configuracion::set('contabilidad.correo', 'previo@ejemplo.com');

        $this->assertTrue(Ajustes::bool('correo.auto_envio'));
        $this->assertSame('previo@ejemplo.com', Ajustes::texto('contabilidad.correo'));
    }

    /** Y lo que escribe la capa nueva lo sigue viendo el código antiguo. */
    public function test_el_codigo_antiguo_ve_lo_que_escribe_la_capa_nueva(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('correo.auto_envio', true);

        $this->assertTrue(Configuracion::getBool('correo.auto_envio', false));
    }

    public function test_la_plantilla_de_correo_cae_a_su_valor_por_defecto(): void
    {
        $this->assertSame(PlantillaCorreo::DEFAULT, Ajustes::texto('correo.plantilla'));
        $this->assertSame(FuenteAjuste::Defecto, Ajustes::fuente('correo.plantilla'));
    }

    // ---------------------------------------------------------- transacción

    public function test_guardar_varios_es_atomico(): void
    {
        $this->actingAs($this->admin());

        try {
            Ajustes::guardarVarios([
                'contabilidad.correo' => 'valido@ejemplo.com',
                'contabilidad.enviar_copia' => 'quizás', // inválido: revienta el lote
            ]);
            $this->fail('Debería haber lanzado ValorAjusteInvalidoException.');
        } catch (ValorAjusteInvalidoException) {
            // Esperado.
        }

        Configuracion::olvidarCache();

        $this->assertNull(
            Configuracion::get('contabilidad.correo'),
            'El primer valor no puede quedar aplicado si el segundo falló.'
        );
    }

    // ---------------------------------------------------------- concurrencia

    public function test_una_escritura_sobre_un_valor_ya_cambiado_se_rechaza(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 30);

        $vistoEn = AjusteSistema::query()->where('clave', 'respaldos.dias_retencion')->value('updated_at');

        // Otro administrador guarda un segundo después.
        $this->travel(2)->seconds();
        Ajustes::guardar('respaldos.dias_retencion', 60);

        $this->expectException(ConflictoDeAjusteException::class);

        Ajustes::guardar('respaldos.dias_retencion', 90, Carbon::parse($vistoEn));
    }

    public function test_sin_cambios_de_por_medio_la_escritura_pasa(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 30);
        $vistoEn = AjusteSistema::query()->where('clave', 'respaldos.dias_retencion')->value('updated_at');

        Ajustes::guardar('respaldos.dias_retencion', 90, Carbon::parse($vistoEn));

        $this->assertSame(90, Ajustes::entero('respaldos.dias_retencion'));
    }
}
