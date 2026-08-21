<?php

namespace Tests\Feature\Configuracion;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\Editabilidad;
use App\Ajustes\Definicion\Persistencia;
use App\Ajustes\Excepciones\AjusteNoEditableException;
use App\Ajustes\Fiscal\AjusteFiscal;
use App\Ajustes\Fiscal\ClasificacionFiscal;
use App\Ajustes\Fiscal\InventarioFiscal;
use App\Facades\Ajustes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El inventario fiscal y las dos pantallas que solo listan (Parámetros fiscales e
 * Invalidación).
 *
 * La garantía transversal de toda esta fase, y la que más fácil se rompe sin
 * darse cuenta: NINGÚN ajuste fiscal tiene dónde escribirse. Declararlos en el
 * catálogo les da etiqueta, descripción y fuente — no les abre una puerta. El
 * test recorre las tres secciones enteras en vez de una lista escrita a mano,
 * para que un ajuste nuevo mal clasificado falle el día que se declara y no seis
 * meses después.
 */
class InventarioFiscalTest extends TestCase
{
    use RefreshDatabase;

    /** Secciones del catálogo que componen el bloque fiscal. */
    private const SECCIONES = ['fiscal', 'fiscal_parametros', 'fiscal_invalidacion'];

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrador', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    // -------------------------------------------------------------- catálogo

    public function test_ningun_ajuste_fiscal_puede_escribirse(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        foreach (self::SECCIONES as $seccion) {
            foreach ($catalogo->porSeccion($seccion) as $clave => $definicion) {
                $this->assertFalse(
                    $definicion->editabilidad->permiteEscritura(),
                    "«{$clave}» quedó abierto a escritura y ningún ajuste fiscal debería estarlo todavía."
                );
                $this->assertSame(
                    Persistencia::Ninguna,
                    $definicion->persistencia,
                    "«{$clave}» declara dónde guardarse; en esta fase no debe tener dónde."
                );
            }
        }
    }

    /**
     * La distinción que el enum `Editabilidad` no puede expresar por sí solo: un
     * ajuste marcado como SoloLectura no va a abrirse nunca, y uno marcado como
     * Futura sí. Si se confunden, la pantalla promete cosas que no van a pasar.
     */
    public function test_los_ajustes_de_solo_lectura_son_los_de_valor_legal(): void
    {
        $catalogo = app(CatalogoAjustes::class);
        $soloLectura = [];

        foreach (self::SECCIONES as $seccion) {
            foreach ($catalogo->porSeccion($seccion) as $clave => $definicion) {
                if ($definicion->editabilidad === Editabilidad::SoloLectura) {
                    $soloLectura[] = $clave;
                }
            }
        }

        sort($soloLectura);

        $this->assertSame([
            'dte.factura_consumidor_final.receptor_obligatorio_desde',
            'dte.invalidacion.version',
            'dte.iva_tasa',
            'dte.retencion_iva_tasa',
        ], $soloLectura, 'Cambió qué parámetros fiscales son definitivamente inmutables. Es una decisión, no un detalle.');
    }

    /** Ni siquiera un administrador puede escribir uno de los candados nuevos. */
    public function test_un_candado_no_puede_escribirse_ni_como_administrador(): void
    {
        $this->actingAs($this->admin());

        $this->expectException(AjusteNoEditableException::class);

        Ajustes::guardar('dte.transmision.dry_run', false);
    }

    public function test_las_credenciales_del_ministerio_no_estan_en_el_catalogo(): void
    {
        $catalogo = app(CatalogoAjustes::class);

        // No están declaradas a propósito: el catálogo es una lista blanca, y lo que
        // no está en ella no se lee ni se escribe por esta capa.
        foreach ([
            'dte.transmision.usuario_produccion',
            'dte.transmision.password_produccion',
            'dte.transmision.usuario_testing',
            'dte.transmision.password_testing',
            'dte.transmision.token',
            'dte.firma.cert_password',
        ] as $clave) {
            $this->assertFalse($catalogo->existe($clave), "«{$clave}» no debe ser administrable desde la web.");
        }
    }

    // ------------------------------------------------------------ inventario

    public function test_el_candado_de_ensayo_avisa_cuando_esta_apagado_no_cuando_esta_encendido(): void
    {
        $inventario = app(InventarioFiscal::class);

        // Con el ensayo ACTIVO no hay nada que avisar: es la red de seguridad.
        config(['dte.transmision.dry_run' => true]);
        $encendido = $this->candado($inventario, 'Transmisión', 'Modo de ensayo (dry-run)');
        $this->assertFalse($encendido->atencion);

        // Apagarlo es lo que quita la red, y ahí sí.
        config(['dte.transmision.dry_run' => false]);
        $apagado = $this->candado($inventario, 'Transmisión', 'Modo de ensayo (dry-run)');
        $this->assertTrue($apagado->atencion);
    }

