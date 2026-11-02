<?php

namespace Tests\Feature\Planilla;

use App\Enums\PermisoSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\Planilla\PlanillaSueldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PersonalCargarTest extends TestCase
{
    use RefreshDatabase;

    private const ENCABEZADO = 'documento_tipo,documento,nombres,apellidos,cargo,fecha_ingreso,sueldo_mensual,con_descuentos,marca,sale_rutas,usuario_email';

    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = User::factory()->create(['activo' => true, 'email' => 'admin@example.test']);
        $admin->syncPermissions(PermisoSistema::todos());

        $this->archivo = tempnam(sys_get_temp_dir(), 'personal');
    }

    protected function tearDown(): void
    {
        @unlink($this->archivo);

        parent::tearDown();
    }

    private function correr(array $filas, array $opciones = [], string $encabezado = self::ENCABEZADO, string $separador = "\n"): array
    {
        file_put_contents($this->archivo, implode($separador, array_merge([$encabezado], $filas)));

        $codigo = Artisan::call('personal:cargar', ['archivo' => $this->archivo, '--usuario' => 'admin@example.test'] + $opciones);

        return [$codigo, Artisan::output()];
    }

    public function test_sin_aplicar_no_escribe_nada_y_no_muestra_datos_personales(): void
    {
        [$codigo, $salida] = $this->correr(['dui,00000000-0,Ana,Ficticia,Operaria,2026-01-15,408.80,sí,sí,no,']);

        $this->assertSame(0, $codigo);
        $this->assertSame(0, PlanillaEmpleado::count());
        $this->assertSame(0, AsistenciaEmpleado::count());
        $this->assertStringContainsString('No se escribió nada', $salida);

        foreach (['Ana', 'Ficticia', '00000000', '408.80'] as $dato) {
            $this->assertStringNotContainsString($dato, $salida);
        }
    }

    public function test_con_aplicar_carga_y_el_sueldo_rige_desde_la_fecha_pedida(): void
    {
        [$codigo] = $this->correr(['dui,00000000-0,Ana,Ficticia,Operaria,2026-01-15,408.80,sí,sí,no,'], ['--aplicar' => true, '--sueldo-desde' => '2026-11-01']);

        $this->assertSame(0, $codigo);
        $e = PlanillaEmpleado::sole();
        $this->assertTrue($e->con_descuentos_ley);
        $this->assertNotNull($e->asistencia_empleado_id);
        $this->assertSame('2026-11-01', PlanillaSueldo::sole()->vigente_desde->toDateString());
    }

    public function test_enlaza_a_quien_ya_existe_sin_duplicarlo(): void
    {
        $marca = AsistenciaEmpleado::create(['nombres' => 'Ana María', 'apellidos' => 'Ficticia López', 'activo' => true]);
        $ruta = PersonalRuta::create(['nombre' => 'Ana', 'activo' => true]);

        $this->correr(['dui,00000000-0,Ana,Ficticia,,,408.80,sí,no,no,'], ['--aplicar' => true]);

        $e = PlanillaEmpleado::sole();
        $this->assertSame($marca->id, $e->asistencia_empleado_id);
        $this->assertSame($ruta->id, $e->personal_ruta_id);
        $this->assertSame(1, AsistenciaEmpleado::count());
        $this->assertSame(1, PersonalRuta::count());
    }

    public function test_dos_fichas_posibles_detienen_toda_la_carga_sin_adivinar(): void
    {
        AsistenciaEmpleado::create(['nombres' => 'Ana', 'apellidos' => 'Ficticia', 'activo' => true]);
        AsistenciaEmpleado::create(['nombres' => 'Ana Luz', 'apellidos' => 'Ficticia Mora', 'activo' => true]);

        [$codigo, $salida] = $this->correr([
            'pasaporte,FICTICIO-1,Bea,Otra,,,408.80,sí,no,no,',
            'dui,00000000-0,Ana,Ficticia,,,408.80,sí,no,no,',
        ], ['--aplicar' => true]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Fila 3', $salida);
        $this->assertSame(0, PlanillaEmpleado::count());
    }

    public function test_una_fila_mala_no_deja_entrar_a_las_demas(): void
    {
        [$codigo, $salida] = $this->correr([
            'pasaporte,FICTICIO-1,Bea,Otra,,,408.80,sí,no,no,',
            'pasaporte,FICTICIO-2,Cruz,Otra,,,100.00,sí,no,no,',
        ], ['--aplicar' => true]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Fila 3', $salida);
        $this->assertSame(0, PlanillaEmpleado::count());
    }

    public function test_quien_ya_esta_en_planilla_no_se_vuelve_a_cargar(): void
    {
        $fila = 'pasaporte,FICTICIO-1,Bea,Otra,,,408.80,sí,no,no,';
        $this->correr([$fila], ['--aplicar' => true]);

        [$codigo] = $this->correr([$fila], ['--aplicar' => true]);

        $this->assertSame(1, $codigo);
        $this->assertSame(1, PlanillaEmpleado::count());
    }

    public function test_enlaza_un_usuario_por_correo_y_nunca_crea_uno(): void
    {
        $usuario = User::factory()->create(['email' => 'bea@example.test']);

        $this->correr(['pasaporte,FICTICIO-1,Bea,Otra,,,408.80,sí,no,no,bea@example.test'], ['--aplicar' => true]);
        $this->assertSame($usuario->id, PlanillaEmpleado::sole()->user_id);

        [$codigo] = $this->correr(['pasaporte,FICTICIO-2,Cruz,Otra,,,408.80,sí,no,no,nadie@example.test'], ['--aplicar' => true]);
        $this->assertSame(1, $codigo);
        $this->assertSame(0, User::where('email', 'nadie@example.test')->count());
    }

    public function test_lee_el_punto_y_coma_de_excel_con_bom_y_fin_de_linea_de_windows(): void
    {
        $encabezado = "\xEF\xBB\xBF".str_replace(',', ';', self::ENCABEZADO);

        [$codigo] = $this->correr(['pasaporte;FICTICIO-1;Bea;Otra;;;408.80;si;no;no;'], ['--aplicar' => true], $encabezado, "\r\n");

        $this->assertSame(0, $codigo);
        $this->assertSame(1, PlanillaEmpleado::count());
    }

    public function test_avisa_si_faltan_columnas_el_archivo_o_el_responsable(): void
    {
        [$codigo, $salida] = $this->correr(['x'], [], 'documento,nombres');
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Faltan columnas', $salida);

        $this->assertSame(1, Artisan::call('personal:cargar', ['archivo' => 'no-existe.csv', '--usuario' => 'admin@example.test']));
        $this->assertSame(1, Artisan::call('personal:cargar', ['archivo' => $this->archivo, '--usuario' => 'nadie@example.test']));
    }

    public function test_sin_descuentos_bajo_el_minimo_se_confirma_con_su_columna(): void
    {
        $encabezado = self::ENCABEZADO.',confirmar_bajo_minimo';

        [$rechazada] = $this->correr(['pasaporte,FICTICIO-1,Bea,Otra,,,300.00,no,no,no,,no'], ['--aplicar' => true], $encabezado);
        $this->assertSame(1, $rechazada);

        [$aceptada] = $this->correr(['pasaporte,FICTICIO-1,Bea,Otra,,,300.00,no,no,no,,sí'], ['--aplicar' => true], $encabezado);
        $this->assertSame(0, $aceptada);
    }
}
