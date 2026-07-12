<?php

namespace Tests\Feature\Facturacion;

use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\User;
use Database\Seeders\DatosInicialesNegritaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Checklist "Preparar emisión real": pantalla SOLO de preparación. Verifica que
 * carga para gestores, muestra las advertencias de seguridad, NO expone ningún
 * endpoint/botón que emita o transmita, y que verla no mueve correlativos. El
 * backup solo-BD es admin-only. No toca la lógica de emisión real.
 */
class PreparacionProduccionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrador', 'facturacion', 'consulta', 'contador'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(string $rol): User
    {
        return User::factory()->create()->assignRole($rol);
    }

    public function test_administrador_carga_el_checklist_con_advertencias_de_seguridad(): void
    {
        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.preparar-produccion'))
            ->assertOk()
            ->assertSee('Preparar emisión real')
            ->assertSee('PARALELO SEGURO')
            ->assertSee('EMITIR PRODUCCION')                       // frase mostrada como recordatorio
            ->assertSee('No escribás esta frase si no vas a emitir de verdad')
            ->assertSee('Flujo recomendado');
    }

    public function test_facturacion_tambien_carga_el_checklist(): void
    {
        $this->actingAs($this->usuario('facturacion'))
            ->get(route('facturacion.preparar-produccion'))
            ->assertOk()
            ->assertSee('EMITIR PRODUCCION');
    }

    public function test_consulta_y_contador_no_acceden(): void
    {
        $this->actingAs($this->usuario('consulta'))
            ->get(route('facturacion.preparar-produccion'))
            ->assertForbidden();

        $this->actingAs($this->usuario('contador'))
            ->get(route('facturacion.preparar-produccion'))
            ->assertForbidden();
    }

    public function test_la_pantalla_no_expone_ningun_endpoint_de_emision_ni_transmision(): void
    {
        $html = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.preparar-produccion'))
            ->assertOk()
            ->getContent();

        // No hay formulario/botón que firme o transmita, ni el campo de la frase real.
        $this->assertStringNotContainsString('firmar-transmitir', $html);
        $this->assertStringNotContainsString('confirmacion_emision', $html);
        // El único POST de esta pantalla es el backup solo-BD.
        $this->assertStringNotContainsString('/dry-run', $html);
    }

    public function test_ver_el_checklist_no_mueve_correlativos_ni_emite(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);

        // Correlativo de CCF de producción (crea uno si el seeder no lo trae).
        $corr = Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->first();
        if (! $corr) {
            $corr = Correlativo::create([
                'tipo_dte' => '03',
                'establecimiento_id' => Establecimiento::firstOrFail()->id,
                'punto_venta_id' => null,
                'ambiente' => '01',
                'serie' => 'M001P001',
                'ultimo_numero' => 1078,
                'activo' => true,
            ]);
        }
        $antesNumero = $corr->ultimo_numero;
        $antesDtes = Dte::count();

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.preparar-produccion'))
            ->assertOk()
            ->assertSee((string) ($antesNumero + 1)); // próximo correlativo mostrado

        $corr->refresh();
        $this->assertSame($antesNumero, $corr->ultimo_numero); // no se movió
        $this->assertSame($antesDtes, Dte::count());           // no se emitió nada
    }

    public function test_muestra_barrera_anti_conta_correlativo_grande_y_worker(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        $corr = Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->first();
        if (! $corr) {
            $corr = Correlativo::create([
                'tipo_dte' => '03', 'establecimiento_id' => Establecimiento::firstOrFail()->id,
                'punto_venta_id' => null, 'ambiente' => '01', 'serie' => 'M001P001',
                'ultimo_numero' => 1078, 'activo' => true,
            ]);
        }
        $internoProximo = $corr->ultimo_numero + 1;       // 1079 (contador interno)

        $html = $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.preparar-produccion'))
            ->assertOk()
            // Las 3 cifras: interno 1078, externo (Conta) 1093, próximo operativo 1094.
            ->assertSee((string) $corr->ultimo_numero)     // 1078 interno
            ->assertSee('1093')                            // externo confirmado (default)
            ->assertSee('1094')                            // próximo operativo correcto
            // Aviso de desalineación (contador interno 1079 != operativo 1094).
            ->assertSee('desalineado', false)
            ->assertSee('alinear el correlativo', false)
            // Barrera anti-Conta con el texto dinámico nuevo.
            ->assertSee('Confirmo que Conta Portable quedó detenido/alineado', false)
            ->assertSee('último CCF real externo es 1093', false)
            ->assertSee('el próximo será 1094', false)
            // Higiene de configuración (solo reporte, no cambia .env).
            ->assertSee('Higiene de configuración')
            ->assertSee('no cambia', false)
            ->getContent();

        // El contador interno (1079) sigue visible pero marcado como desactualizado.
        $this->assertStringContainsString((string) $internoProximo, $html);
        // El worker en tests no late: debe avisar que los correos/jobs no saldrán.
        $this->assertStringContainsString('no saldrán', $html);
        // Sigue sin exponer el campo de la frase real de emisión.
        $this->assertStringNotContainsString('confirmacion_emision', $html);
    }

    public function test_ultimo_externo_configurable_actualiza_proximo_operativo(): void
    {
        $this->seed(DatosInicialesNegritaSeeder::class);
        if (! Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->exists()) {
            Correlativo::create([
                'tipo_dte' => '03', 'establecimiento_id' => Establecimiento::firstOrFail()->id,
                'punto_venta_id' => null, 'ambiente' => '01', 'serie' => 'M001P001',
                'ultimo_numero' => 1078, 'activo' => true,
            ]);
        }
        // Confirmar otro último externo (p. ej. Conta llegó a 1100) => próximo 1101,
        // SIN tocar el correlativo (solo una clave de configuración).
        \App\Models\Configuracion::set('produccion.ultimo_ccf_externo', '1100');

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.preparar-produccion'))
            ->assertOk()
            ->assertSee('1100')  // externo confirmado
            ->assertSee('1101'); // próximo operativo = externo + 1

        // No se movió el correlativo interno.
        $this->assertSame(1078, (int) Correlativo::where('tipo_dte', '03')->where('ambiente', '01')->value('ultimo_numero'));
    }

    public function test_el_backup_solo_bd_es_admin_only(): void
    {
        // Facturación (no admin) no puede generar backup.
        $this->actingAs($this->usuario('facturacion'))
            ->post(route('facturacion.preparar-produccion.backup'))
            ->assertForbidden();
    }

    public function test_backup_admin_corre_solo_bd_sin_notificaciones_y_no_emite(): void
    {
        // Se mockea el comando para no correr un backup real en el test; se verifica
        // que se invoque SOLO base de datos y SIN notificaciones (nada de correos).
        Artisan::shouldReceive('call')
            ->once()
            ->with('backup:run', ['--only-db' => true, '--disable-notifications' => true])
            ->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('Backup completed');

        $this->actingAs($this->usuario('administrador'))
            ->post(route('facturacion.preparar-produccion.backup'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, Dte::count()); // el backup no emite documentos
    }

    public function test_prueba_en_vivo_del_firmador_devuelve_json_sin_firmar(): void
    {
        // No se conecta a un firmador real: se simula el /status.
        Http::fake([
            rtrim((string) config('dte.firmador.url'), '/').'/status' => Http::response('OK', 200),
        ]);

        $this->actingAs($this->usuario('administrador'))
            ->get(route('facturacion.preparar-produccion.firmador'))
            ->assertOk()
            ->assertJson(['disponible' => true]);
    }
}