    public function test_el_conteo_de_candados_abiertos_sigue_a_la_configuracion(): void
    {
        $inventario = app(InventarioFiscal::class);

        config([
            'dte.firma.enabled' => false,
            'dte.firma.mock' => false,
            'dte.transmision.enabled' => false,
            'dte.transmision.mock' => false,
            'dte.transmision.real_confirmation' => false,
            'dte.transmision.allow_production' => false,
            'dte.transmision.dry_run' => true,
            'dte.transmision.test_enabled' => false,
            'dte.transmision.sistema_actual_activo' => true,
            'dte.transmision.modo_operacion' => 'paralelo',
            'dte.transmision.auth_test_real_enabled' => false,
            'dte.transmision.auth_test_prod_enabled' => false,
            'dte.invalidacion.mock' => false,
            'dte.invalidacion.real_confirmation' => false,
            'dte.invalidacion.produccion_enabled' => false,
        ]);

        $cerrado = $inventario->resumenCandados();
        $this->assertSame(0, $cerrado['abiertos']);
        $this->assertGreaterThan(10, $cerrado['total']);

        config(['dte.transmision.enabled' => true, 'dte.transmision.mock' => true]);
        $this->assertSame(2, $inventario->resumenCandados()['abiertos']);
    }

    public function test_la_contrasena_del_certificado_se_clasifica_como_solo_del_servidor(): void
    {
        $filas = app(InventarioFiscal::class)->firmador();
        $password = collect($filas)->firstWhere('etiqueta', 'Contraseña del certificado');

        $this->assertNotNull($password);
        $this->assertSame(ClasificacionFiscal::SoloServidor, $password->clasificacion);
        $this->assertFalse($password->clasificacion->abrirseAlgunDia());
    }

    /**
     * La lista de configuración muerta no es decorativa: es lo que impide que
     * alguien edite una clave sin consumidor creyendo que hace algo. Se comprueba
     * que sigue nombrando los duplicados conocidos.
     */
    public function test_el_inventario_nombra_la_configuracion_sin_efecto(): void
    {
        $claves = array_column(app(InventarioFiscal::class)->configuracionMuerta(), 'clave');

        $this->assertContains('dte.correlativo.formato', $claves);
        $this->assertContains('dte.json.invalidacion_version', $claves);
        $this->assertContains('dte.firma.driver', $claves);
    }

    // -------------------------------------------------------------- pantallas

    public function test_la_pantalla_de_parametros_carga(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/parametros')
            ->assertOk()
            ->assertSee('Parámetros fiscales')
            ->assertSee('Tasa de IVA');
    }

    public function test_la_pantalla_de_invalidacion_carga(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get('/configuracion/facturacion-electronica/invalidacion')
            ->assertOk()
            ->assertSee('Candados de la invalidación', escape: false);
    }

    /**
     * «Es de solo lectura» se comprueba en las RUTAS, no rastreando el HTML: el
     * marco de la aplicación trae sus propios formularios (cerrar sesión, buscar) y
     * buscar la cadena «<form» en la respuesta solo probaría eso. Lo que importa es
     * que no exista ningún verbo de escritura al que apuntar.
     */
    #[DataProvider('pantallasDeListado')]
    public function test_las_pantallas_de_listado_no_aceptan_escritura(string $pantalla): void
    {
        $url = '/configuracion/facturacion-electronica/'.$pantalla;

        foreach (['put', 'patch', 'post', 'delete'] as $verbo) {
            $this->actingAs($this->admin())->$verbo($url)->assertStatus(405);
        }
    }

    #[DataProvider('pantallasDeListado')]
    public function test_un_visitante_sin_sesion_va_al_login(string $pantalla): void
    {
        $this->get('/configuracion/facturacion-electronica/'.$pantalla)->assertRedirect('/login');
    }

    #[DataProvider('pantallasDeListado')]
    public function test_un_usuario_sin_permiso_no_entra(string $pantalla): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/configuracion/facturacion-electronica/'.$pantalla)
            ->assertForbidden();
    }

    public static function pantallasDeListado(): array
    {
        return [
            'parámetros fiscales' => ['parametros'],
            'invalidación' => ['invalidacion'],
        ];
    }

    // ---------------------------------------------------------------- ayudas

    private function candado(InventarioFiscal $inventario, string $grupo, string $etiqueta): AjusteFiscal
    {
        return collect($inventario->candados()[$grupo])->firstWhere('etiqueta', $etiqueta);
    }
}
