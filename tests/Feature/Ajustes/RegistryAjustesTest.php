<?php

namespace Tests\Feature\Ajustes;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\ConversorValor;
use App\Ajustes\Definicion\Editabilidad;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Definicion\Impacto;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\Definicion\Persistencia;
use App\Ajustes\Definicion\TipoAjuste;
use App\Ajustes\Excepciones\AjusteDesconocidoException;
use App\Ajustes\Excepciones\AjusteNoEditableException;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Enums\PermisoSistema;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El registry es una LISTA BLANCA y los tipos convierten de forma determinista.
 *
 * Son las dos propiedades de las que depende todo lo demás: si una clave
 * arbitraria pudiera leerse, el catálogo no serviría de nada; si "false" pudiera
 * volverse true al releerlo, un interruptor de seguridad podría quedar encendido
 * sin que nadie lo tocara.
 */
class RegistryAjustesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['activo' => true])->assignRole('administrador');
    }

    // ------------------------------------------------------------ lista blanca

    public function test_una_clave_desconocida_no_puede_leerse(): void
    {
        $this->expectException(AjusteDesconocidoException::class);

        Ajustes::get('inventada.por.el.navegador');
    }

    public function test_una_clave_desconocida_no_puede_escribirse(): void
    {
        $this->actingAs($this->admin());

        $this->expectException(AjusteDesconocidoException::class);

        Ajustes::guardar('inventada.por.el.navegador', 'x');
    }

    /**
     * El caso que motiva la lista blanca: aunque la clave EXISTA en la
     * configuración de Laravel, si no está declarada no se lee. Sin esto,
     * `Ajustes::get($request->clave)` sería una ventana a config/.env.
     */
    public function test_una_clave_de_config_no_declarada_sigue_siendo_desconocida(): void
    {
        $this->assertNotNull(config('database.connections.mysql.password', null), 'Precondición del test: la clave existe en config.');

        $this->expectException(AjusteDesconocidoException::class);

        Ajustes::get('database.connections.mysql.password');
    }

    public function test_el_catalogo_no_expone_claves_de_infraestructura(): void
    {
        $prohibidas = ['app.key', 'database.password', 'session.driver', 'queue.default', 'cache.default', 'filesystems.default'];

        foreach ($prohibidas as $clave) {
            $this->assertFalse(
                app(CatalogoAjustes::class)->existe($clave),
                "«{$clave}» es infraestructura y no puede ser un ajuste web."
            );
        }
    }

    // ------------------------------------------------------------------ tipos

    /**
     * El bug clásico de PHP: `(bool) 'false' === true`. Un interruptor guardado
     * como el texto "false" tiene que releerse como false.
     */
    public function test_el_texto_false_no_se_interpreta_como_verdadero(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('correo.auto_envio', 'false');

        $this->assertFalse(Ajustes::bool('correo.auto_envio'));
    }

    public function test_los_booleanos_aceptan_las_formas_habituales(): void
    {
        $this->actingAs($this->admin());

        foreach ([true, '1', 'true', 'on', 'si', 'sí', 'yes'] as $verdadero) {
            Ajustes::guardar('correo.auto_envio', $verdadero);
            $this->assertTrue(Ajustes::bool('correo.auto_envio'), "«{$verdadero}» debería ser verdadero.");
        }

        foreach ([false, '0', 'false', 'off', 'no'] as $falso) {
            Ajustes::guardar('correo.auto_envio', $falso);
            $this->assertFalse(Ajustes::bool('correo.auto_envio'), "«{$falso}» debería ser falso.");
        }
    }

    public function test_un_booleano_no_acepta_un_valor_ambiguo(): void
    {
        $this->actingAs($this->admin());

        $this->expectException(ValorAjusteInvalidoException::class);

        Ajustes::guardar('correo.auto_envio', 'quizás');
    }

    public function test_un_entero_rechaza_texto_que_empieza_por_numero(): void
    {
        $this->actingAs($this->admin());

        $this->expectException(ValorAjusteInvalidoException::class);

        // (int) '30dias' daría 30 en PHP. Acá es un error.
        Ajustes::guardar('respaldos.dias_retencion', '30dias');
    }

    public function test_un_entero_respeta_su_rango(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('respaldos.dias_retencion', 14);
        $this->assertSame(14, Ajustes::entero('respaldos.dias_retencion'));

        $this->expectException(ValorAjusteInvalidoException::class);
        Ajustes::guardar('respaldos.dias_retencion', 0);
    }

    public function test_un_email_invalido_se_rechaza(): void
    {
        $this->actingAs($this->admin());

        $this->expectException(ValorAjusteInvalidoException::class);

        Ajustes::guardar('contabilidad.correo', 'no-es-un-correo');
    }

    public function test_un_email_se_normaliza_a_minusculas(): void
    {
        $this->actingAs($this->admin());

        Ajustes::guardar('contabilidad.correo', '  Contabilidad@Empresa.COM  ');

        $this->assertSame('contabilidad@empresa.com', Ajustes::texto('contabilidad.correo'));
    }

    // --------------------------------------------------------------- enums

    public function test_un_enum_rechaza_un_valor_fuera_de_la_lista(): void
    {
        $definicion = app(CatalogoAjustes::class)->definicion('dte.ambiente');

        $this->assertSame(TipoAjuste::Enumerado, $definicion->tipo);
        $this->assertSame(['00', '01'], $definicion->opciones);

        $this->expectException(ValorAjusteInvalidoException::class);

        app(ConversorValor::class)->validarYNormalizar($definicion, '99');
    }

    public function test_un_enum_acepta_los_valores_declarados(): void
    {
        $conversor = app(ConversorValor::class);
        $definicion = app(CatalogoAjustes::class)->definicion('dte.transmision.ambiente');

        $this->assertSame('produccion', $conversor->validarYNormalizar($definicion, 'produccion'));
        $this->assertSame('testing', $conversor->validarYNormalizar($definicion, 'testing'));
    }

    // ------------------------------------------------------------------- N3

    /**
     * Los cuatro ajustes fiscales están DECLARADOS y clasificados como críticos,
     * pero cerrados a escritura en esta fase. El registry tiene que saber las dos
     * cosas a la vez.
     */
    public function test_los_ajustes_fiscales_son_n3_criticos_y_no_editables(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        foreach (['dte.ambiente', 'dte.transmision.ambiente', 'dte.firma.enabled', 'dte.transmision.enabled'] as $clave) {
            $definicion = $catalogo->definicion($clave);

            $this->assertSame(NivelConfirmacion::N3, $definicion->nivel, "{$clave} debería ser N3.");
            $this->assertSame(Impacto::FiscalCritico, $definicion->impacto, "{$clave} debería ser fiscal crítico.");
            $this->assertSame(Editabilidad::Futura, $definicion->editabilidad, "{$clave} no debería ser editable todavía.");
            $this->assertSame(Persistencia::Ninguna, $definicion->persistencia, "{$clave} no debería tener dónde guardarse.");
            $this->assertTrue($definicion->nivel->requiereCeremoniaFuerte());
            $this->assertSame(PermisoSistema::ConfiguracionCritica, $definicion->nivel->permisoRequerido());
        }
    }

    public function test_los_ajustes_n3_se_leen_de_config(): void
    {
        config(['dte.ambiente' => '00']);

        $this->assertSame('00', Ajustes::texto('dte.ambiente'));
        $this->assertSame(FuenteAjuste::Configuracion, Ajustes::fuente('dte.ambiente'));
    }

    /** Ni siquiera el administrador puede escribir un N3 en esta fase. */
    public function test_un_ajuste_n3_no_puede_escribirse_todavia(): void
    {
        $this->actingAs($this->admin());

        $this->expectException(AjusteNoEditableException::class);

        Ajustes::guardar('dte.ambiente', '01');
    }

    public function test_el_ambiente_fiscal_no_cambia_tras_el_intento(): void
    {
        config(['dte.ambiente' => '00']);
        $this->actingAs($this->admin());

        try {
            Ajustes::guardar('dte.ambiente', '01');
        } catch (\Throwable) {
            // Esperado.
        }

        $this->assertSame('00', config('dte.ambiente'));
        $this->assertSame('00', Ajustes::texto('dte.ambiente'));
        $this->assertDatabaseCount('ajustes_sistema', 0);
    }

    // ---------------------------------------------------------------- niveles

    public function test_los_niveles_mapean_al_permiso_correcto(): void
    {
        $this->assertSame(PermisoSistema::ConfiguracionGestionar, NivelConfirmacion::N1->permisoRequerido());
        $this->assertSame(PermisoSistema::ConfiguracionGestionar, NivelConfirmacion::N2->permisoRequerido());
        $this->assertSame(PermisoSistema::ConfiguracionCritica, NivelConfirmacion::N3->permisoRequerido());

        $this->assertFalse(NivelConfirmacion::N1->requiereConfirmacion());
        $this->assertTrue(NivelConfirmacion::N2->requiereConfirmacion());
        $this->assertTrue(NivelConfirmacion::N3->requiereConfirmacion());
    }

    public function test_cada_ajuste_del_catalogo_declara_su_nivel(): void
    {
        foreach (app(CatalogoAjustes::class)->todos() as $clave => $definicion) {
            $this->assertNotEmpty($definicion->etiqueta, "{$clave} necesita una etiqueta legible.");
            $this->assertNotEmpty($definicion->seccion, "{$clave} necesita una sección.");
            $this->assertSame($clave, $definicion->clave);
        }
    }
}
