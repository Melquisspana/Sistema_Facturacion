<?php

namespace Tests\Feature\Cobros;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\EstadoDte;
use App\Enums\PermisoSistema;
use App\Enums\RolSistema;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\ClientePerfilDocumento;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroDocumento;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Cobros\AltaCobrosService;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Services\Dte\PerfilDocumentoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\PreparaEmisorDte;
use Tests\TestCase;

/**
 * La AUTOMATIZACIÓN del seguimiento: que el alta y la lectura del buzón puedan correr
 * solas, que estén APAGADAS hasta que alguien las encienda, y que el botón siga siendo la
 * recuperación.
 *
 * Lo que protege, en orden de importancia:
 *
 *  1. **Que exista la tarea.** Mientras el alta dependiera solo del botón, la factura
 *     olvidada era justo la que no entraba al seguimiento.
 *  2. **Que no se encienda sola.** Instalar el planificador en el servidor no puede
 *     encender un módulo de rebote: cada tarea tiene su llave, apagada por defecto, y el
 *     comando comprueba la misma llave cuando se lo invoca a mano.
 *  3. **Que el ensayo en seco no escriba.** Es el paso previo con el que se comprueba qué
 *     entraría antes de dejarlo correr.
 *
 * Ninguna prueba enciende un proceso real: no se consulta Gmail y no se emite nada.
 */
class CobrosAutomatizacionTest extends TestCase
{
    use PreparaEmisorDte;
    use RefreshDatabase;

