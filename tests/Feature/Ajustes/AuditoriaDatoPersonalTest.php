<?php

namespace Tests\Feature\Ajustes;

use App\Enums\ModuloSistema;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Los datos personales del patrono y el documento de los responsables de invalidación
 * se ven en Configuración, que es de quien la administra. La auditoría la lee más
 * gente —Contabilidad es una cuenta externa—: ahí queda que cambió, nunca el valor.
 *
 * Todos los datos de esta prueba son inventados.
 */
class AuditoriaDatoPersonalTest extends TestCase
{
    use RefreshDatabase;

    private const DUI = '01234567-8';

    private const DOMICILIO = 'Colonia Inventada, Ciudad Ficticia';

    private function usuario(string $rol): User
    {
        return User::factory()->create(['activo' => true])->assignRole($rol);
    }

    private function ultima(): Activity
    {
        return Activity::query()->where('log_name', 'ajustes')->latest('id')->firstOrFail();
    }

    private function guardarDatosDelPatrono(User $admin): void
    {
        $this->actingAs($admin);
        Ajustes::guardar('planilla.contrato.patrono.dui', self::DUI);
        Ajustes::guardar('planilla.contrato.patrono.domicilio', self::DOMICILIO);
    }

    public function test_el_cambio_de_un_dato_personal_no_deja_el_valor_en_la_auditoria(): void
    {
        $this->actingAs($this->usuario('administrador'));

        Ajustes::guardar('planilla.contrato.patrono.dui', '98765432-1');
        Ajustes::guardar('planilla.contrato.patrono.dui', self::DUI);

        $actividad = $this->ultima();
        $propiedades = json_encode($actividad->properties, JSON_UNESCAPED_UNICODE);

        $this->assertSame('planilla.contrato.patrono.dui', $actividad->getExtraProperty('clave'));
        $this->assertTrue($actividad->getExtraProperty('dato_personal'));
        $this->assertNull($actividad->getExtraProperty('valor_antes'));
        $this->assertNull($actividad->getExtraProperty('valor_despues'));
        $this->assertStringNotContainsString(self::DUI, $propiedades);
        $this->assertStringNotContainsString('98765432-1', $propiedades);
    }

    public function test_quitar_lo_guardado_de_un_dato_personal_tampoco_deja_el_valor(): void
    {
        config(['planilla.contrato.patrono.domicilio' => 'Barrio Ficticio']);
        $this->actingAs($this->usuario('administrador'));

        Ajustes::guardar('planilla.contrato.patrono.domicilio', self::DOMICILIO);
        Ajustes::quitarOverride('planilla.contrato.patrono.domicilio');

        $actividad = $this->ultima();
        $propiedades = json_encode($actividad->properties, JSON_UNESCAPED_UNICODE);

        $this->assertSame('override_quitado', $actividad->getExtraProperty('accion'));
        $this->assertTrue($actividad->getExtraProperty('dato_personal'));
        $this->assertStringNotContainsString(self::DOMICILIO, $propiedades);
        $this->assertStringNotContainsString('Barrio Ficticio', $propiedades);
    }

    public function test_el_documento_de_los_responsables_de_invalidacion_es_dato_personal(): void
    {
        $catalogo = Ajustes::catalogo();

        $this->assertTrue($catalogo->definicion('dte.invalidacion.responsable.num_doc')->esDatoPersonal());
        $this->assertTrue($catalogo->definicion('dte.invalidacion.solicita.num_doc')->esDatoPersonal());
        // Lo que es de la empresa, no de una persona, sigue auditándose con su valor.
        $this->assertFalse($catalogo->definicion('planilla.contrato.patrono.ciudad')->esDatoPersonal());
    }

    public function test_un_ajuste_que_no_es_dato_personal_sigue_dejando_el_antes_y_el_despues(): void
    {
        $this->actingAs($this->usuario('administrador'));

        Ajustes::guardar('planilla.contrato.patrono.ciudad', 'Ciudad Ficticia');

        $actividad = $this->ultima();

        $this->assertSame('Ciudad Ficticia', $actividad->getExtraProperty('valor_despues'));
        $this->assertNull($actividad->getExtraProperty('dato_personal'));
    }

    public function test_contabilidad_ve_que_cambio_pero_no_el_dui_ni_el_domicilio(): void
    {
        $this->guardarDatosDelPatrono($this->usuario('administrador'));

        $this->actingAs($this->usuario('contabilidad'))
            ->get(route('auditoria.index', ['log_name' => 'ajustes']))
            ->assertOk()
            ->assertSee('planilla.contrato.patrono.dui')
            ->assertSee('planilla.contrato.patrono.domicilio')
            ->assertDontSee(self::DUI)
            ->assertDontSee(self::DOMICILIO);
    }

    public function test_administracion_ve_el_dui_y_el_domicilio_en_configuracion(): void
    {
        config([ModuloSistema::Planilla->claveConfig() => true]);
        $admin = $this->usuario('administrador');
        $this->guardarDatosDelPatrono($admin);

        $this->actingAs($admin)
            ->get(route('configuracion.opciones.planilla'))
            ->assertOk()
            ->assertSee(self::DUI)
            ->assertSee(self::DOMICILIO);

        $this->actingAs($this->usuario('contabilidad'))
            ->get(route('configuracion.opciones.planilla'))
            ->assertForbidden();
    }
}
