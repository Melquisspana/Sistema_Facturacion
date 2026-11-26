<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Pago;
use App\Models\Gastos\Regla;
use App\Models\User;
use App\Services\Gastos\Recurrencia\AdministrarReglas;
use App\Services\Gastos\Recurrencia\GenerarObligaciones;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Gastos › Por pagar: cuando el servidor rechaza un formulario, vuelve abierto y con lo
 * escrito, como ya hacía «Saltar».
 *
 * Antes volvían cerrados y vacíos: el error se leía arriba, pero había que buscar la
 * fila, abrirla y escribir todo de nuevo. Todas las filas usan los mismos nombres de
 * campo (fecha, metodo, importe…), así que lo escrito vuelve SOLO al formulario que lo
 * mandó; la otra fila sigue con sus valores por defecto. Datos ficticios.
 */
class PanelConservaLoEscritoTest extends TestCase
{
    use RefreshDatabase;

    private const HOY = '2026-09-25';

    private const FOCO = '$nextTick(() => $el.focus())';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['gastos.enabled' => true]);
        $this->travelTo(CarbonImmutable::parse(self::HOY.' 10:00'));
    }

    private function operador(): User
    {
        $u = User::factory()->create(['activo' => true]);
        $u->syncPermissions(array_map(fn (PermisoSistema $p) => $p->value, [
            PermisoSistema::GastosVer, PermisoSistema::GastosRegistrar, PermisoSistema::GastosPersonales,
            PermisoSistema::GastosPagosRegistrar, PermisoSistema::GastosRecurrencias,
        ]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** Una deuda sin fecha: «compra» se abona, cualquier otra se paga entera. */
    private function deuda(User $u, string $proveedor, string $importe, string $naturaleza): Gasto
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(),
            'huella_peticion' => hash('sha256', $proveedor.$importe),
            'beneficiario' => $proveedor,
            'concepto' => 'Saldo de prueba',
            'categoria' => 'Productos',
            'ambito' => 'empresarial',
            'naturaleza' => $naturaleza,
            'moneda' => 'USD',
            'importe' => $importe,
            'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'registrado_por' => $u->id,
        ]);
        $gasto->cuotas()->create(['numero' => 1, 'importe' => $importe, 'vence' => null]);

        return $gasto->fresh();
    }

    /** Una obligación que espera su recibo: existe, pero todavía sin importe ni cuotas. */
    private function sinMonto(User $u, string $proveedor, string $naturaleza): Gasto
    {
        return Gasto::create([
            'clave' => (string) Str::uuid(),
            'huella_peticion' => hash('sha256', $proveedor.$naturaleza),
            'beneficiario' => $proveedor,
            'concepto' => 'Recibo de prueba',
            'categoria' => 'Servicios',
            'ambito' => 'empresarial',
            'naturaleza' => $naturaleza,
            'moneda' => 'USD',
            'importe' => null,
            'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'registrado_por' => $u->id,
        ])->fresh();
    }

    /** Una regla de monto variable que vence el 28: sin generar es una fila programada. */
    private function reglaVariable(User $u, string $proveedor): Regla
    {
        return app(AdministrarReglas::class)->crear($u, [
            'clave' => (string) Str::uuid(),
            'nombre' => 'Luz de '.$proveedor,
            'beneficiario' => $proveedor,
            'concepto' => 'Luz de prueba',
            'categoria' => 'Servicios',
            'ambito' => 'empresarial',
            'naturaleza' => 'operativo',
            'moneda' => 'USD',
            'documentacion' => 'pendiente',
            'responsable_id' => $u->id,
            'monto_modo' => 'variable',
            'importe' => null,
            'frecuencia' => 'mensual',
            'dia_mes' => 28,
            'dias_generar_antes' => 5,
            'vigente_desde' => '2026-09-01',
        ]);
    }

    /** Manda el formulario desde el panel, comprueba que vuelve ahí con error y devuelve la pantalla de vuelta. */
    private function volverConError(User $u, string $url, array $datos): string
    {
        $this->actingAs($u)->from(route('gastos.panel'))->post($url, $datos)
            ->assertRedirect(route('gastos.panel'))
            ->assertSessionHasErrors();

        return $this->actingAs($u)->get(route('gastos.panel'))->assertOk()->getContent();
    }

    /** El HTML del formulario que lleva el campo oculto de ese panel. */
    private function formularioDe(string $html, string $panel): string
    {
        return $this->formularioCon($html, 'name="panel" value="'.$panel.'"', 'El formulario de «'.$panel.'» no avisa qué panel reabrir.');
    }

    /** El HTML del formulario que contiene ese fragmento. */
    private function formularioCon(string $html, string $fragmento, string $siFalta = ''): string
    {
        $marca = strpos($html, $fragmento);
        $this->assertNotFalse($marca, $siFalta ?: 'Ningún formulario contiene: '.$fragmento);
        $inicio = strrpos(substr($html, 0, $marca), '<form');
        $fin = strpos($html, '</form>', $marca);

        return substr($html, $inicio, $fin - $inicio);
    }

    /** Lo que el navegador manda en «panel» desde el formulario con esa dirección. */
    private function panelQueManda(string $html, string $accion): string
    {
        $formulario = $this->formularioCon($html, 'action="'.$accion.'"');
        $this->assertSame(1, preg_match('/name="panel" value="([^"]+)"/', $formulario, $m));

        return $m[1];
    }

    /** Vuelve abierto ese panel y un solo campo de toda la página recibe el foco: el indicado. */
    private function assertReabierto(string $html, string $panel, string $idConFoco): void
    {
        $this->assertStringContainsString("abierto: '".$panel."'", html_entity_decode($html));
        $this->assertSame(1, substr_count($html, self::FOCO), 'Un solo campo de la página recibe el foco.');
        $this->assertMatchesRegularExpression('/id="'.preg_quote($idConFoco, '/').'"[^<]*x-init="'.preg_quote(self::FOCO, '/').'"/', $html);
    }

    /**
     * El aviso va justo debajo de su campo y está enlazado a él: al llegar el foco, el lector
     * de pantalla dice que el campo está mal y lee el aviso. Solo se marca el que falló.
     */
    private function assertErrorDebajo(string $formulario, string $id): void
    {
        $aviso = preg_quote($id.'-error', '/');
        $this->assertMatchesRegularExpression('/id="'.preg_quote($id, '/').'"[^<]*aria-invalid="true" aria-describedby="'.$aviso.'"[^<]*>\s*<p id="'.$aviso.'" class="mt-1 text-sm text-red-600">/', $formulario);
        $this->assertSame(1, substr_count($formulario, 'aria-invalid="true"'), 'Solo el campo que falló se marca.');
    }

    /** Lo escrito vuelve una sola vez en la página, y es en ese formulario. */
    private function assertUnaVezEn(string $html, string $formulario, string $fragmento): void
    {
        $this->assertSame(1, substr_count($html, $fragmento), 'Aparece en más de un formulario, o en ninguno: '.$fragmento);
        $this->assertStringContainsString($fragmento, $formulario);
    }

    public function test_compre_y_pague_vuelve_abierto_con_lo_escrito(): void
    {
        $u = $this->operador();
        $a = $this->deuda($u, 'Proveedor de prueba A', '300.00', 'compra');
        $b = $this->deuda($u, 'Proveedor de prueba B', '60.00', 'operativo');
        $clave = (string) Str::uuid();

        $html = $this->volverConError($u, route('gastos.panel.compras'), [
            'clave' => $clave, 'moneda' => 'USD', 'panel' => 'compra',
            'concepto' => 'Bolsas de prueba', 'importe' => '12.345', 'beneficiario' => 'Tienda de prueba',
            'fecha' => '2026-09-24', 'metodo' => 'tarjeta', 'ambito' => 'personal',
        ]);

        $form = $this->formularioDe($html, 'compra');
        $this->assertReabierto($html, 'compra', 'c-importe');
        $this->assertErrorDebajo($form, 'c-importe');
        foreach (['value="Bolsas de prueba"', 'value="12.345"', 'value="Tienda de prueba"', 'value="2026-09-24"',
            '<option value="tarjeta" selected>', '<option value="personal" selected>', 'value="'.$clave.'"'] as $escrito) {
            $this->assertUnaVezEn($html, $form, $escrito);
        }

        // Las filas de abajo usan los mismos nombres (importe, fecha, metodo) y siguen igual.
        foreach ([$a, $b] as $fila) {
            $otra = $this->formularioDe($html, 'fila-'.($fila->naturaleza === 'compra' ? 'abonar' : 'pagar').'-'.$fila->id);
            $this->assertStringContainsString('value="'.self::HOY.'"', $otra);
            $this->assertStringNotContainsString('selected', $otra);
            $this->assertStringNotContainsString('text-red-600', $otra);
        }
        $this->assertSame(2, Gasto::count(), 'No se guardó la compra.');
    }

    public function test_pagar_vuelve_abierto_con_lo_escrito(): void
    {
        $u = $this->operador();
        $a = $this->deuda($u, 'Proveedor de prueba A', '48.00', 'operativo');
        $b = $this->deuda($u, 'Proveedor de prueba B', '60.00', 'operativo');
        $panel = 'fila-pagar-'.$a->id;
        $clave = (string) Str::uuid();

        $html = $this->volverConError($u, route('gastos.panel.pagar', $a), [
            'clave' => $clave, 'panel' => $panel,
            'fecha' => '2026-09-30', 'metodo' => 'efectivo', 'referencia' => 'Ref de prueba 77',
        ]);

        $form = $this->formularioDe($html, $panel);
        $this->assertReabierto($html, $panel, 'p-fecha-'.$a->id);
        $this->assertErrorDebajo($form, 'p-fecha-'.$a->id);
        foreach (['value="2026-09-30"', '<option value="efectivo" selected>', 'value="Ref de prueba 77"', 'value="'.$clave.'"'] as $escrito) {
            $this->assertUnaVezEn($html, $form, $escrito);
        }

        $otra = $this->formularioDe($html, 'fila-pagar-'.$b->id);
        $this->assertStringContainsString('value="'.self::HOY.'"', $otra);
        $this->assertStringNotContainsString('selected', $otra);
        $this->assertStringNotContainsString('text-red-600', $otra);
        $this->assertSame(0, Pago::count());
    }

    public function test_abonar_vuelve_abierto_al_panel_con_lo_escrito_y_la_misma_clave_no_paga_dos_veces(): void
    {
        $u = $this->operador();
        $a = $this->deuda($u, 'Proveedor de prueba A', '300.00', 'compra');
        $b = $this->deuda($u, 'Proveedor de prueba B', '500.00', 'compra');
        $panel = 'fila-abonar-'.$a->id;
        $clave = (string) Str::uuid();
        $datos = [
            'clave' => $clave, 'panel' => $panel, 'origen' => 'panel',
            'beneficiario' => 'Proveedor de prueba A', 'moneda' => 'USD',
            'importe' => '450.00', 'fecha' => '2026-09-24', 'metodo' => 'tarjeta',
        ];

        // Abona más de lo que se debe: el servicio lo frena y vuelve al panel, no a la cuenta.
        $html = $this->volverConError($u, route('gastos.panel.abonos'), $datos);

        $form = $this->formularioDe($html, $panel);
        $this->assertReabierto($html, $panel, 'a-importe-'.$a->id);
        $this->assertErrorDebajo($form, 'a-importe-'.$a->id);
        $this->assertStringContainsString('El abono supera lo que se le debe', $form);
        foreach (['value="450.00"', 'value="2026-09-24"', '<option value="tarjeta" selected>', 'value="'.$clave.'"'] as $escrito) {
            $this->assertUnaVezEn($html, $form, $escrito);
        }

        $otra = $this->formularioDe($html, 'fila-abonar-'.$b->id);
        $this->assertStringContainsString('value="'.self::HOY.'"', $otra);
        $this->assertStringNotContainsString('selected', $otra);
        $this->assertStringNotContainsString('El abono supera', $otra);
        $this->assertSame(0, Pago::count());

        // Corregido y reenviado con la clave que volvió: se guarda una vez. Un doble clic
        // con esa misma clave no registra un segundo abono.
        $corregido = array_replace($datos, ['importe' => '100.00']);
        $this->actingAs($u)->from(route('gastos.panel'))->post(route('gastos.panel.abonos'), $corregido)
            ->assertSessionHasNoErrors()->assertRedirect(route('gastos.panel'));
        $this->actingAs($u)->from(route('gastos.panel'))->post(route('gastos.panel.abonos'), $corregido)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Pago::count());
    }

    public function test_anotar_el_monto_vuelve_abierto_con_lo_escrito(): void
    {
        $u = $this->operador();
        foreach (['Proveedor de prueba A', 'Proveedor de prueba B'] as $proveedor) {
            app(GenerarObligaciones::class)->paraRegla($this->reglaVariable($u, $proveedor), CarbonImmutable::parse(self::HOY)->startOfDay(), $u);
        }
        $a = Gasto::where('beneficiario', 'Proveedor de prueba A')->sole();
        $b = Gasto::where('beneficiario', 'Proveedor de prueba B')->sole();
        $panel = 'fila-completar-'.$a->id;

        $html = $this->volverConError($u, route('gastos.panel.completar', $a), [
            'panel' => $panel, 'importe' => '12,50', 'vence' => '2026-10-05',
        ]);

        $form = $this->formularioDe($html, $panel);
        $this->assertReabierto($html, $panel, 'r-importe-'.$a->id);
        $this->assertErrorDebajo($form, 'r-importe-'.$a->id);
        $this->assertUnaVezEn($html, $form, 'value="12,50"');
        $this->assertUnaVezEn($html, $form, 'value="2026-10-05"');

        $otra = $this->formularioDe($html, 'fila-completar-'.$b->id);
        $this->assertStringNotContainsString('text-red-600', $otra);
        $this->assertNull($a->fresh()->importe, 'No se anotó nada.');
    }

    /**
     * Doble clic en «Guardar el monto» (o dos personas con el mismo recibo): el primero lo
     * anota y el segundo choca con «ya tiene importe». Para entonces esa fila ya ofrece
     * Pagar (o Abonar, si es una compra). No se abre ese otro formulario con el error y
     * con la cifra escrita para el recibo: el aviso se lee arriba y la fila queda cerrada.
     */
    public function test_el_doble_clic_en_guardar_el_monto_no_abre_el_pago_ni_el_abono_de_esa_fila(): void
    {
        $u = $this->operador();
        $operativo = $this->sinMonto($u, 'Proveedor de prueba A', 'operativo');
        $compra = $this->sinMonto($u, 'Proveedor de prueba B', 'compra');
        $antes = $this->actingAs($u)->get(route('gastos.panel'))->assertOk()->getContent();

        // Operativo: después del monto la fila se paga. Compra: se abona.
        foreach ([['p', $operativo], ['a', $compra]] as [$prefijo, $gasto]) {
            $url = route('gastos.panel.completar', $gasto);
            $datos = ['panel' => $this->panelQueManda($antes, $url), 'importe' => '50.00', 'vence' => ''];
            $this->actingAs($u)->from(route('gastos.panel'))->post($url, $datos)->assertSessionHasNoErrors();
            $html = $this->volverConError($u, $url, $datos);

            $this->assertStringContainsString('Este gasto ya tiene importe', $html, 'El aviso se lee arriba.');
            $this->assertSame(0, substr_count($html, self::FOCO), 'Ningún formulario se abrió solo.');
            $this->assertStringNotContainsString('value="50.00"', $html, 'La cifra del recibo no pasa a otro formulario.');
            $otra = $this->formularioCon($html, 'id="'.$prefijo.'-fecha-'.$gasto->id.'"');
            $this->assertStringContainsString('value="'.self::HOY.'"', $otra);
            $this->assertStringNotContainsString('text-red-600', $otra);
            $this->assertStringNotContainsString('ya tiene importe', $otra);
        }

        $this->assertSame('50.00', $operativo->fresh()->importe);
        $this->assertSame(1, $compra->fresh()->cuotas()->count(), 'Se anotó una sola vez.');
    }

    /**
     * La sesión vence con el panel abierto (media hora sin tocar nada): el envío llega con
     * un token que ya no vale y no se guarda nada. Vuelve abierto y con lo escrito, y dice
     * que no se guardó, arriba y dentro del formulario. Antes volvía cerrado, vacío y sin
     * ningún aviso: parecía guardado.
     */
    public function test_con_la_sesion_vencida_vuelve_abierto_con_lo_escrito_y_avisa_que_no_se_guardo(): void
    {
        $u = $this->operador();
        $a = $this->deuda($u, 'Proveedor de prueba A', '48.00', 'operativo');
        // En las pruebas Laravel no revisa el token: acá sí, como en el servidor.
        config(['app.url' => 'http://localhost']);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $enLocal = fn (string $url) => 'http://localhost'.parse_url($url, PHP_URL_PATH);
        $pagar = 'fila-pagar-'.$a->id;
        $envios = [
            'compra' => [route('gastos.panel.compras'), 'c-concepto', [
                'moneda' => 'USD', 'concepto' => 'Bolsas de prueba', 'importe' => '12.50',
                'beneficiario' => 'Tienda de prueba', 'fecha' => '2026-09-24', 'metodo' => 'tarjeta', 'ambito' => 'personal',
            ], ['value="Bolsas de prueba"', 'value="12.50"', 'value="2026-09-24"', '<option value="tarjeta" selected>', '<option value="personal" selected>']],
            $pagar => [route('gastos.panel.pagar', $a), 'p-fecha-'.$a->id, [
                'fecha' => '2026-09-23', 'metodo' => 'efectivo', 'referencia' => 'Ref de prueba 79',
            ], ['value="2026-09-23"', '<option value="efectivo" selected>', 'value="Ref de prueba 79"']],
        ];

        foreach ($envios as $panel => [$url, $foco, $datos, $escritos]) {
            $clave = (string) Str::uuid();
            $this->flushHeaders()->actingAs($u)
                ->withHeaders(['Referer' => $enLocal(route('gastos.panel')), 'Origin' => 'http://localhost'])
                ->post($enLocal($url), $datos + ['_token' => 'token-de-una-sesion-vencida', 'clave' => $clave, 'panel' => $panel])
                ->assertRedirect($enLocal(route('gastos.panel')))
                ->assertSessionHas('error');
            $html = $this->flushHeaders()->actingAs($u)->get(route('gastos.panel'))->assertOk()->getContent();

            $form = $this->formularioDe($html, $panel);
            $this->assertReabierto($html, $panel, $foco);
            $this->assertMatchesRegularExpression('/role="alert"[^>]*>\s*Tu sesión había vencido/', $html, 'El aviso se lee arriba.');
            $this->assertStringContainsString('Tu sesión había vencido', $form, 'Y dentro del formulario que volvió.');
            foreach (array_merge($escritos, ['value="'.$clave.'"']) as $escrito) {
                $this->assertUnaVezEn($html, $form, $escrito);
            }
        }

        $this->assertSame(1, Gasto::count(), 'No se guardó la compra.');
        $this->assertSame(0, Pago::count(), 'Ni el pago.');
    }

    /**
     * Los botones que abren un formulario dicen si está abierto y cuál abren, como ya hacían
     * «Registrar pago» y «Saltar». Importa más ahora que, tras un error, el formulario se
     * abre solo: sin esto, volver al botón y tocarlo lo cerraba sin ningún aviso.
     */
    public function test_los_botones_que_abren_un_formulario_dicen_si_esta_abierto(): void
    {
        $u = $this->operador();
        $pagar = $this->deuda($u, 'Proveedor de prueba A', '48.00', 'operativo');
        $abonar = $this->deuda($u, 'Proveedor de prueba B', '300.00', 'compra');
        $completar = $this->sinMonto($u, 'Proveedor de prueba C', 'operativo');
        $html = $this->actingAs($u)->get(route('gastos.panel'))->assertOk()->getContent();

        foreach (['compra', 'fila-pagar-'.$pagar->id, 'fila-abonar-'.$abonar->id, 'fila-completar-'.$completar->id] as $panel) {
            $this->assertMatchesRegularExpression('/<button type="button" @click="abrir\(\''.$panel.'\'\)"[^>]*'
                .':aria-expanded="abierto === \''.$panel.'\' \? \'true\' : \'false\'" aria-expanded="false" aria-controls="'.$panel.'"/', $html);
            $this->assertMatchesRegularExpression('/<div id="'.$panel.'" x-show="abierto === \''.$panel.'\'"/', $html);
        }
    }

    public function test_registrar_pago_de_una_fila_programada_vuelve_abierto_con_lo_escrito(): void
    {
        $u = $this->operador();
        $a = $this->reglaVariable($u, 'Proveedor de prueba A');
        $b = $this->reglaVariable($u, 'Proveedor de prueba B');
        $panel = 'prog-'.$a->id.'-2026_09';
        $clave = (string) Str::uuid();

        $html = $this->volverConError($u, route('gastos.panel.programado.pagar', $a), [
            'clave' => $clave, 'panel' => $panel, 'periodo' => '2026-09',
            'importe' => '73,40', 'fecha' => '2026-09-20', 'metodo' => 'tarjeta', 'referencia' => 'Ref programada de prueba',
        ]);

        $form = $this->formularioDe($html, $panel);
        $this->assertReabierto($html, $panel, 'pg-imp-'.$panel);
        $this->assertErrorDebajo($form, 'pg-imp-'.$panel);
        foreach (['value="73,40"', 'value="2026-09-20"', '<option value="tarjeta" selected>',
            'value="Ref programada de prueba"', 'value="'.$clave.'"'] as $escrito) {
            $this->assertUnaVezEn($html, $form, $escrito);
        }

        $otra = $this->formularioDe($html, 'prog-'.$b->id.'-2026_09');
        $this->assertStringContainsString('value="'.self::HOY.'"', $otra);
        $this->assertStringNotContainsString('selected', $otra);
        $this->assertStringNotContainsString('text-red-600', $otra);
        $this->assertSame(0, Gasto::count(), 'No se creó la obligación.');
        $this->assertSame(0, Ocurrencia::count());
    }

    /**
     * Un error que no es de un campo a la vista —acá, la clave del pago— también se lee
     * dentro del panel, no solo en el resumen de arriba. La clave inválida no se devuelve:
     * el formulario trae una nueva, o el reintento fallaría siempre igual.
     */
    public function test_un_error_sin_campo_propio_se_ve_dentro_del_panel_reabierto(): void
    {
        $u = $this->operador();
        $a = $this->deuda($u, 'Proveedor de prueba A', '48.00', 'operativo');
        $b = $this->deuda($u, 'Proveedor de prueba B', '60.00', 'operativo');
        $panel = 'fila-pagar-'.$a->id;

        $html = $this->volverConError($u, route('gastos.panel.pagar', $a), [
            'clave' => 'no-es-una-clave', 'panel' => $panel,
            'fecha' => '2026-09-24', 'metodo' => 'efectivo', 'referencia' => 'Ref de prueba 78',
        ]);

        $form = $this->formularioDe($html, $panel);
        $this->assertReabierto($html, $panel, 'p-fecha-'.$a->id);
        $this->assertMatchesRegularExpression('/<p class="[^"]*text-red-600[^"]*">[^<]*clave[^<]*<\/p>/i', $form);
        $this->assertStringNotContainsString('no-es-una-clave', $html);
        $this->assertMatchesRegularExpression('/name="clave" value="[0-9a-f-]{36}"/', $form);
        $this->assertUnaVezEn($html, $form, 'value="Ref de prueba 78"');
        $this->assertStringNotContainsString('text-red-600', $this->formularioDe($html, 'fila-pagar-'.$b->id));
    }

    /**
     * Doble envío con otro importe y la misma clave (el primero sí se guardó): el servicio
     * lo frena con un error de «pago», que se lee dentro del panel. La clave vuelve igual,
     * así que reenviar no puede registrar un segundo abono.
     */
    public function test_la_clave_ya_usada_se_avisa_dentro_del_panel_y_no_registra_otro_abono(): void
    {
        $u = $this->operador();
        $a = $this->deuda($u, 'Proveedor de prueba A', '300.00', 'compra');
        $this->deuda($u, 'Proveedor de prueba B', '500.00', 'compra');
        $panel = 'fila-abonar-'.$a->id;
        $clave = (string) Str::uuid();
        $datos = [
            'clave' => $clave, 'panel' => $panel, 'origen' => 'panel',
            'beneficiario' => 'Proveedor de prueba A', 'moneda' => 'USD',
            'importe' => '100.00', 'fecha' => '2026-09-24', 'metodo' => 'tarjeta',
        ];
        $this->actingAs($u)->from(route('gastos.panel'))->post(route('gastos.panel.abonos'), $datos)->assertSessionHasNoErrors();

        $html = $this->volverConError($u, route('gastos.panel.abonos'), array_replace($datos, ['importe' => '150.00']));

        $form = $this->formularioDe($html, $panel);
        $this->assertReabierto($html, $panel, 'a-importe-'.$a->id);
        $this->assertStringContainsString('Este registro ya fue utilizado con otros datos.', $form);
        $this->assertUnaVezEn($html, $form, 'value="150.00"');
        $this->assertUnaVezEn($html, $form, 'value="'.$clave.'"');
        $this->assertSame(1, Pago::count());
    }
}
