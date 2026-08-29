<?php

namespace Tests\Feature\Ppq;

use App\Enums\OrigenDescuentoNc;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoNotaCredito;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\ClientePerfilTipoNc;
use App\Models\NcExportacion;
use App\Models\User;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Quién puede QUÉ en las pantallas nuevas.
 *
 * Se prueban las tres puertas por separado porque son tres decisiones distintas y es fácil
 * confundirlas al leer el código: MIRAR y DESCARGAR el formato basta con `ppq.ver`;
 * CREAR el lote —que marca documentos como exportados y ya no vuelven atrás— exige
 * `ppq.gestionar`; y CONFIGURAR el perfil documental, que decide el descuento de las notas
 * de crédito, exige `clientes.gestionar`, el mismo permiso que ya protege
 * `descuento_global_default` en el formulario del cliente.
 *
 * Los roles se siembran desde el catálogo real ({@see PermisoSistema}) en vez de a mano:
 * así, si mañana alguien le da `ppq.gestionar` a jefatura, esta prueba lo refleja en vez de
 * seguir verde contra un reparto inventado.
 */
class NcExportacionAutorizacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        foreach (RolSistema::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web')->syncPermissions(PermisoSistema::paraRol($rol));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function usuario(RolSistema $rol): User
    {
        return User::factory()->create()->assignRole($rol->value);
    }

    private function clienteConPerfil(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create();

        $perfil = ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '001065',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);

        ClientePerfilTipoNc::create([
            'cliente_perfil_documento_id' => $perfil->id,
            'tipo_nota_credito' => TipoNotaCredito::Averia->value,
            'codigo_externo' => 'AC02',
            'descuento_origen' => OrigenDescuentoNc::Ccf->value,
        ]);

        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    /** Lote vacío, suficiente para probar la puerta de la descarga. */
    private function lote(Cliente $cliente): NcExportacion
    {
        return NcExportacion::create([
            'cliente_id' => $cliente->id,
            'referencia' => 'NC-001065-'.now()->format('Ymd').'-01',
            'formato' => 'albaran_nc_v1',
            'archivo_nombre' => '001065'.now()->format('YmdHi').'.xlsx',
        ]);
    }

    // ---------------------------------------------------------------- ver

    /** @return array<int, array{0: RolSistema}> */
    public static function rolesConPpqVer(): array
    {
        return [
            'administrador' => [RolSistema::Administrador],
            'facturación' => [RolSistema::Facturacion],
            'jefatura' => [RolSistema::Jefatura],
            'contabilidad' => [RolSistema::Contabilidad],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rolesConPpqVer')]
    public function test_la_bandeja_se_ve_con_ppq_ver(RolSistema $rol): void
    {
        $usuario = $this->usuario($rol);
        $this->assertTrue($usuario->can(PermisoSistema::PpqVer->value), "{$rol->value} debería tener ppq.ver");

        $this->actingAs($usuario)
            ->get(route('ppq.nc-exportaciones.index'))
            ->assertOk()
            ->assertSee('Formato de notas de crédito');
    }

    public function test_sin_ppq_ver_la_bandeja_da_403(): void
    {
        // Producción está deliberadamente aislada del área fiscal (ver PermisoSistema).
        $usuario = $this->usuario(RolSistema::Produccion);
        $this->assertFalse($usuario->can(PermisoSistema::PpqVer->value));

        $this->actingAs($usuario)
            ->get(route('ppq.nc-exportaciones.index'))
            ->assertForbidden();
    }

    public function test_la_bandeja_exige_sesion(): void
    {
        $this->get(route('ppq.nc-exportaciones.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- descargar

    public function test_descargar_basta_con_ppq_ver(): void
    {
        $cliente = $this->clienteConPerfil();
        $lote = $this->lote($cliente);

        // Jefatura es SOLO lectura: no tiene ppq.gestionar, pero sí puede bajar el archivo.
        $usuario = $this->usuario(RolSistema::Jefatura);
        $this->assertFalse($usuario->can(PermisoSistema::PpqGestionar->value));

        $this->actingAs($usuario)
            ->get(route('ppq.nc-exportaciones.descargar', $lote))
            ->assertOk()
            ->assertDownload($lote->archivo_nombre);
    }

    public function test_sin_ppq_ver_no_se_puede_descargar(): void
    {
        $lote = $this->lote($this->clienteConPerfil());

        $this->actingAs($this->usuario(RolSistema::Produccion))
            ->get(route('ppq.nc-exportaciones.descargar', $lote))
            ->assertForbidden();
    }

    /**
     * Descargar deja constancia (estado y contador) pero NO marca el lote como enviado ni
     * toca los documentos que contiene.
     */
    public function test_descargar_registra_la_descarga_sin_marcar_el_lote_como_enviado(): void
    {
        $lote = $this->lote($this->clienteConPerfil());
        $this->assertSame(\App\Enums\EstadoNcExportacion::Generado, $lote->estado);
        $this->assertSame(0, $lote->descargas);

        $usuario = $this->usuario(RolSistema::Administrador);
        $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.descargar', $lote))->assertOk();
        $this->actingAs($usuario)->get(route('ppq.nc-exportaciones.descargar', $lote))->assertOk();

        $lote->refresh();
        $this->assertSame(\App\Enums\EstadoNcExportacion::Descargado, $lote->estado);
        $this->assertSame(2, $lote->descargas);
        $this->assertNotNull($lote->descargado_en);
        // No existe ningún estado «enviado» que un lote pueda alcanzar por descargarse.
        $this->assertNotContains('enviado', array_column(\App\Enums\EstadoNcExportacion::cases(), 'value'));
        // Y bajarlo dos veces no agregó ni quitó documentos.
        $this->assertSame(0, $lote->items()->count());
    }

    // ---------------------------------------------------------------- crear lote

    public function test_crear_lote_exige_ppq_gestionar(): void
    {
        $cliente = $this->clienteConPerfil();

        // Jefatura y contabilidad ven, pero no gestionan.
        foreach ([RolSistema::Jefatura, RolSistema::Contabilidad] as $rol) {
            $usuario = $this->usuario($rol);
            $this->assertTrue($usuario->can(PermisoSistema::PpqVer->value));
            $this->assertFalse($usuario->can(PermisoSistema::PpqGestionar->value));

            $this->actingAs($usuario)
                ->post(route('ppq.nc-exportaciones.store'), [
                    'cliente_id' => $cliente->id,
                    'dtes' => [1],
                ])
                ->assertForbidden();
        }

        $this->assertSame(0, NcExportacion::count());
    }

    /**
     * Ocultar el botón no autoriza nada —la puerta real es el middleware, probado arriba—,
     * pero ofrecer una acción que va a devolver 403 es una mentira de interfaz.
     */
    public function test_el_boton_de_crear_lote_no_se_dibuja_sin_ppq_gestionar(): void
    {
        $cliente = $this->clienteConPerfil();

        $this->actingAs($this->usuario(RolSistema::Jefatura))
            ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]))
            ->assertOk()
            ->assertDontSee('Generar formato con las marcadas');

        // Y sí se dibuja para quien sí puede.
        $this->actingAs($this->usuario(RolSistema::Administrador))
            ->get(route('ppq.nc-exportaciones.index', ['cliente_id' => $cliente->id]))
            ->assertOk()
            ->assertSee('3 · Formatos generados');
    }

    // ---------------------------------------------------------------- perfil del cliente

    public function test_configurar_el_perfil_exige_clientes_gestionar(): void
    {
        $cliente = $this->clienteConPerfil();

        // Facturación VE clientes pero no los gestiona: no puede tocar el perfil, que
        // decide el descuento de las notas de crédito.
        $usuario = $this->usuario(RolSistema::Facturacion);
        $this->assertTrue($usuario->can(PermisoSistema::ClientesVer->value));
        $this->assertFalse($usuario->can(PermisoSistema::ClientesGestionar->value));

        $this->actingAs($usuario)
            ->get(route('clientes.perfil-documento.edit', $cliente))
            ->assertForbidden();

        $this->actingAs($usuario)
            ->put(route('clientes.perfil-documento.update', $cliente), ['tolerancia_albaran' => 5])
            ->assertForbidden();

        // El perfil quedó intacto.
        $this->assertSame('0.00', $cliente->perfilDocumento->refresh()->tolerancia_albaran);
    }

    public function test_el_administrador_configura_el_perfil(): void
    {
        $cliente = $this->clienteConPerfil();

        $this->actingAs($this->usuario(RolSistema::Administrador))
            ->get(route('clientes.perfil-documento.edit', $cliente))
            ->assertOk()
            ->assertSee('Perfil documental');

        $this->actingAs($this->usuario(RolSistema::Administrador))
            ->put(route('clientes.perfil-documento.update', $cliente), [
                'activo' => '1',
                'codigo_proveedor' => '001065',
                'formato_export' => 'albaran_nc_v1',
                'exige_albaran_en_nc' => '1',
                'tolerancia_albaran' => '0.05',
                'modalidades' => [
                    TipoNotaCredito::Averia->value => [
                        'usar' => '1', 'codigo_externo' => 'ac02',
                        'descuento_origen' => OrigenDescuentoNc::Ccf->value,
                    ],
                    TipoNotaCredito::DevolucionProducto->value => [
                        'usar' => '1', 'codigo_externo' => 'AC04',
                        'descuento_origen' => OrigenDescuentoNc::Ninguno->value,
                    ],
                ],
            ])
            ->assertRedirect(route('clientes.show', $cliente))
            ->assertSessionHasNoErrors();

        $perfil = $cliente->perfilDocumento()->with('tiposNc')->first();
        $this->assertTrue($perfil->activo);
        $this->assertTrue($perfil->exige_albaran_en_nc);
        $this->assertSame('0.05', $perfil->tolerancia_albaran);
        $this->assertCount(2, $perfil->tiposNc);
        // El código se guarda canónico en mayúsculas aunque se escriba en minúsculas.
        $this->assertSame('AC02', $perfil->reglaPara(TipoNotaCredito::Averia)->codigo_externo);
        $this->assertSame(
            OrigenDescuentoNc::Ninguno,
            $perfil->reglaPara(TipoNotaCredito::DevolucionProducto)->descuento_origen
        );
    }

    public function test_desmarcar_una_modalidad_la_devuelve_al_criterio_historico(): void
    {
        $cliente = $this->clienteConPerfil();
        $this->assertNotNull($cliente->perfilDocumento->reglaPara(TipoNotaCredito::Averia));

        $this->actingAs($this->usuario(RolSistema::Administrador))
            ->put(route('clientes.perfil-documento.update', $cliente), [
                'activo' => '1',
                'tolerancia_albaran' => '0',
                'modalidades' => [
                    TipoNotaCredito::Averia->value => ['usar' => '0', 'codigo_externo' => 'AC02'],
                ],
            ])
            ->assertRedirect();

        $this->assertCount(0, $cliente->perfilDocumento()->with('tiposNc')->first()->tiposNc);
    }

    public function test_tasa_propia_sin_tasa_se_rechaza(): void
    {
        $cliente = $this->clienteConPerfil();

        $this->actingAs($this->usuario(RolSistema::Administrador))
            ->put(route('clientes.perfil-documento.update', $cliente), [
                'tolerancia_albaran' => '0',
                'modalidades' => [
                    TipoNotaCredito::Averia->value => [
                        'usar' => '1', 'codigo_externo' => 'AC02',
                        'descuento_origen' => OrigenDescuentoNc::TasaPropia->value,
                    ],
                ],
            ])
            ->assertSessionHasErrors('modalidades.averia.descuento_tasa');
    }

    public function test_formato_de_exportacion_inexistente_se_rechaza(): void
    {
        $cliente = $this->clienteConPerfil();

        $this->actingAs($this->usuario(RolSistema::Administrador))
            ->put(route('clientes.perfil-documento.update', $cliente), [
                'tolerancia_albaran' => '0',
                'formato_export' => 'formato_que_no_existe',
            ])
            ->assertSessionHasErrors('formato_export');
    }

    public function test_la_ficha_del_cliente_muestra_el_perfil_a_quien_solo_lee(): void
    {
        $cliente = $this->clienteConPerfil();

        $this->actingAs($this->usuario(RolSistema::Facturacion))
            ->get(route('clientes.show', $cliente))
            ->assertOk()
            ->assertSee('Perfil documental')
            ->assertSee('AC02')
            // Ver sí; el enlace para configurarlo, no.
            ->assertDontSee(route('clientes.perfil-documento.edit', $cliente));
    }
}