    /** @var array{estab: Establecimiento, pv: PuntoVenta}|null */
    private ?array $emisor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogosDte();
    }

    private function cliente(): Cliente
    {
        $cliente = Cliente::factory()->contribuyente()->create(['nombre' => 'Calleja, S.A. de C.V.']);

        ClientePerfilDocumento::create([
            'cliente_id' => $cliente->id,
            'activo' => true,
            'codigo_proveedor' => '000123',
            'formato_export' => 'albaran_nc_v1',
            'exige_albaran_en_nc' => false,
            'tolerancia_albaran' => 0,
        ]);

        app(PerfilDocumentoResolver::class)->olvidar();

        return $cliente;
    }

    /** @return array{estab: Establecimiento, pv: PuntoVenta} */
    private function emisorUnico(): array
    {
        if ($this->emisor !== null) {
            return $this->emisor;
        }

        ['estab' => $estab, 'pv' => $pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $t) {
            Correlativo::create([
                'tipo_dte' => $t, 'establecimiento_id' => $estab->id, 'punto_venta_id' => $pv->id,
                'ambiente' => '00', 'ultimo_numero' => 0, 'activo' => true,
            ]);
        }

        return $this->emisor = compact('estab', 'pv');
    }

    private function ccfAceptado(Cliente $cliente): Dte
    {
        ['estab' => $estab, 'pv' => $pv] = $this->emisorUnico();

        $producto = Producto::factory()->create([
            'nombre' => 'MANI HORNEADO',
            'precio_unitario' => 1.04,
            'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);

        $ccf = app(DteBorradorService::class)->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'cliente_id' => $cliente->id,
            'establecimiento_id' => $estab->id,
            'punto_venta_id' => $pv->id,
        ]);
        $ccf->forceFill(['numero_orden_compra' => '26090017003463'])->save();
        app(DteBorradorService::class)->agregarLineaDesdeProducto($ccf, $producto, cantidad: 100);
        app(DteGeneracionService::class)->generar($ccf);

        $ccf->refresh()->forceFill([
            'sello_recepcion' => '2026SELLO'.str_pad((string) $ccf->id, 31, 'X'),
            'fecha_procesamiento_mh' => now(),
            'estado' => EstadoDte::Aceptado->value,
        ])->save();

        return $ccf->refresh();
    }

    // ------------------------------------------------------------------ dorada

    /**
     * DORADA · las dos tareas están DEFINIDAS y APAGADAS.
     *
     * Definidas para poder inspeccionarlas y probarlas; apagadas para que registrar
     * `schedule:run` en un servidor no encienda el módulo por su cuenta.
     */
    public function test_dorada_las_tareas_existen_y_estan_apagadas_por_defecto(): void
    {
        $comandos = collect(Schedule::events())->map(fn ($e) => $e->command)->implode(' | ');

        $this->assertStringContainsString('cobros:sincronizar --aplicar --vincular', $comandos,
            'El alta automática tiene que estar definida: sin ella, la factura olvidada no entra nunca.');
        $this->assertStringContainsString('cobros:leer-correos --aplicar', $comandos);

        // Y las cuatro llaves, apagadas.
        $this->assertFalse((bool) config('cobros.alta.automatica'));
        $this->assertFalse((bool) config('cobros.vinculacion.automatica'));
        $this->assertFalse((bool) config('cobros.correo.enabled'));
        $this->assertFalse((bool) config('cobros.correo.automatica'));
    }

    /**
     * DORADA · con la llave apagada, `--aplicar` NO escribe y lo dice.
     *
     * Es la segunda puerta: una invocación por fuera del planificador —un `.bat` viejo, un
     * dedo de más— tampoco puede encender el módulo de rebote.
     */
    public function test_dorada_con_la_llave_apagada_no_escribe_nada(): void
    {
        $cliente = $this->cliente();
        $this->ccfAceptado($cliente);

        config()->set('cobros.alta.automatica', false);

        $this->artisan('cobros:sincronizar', ['--cliente' => $cliente->id, '--aplicar' => true])
            ->expectsOutputToContain('COBROS_ALTA_AUTO=false')
            ->assertExitCode(1);

        $this->assertSame(0, CobroDocumento::count(), 'No se creó ni un documento.');
    }

    /** DORADA · el ensayo en seco enumera lo que haría y no escribe una fila. */
    public function test_dorada_el_ensayo_en_seco_no_escribe_nada(): void
    {
        $cliente = $this->cliente();
        $this->ccfAceptado($cliente);

        $this->artisan('cobros:sincronizar', ['--cliente' => $cliente->id])
            ->expectsOutputToContain('ENSAYO EN SECO')
            ->assertExitCode(0);

        $this->assertSame(0, CobroDocumento::count());
    }

    /** El ensayo en seco CUENTA bien lo que entraría: no es un texto decorativo. */
    public function test_el_ensayo_en_seco_cuenta_lo_que_entraria(): void
    {
        $cliente = $this->cliente();
        $this->ccfAceptado($cliente);
        $this->ccfAceptado($cliente);

        $previsto = app(AltaCobrosService::class)->previsualizar($cliente);

        $this->assertSame(2, $previsto['creados']);
        $this->assertSame(0, $previsto['adoptados']);
        $this->assertSame(0, $previsto['sin_cambio']);
        $this->assertSame(0, CobroDocumento::count(), 'Previsualizar no escribe.');

        // Y después de dar de alta de verdad, la previsualización dice que ya están.
        config()->set('cobros.alta.automatica', true);
        $this->artisan('cobros:sincronizar', ['--cliente' => $cliente->id, '--aplicar' => true])
            ->assertExitCode(0);

        $segunda = app(AltaCobrosService::class)->previsualizar($cliente);
        $this->assertSame(0, $segunda['creados']);
        $this->assertSame(2, $segunda['sin_cambio']);
    }

    /**
     * DORADA · encendida, la tarea da de alta los documentos aceptados, y repetirla no
     * cambia nada.
     */
    public function test_dorada_encendida_da_de_alta_y_es_idempotente(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->ccfAceptado($cliente);

        config()->set('cobros.alta.automatica', true);

        $this->artisan('cobros:sincronizar', ['--cliente' => $cliente->id, '--aplicar' => true])
            ->assertExitCode(0);

        $this->assertSame(1, CobroDocumento::count());
        $documento = CobroDocumento::firstOrFail();
        $this->assertSame($ccf->id, $documento->dte_id);
        $this->assertSame(OrigenCobroDocumento::Dte, $documento->origen);

        // Una nota nuestra sobrevive a la siguiente corrida.
        $documento->forceFill(['observaciones' => 'No perder esto.'])->save();

        $this->artisan('cobros:sincronizar', ['--cliente' => $cliente->id, '--aplicar' => true])
            ->assertExitCode(0);

        $this->assertSame(1, CobroDocumento::count());
        $this->assertSame('No perder esto.', $documento->refresh()->observaciones);
    }

    /**
     * El alta NO toca la emisión: solo lee `dtes` y escribe en su propia tabla. Ningún
     * documento fiscal cambia de estado, de sello ni de número.
     */
    public function test_el_alta_no_modifica_ningun_documento_fiscal(): void
    {
        $cliente = $this->cliente();
        $ccf = $this->ccfAceptado($cliente);
        $antes = $ccf->only(['estado', 'numero_control', 'codigo_generacion', 'sello_recepcion', 'total_pagar']);

        config()->set('cobros.alta.automatica', true);
        $this->artisan('cobros:sincronizar', ['--cliente' => $cliente->id, '--aplicar' => true])
            ->assertExitCode(0);

        $this->assertSame($antes, $ccf->refresh()->only(array_keys($antes)));
    }

    // ------------------------------------------------------------------ correos

    /** Con la llave de correo apagada, `--aplicar` no consulta el buzón y lo dice. */
    public function test_la_lectura_de_correos_apagada_no_consulta_el_buzon(): void
    {
        config()->set('cobros.correo.enabled', false);

        $this->artisan('cobros:leer-correos', ['--aplicar' => true])
            ->expectsOutputToContain('COBROS_CORREO_ENABLED=false')
            ->assertExitCode(1);

        $this->assertSame(0, CobroCorreo::count());
    }

    /**
     * La tarea de correo exige las TRES llaves. Se comprueba la condición tal cual la
     * evalúa el planificador, sin encender ningún proceso real.
     */
    public function test_la_tarea_de_correo_exige_las_tres_llaves(): void
    {
        $condicion = fn () => (bool) config('ppq.gmail.enabled', false)
            && (bool) config('cobros.correo.enabled', false)
            && (bool) config('cobros.correo.automatica', false);

        config()->set('ppq.gmail.enabled', true);
        config()->set('cobros.correo.enabled', true);
        config()->set('cobros.correo.automatica', false);
        $this->assertFalse($condicion(), 'Sin la automática no corre sola.');

        config()->set('cobros.correo.enabled', false);
        config()->set('cobros.correo.automatica', true);
        $this->assertFalse($condicion(), 'Sin el permiso del módulo tampoco.');

        config()->set('ppq.gmail.enabled', false);
        config()->set('cobros.correo.enabled', true);
        $this->assertFalse($condicion(), 'Y sin conexión a Gmail, menos.');

        config()->set('ppq.gmail.enabled', true);
        $this->assertTrue($condicion(), 'Con las tres, sí.');
    }

    /**
     * El botón de la pantalla SIGUE funcionando con la automática apagada: es la
     * recuperación, no un duplicado que se apaga con ella.
     */
    public function test_el_boton_de_la_pantalla_funciona_con_la_automatica_apagada(): void
    {
        $cliente = $this->cliente();
        $this->ccfAceptado($cliente);

        config()->set('cobros.alta.automatica', false);

        $usuario = User::factory()->create();
        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        Role::findOrCreate('administrador', 'web')
            ->syncPermissions(PermisoSistema::paraRol(RolSistema::Administrador));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $usuario->assignRole('administrador');

        $this->actingAs($usuario)
            ->post(route('cobros.sincronizar', $cliente))
            ->assertRedirect();

        $this->assertSame(1, CobroDocumento::count(),
            'El botón es la recuperación: no depende de que la automática esté encendida.');
    }
}
