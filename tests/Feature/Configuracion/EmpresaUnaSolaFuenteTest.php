<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\Resumen\ResumenConfiguracion;
use App\Facades\Ajustes;
use App\Models\Empresa;
use App\Services\Planilla\IdentidadNegocio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los datos de la empresa salen de UN lugar (la tabla `empresas`, Configuración ›
 * Empresa emisora), y el Resumen lee por Ajustes como el resto del Centro.
 */
class EmpresaUnaSolaFuenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_planilla_lee_la_empresa_y_no_config_company(): void
    {
        config(['company.nombre' => 'Nombre del env', 'company.nombre_comercial' => 'Comercial del env']);
        Empresa::create(['razon_social' => 'Patrona Ejemplo', 'nombre_comercial' => 'Negocio Ejemplo', 'nit' => '00000000000000', 'ambiente' => '00', 'activo' => true]);

        $identidad = new IdentidadNegocio;

        $this->assertSame('Negocio Ejemplo', $identidad->negocio());
        $this->assertSame('Patrona Ejemplo', $identidad->empleadora());
        $this->assertSame('00000000000000', $identidad->nit());
    }

    public function test_sin_nombre_comercial_el_negocio_es_la_razon_social_y_la_empleadora_no_se_pierde(): void
    {
        config(['company.nombre_comercial' => 'Comercial del env']);
        Empresa::create(['razon_social' => 'Patrona Ejemplo', 'ambiente' => '00', 'activo' => true]);

        $identidad = new IdentidadNegocio;

        $this->assertSame('Patrona Ejemplo', $identidad->negocio());
        $this->assertSame('Patrona Ejemplo', $identidad->empleadora());
    }

    public function test_sin_empresa_no_inventa_un_nombre_desde_el_env(): void
    {
        config(['company.nombre' => 'Nombre del env', 'company.nombre_comercial' => 'Comercial del env']);

        $this->assertSame('', (new IdentidadNegocio)->negocio());
    }

    /** El Resumen dice lo que el lector va a usar: lo guardado, no el .env. */
    public function test_el_resumen_lee_el_buzon_y_gmail_por_ajustes(): void
    {
        config([
            'documentos_recibidos.mail.driver' => 'none',
            'documentos_recibidos.mail.host' => '',
            'documentos_recibidos.mail.username' => '',
            'documentos_recibidos.mail.password' => '',
            'ppq.gmail.client_id' => '',
            'ppq.gmail.client_secret' => '',
        ]);

        Ajustes::guardarComoSistema('documentos_recibidos.driver', 'imap');
        Ajustes::guardarComoSistema('documentos_recibidos.host', 'imap.ejemplo.com');
        Ajustes::guardarComoSistema('documentos_recibidos.username', 'compras@ejemplo.com');
        Ajustes::guardarComoSistema('documentos_recibidos.password', 'clave-ficticia');
        Ajustes::guardarComoSistema('ppq.gmail.client_id', 'cliente-ficticio');
        Ajustes::guardarComoSistema('ppq.gmail.client_secret', 'secreto-ficticio');

        $tarjetas = collect(app(ResumenConfiguracion::class)->tarjetas())->keyBy('clave');

        $imap = $tarjetas['imap'];
        $this->assertSame('Configurado', $imap->estado->etiqueta());
        $this->assertContains('Usuario: co••••@ejemplo.com', $imap->lineas);
        $this->assertSame('Guardado en la aplicación', $imap->fuente);

        $this->assertContains('Credenciales de la aplicación: configuradas', $tarjetas['gmail']->lineas);

        $todo = json_encode([$imap->lineas, $tarjetas['gmail']->lineas]);
        $this->assertStringNotContainsString('clave-ficticia', $todo);
        $this->assertStringNotContainsString('secreto-ficticio', $todo);
    }
}
