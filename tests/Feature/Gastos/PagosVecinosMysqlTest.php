<?php

namespace Tests\Feature\Gastos;

use App\Enums\PermisoSistema;
use App\Models\Gastos\Cuota;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\RegistrarAjuste;
use App\Services\Gastos\RegistrarPago;
use App\Services\Gastos\SaldosGastos;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Dte\CorrelativoAnualMysqlTest;
use Tests\TestCase;

/**
 * DOS MOVIMIENTOS A LA VEZ EN CUOTAS VECINAS DE GASTOS DISTINTOS HACEN FILA, NO SE CRUZAN.
 *
 * En MySQL (REPEATABLE READ) la lectura con candado del saldo de una cuota bloquea
 * también el hueco del índice `cuota_id`. Dos pagos simultáneos de cuotas vecinas sin
 * historial tomaban el mismo hueco, después los dos insertaban su aplicación ahí y MySQL
 * cortaba a uno por interbloqueo (siempre, en una prueba con dos procesos del 12/11). No
 * perdía datos —se reintentaba—, pero el candado estaba mal puesto.
 *
 * Ahora todos toman primero el mismo turno ({@see SaldosGastos::tomarTurno()}): el
 * segundo espera AHÍ, sin haber tomado ningún hueco, así que el primero nunca lo espera.
 *
 * Solo corre en MySQL (job «PHPUnit (MySQL estricto)»), con dos conexiones y datos
 * CONFIRMADOS, como {@see CorrelativoAnualMysqlTest}:
 *  1. la otra conexión registra un movimiento en la cuota de un gasto y deja su
 *     transacción ABIERTA;
 *  2. la principal registra uno en la cuota del otro gasto: tiene que esperar en el turno
 *     (se corta con innodb_lock_wait_timeout = 1), no al insertar en el saldo, que es
 *     donde antes esperaba con el hueco ya tomado;
 *  3. la otra confirma y la principal pasa.
 * Datos ficticios.
 */
class PagosVecinosMysqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Los candados de hueco solo existen en MySQL (job «PHPUnit (MySQL estricto)»).');
        }
        Artisan::call('migrate:fresh');

        foreach (PermisoSistema::todos() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['gastos.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->app && DB::connection()->getDriverName() === 'mysql') {
            DB::purge('mysql_concurrente');
            // Deja la base como la encontraría RefreshDatabase: recién migrada, sin datos de prueba.
            Artisan::call('migrate:fresh');
        }
        parent::tearDown();
    }

    private function operador(): User
    {
        $usuario = User::factory()->create(['activo' => true]);
        $usuario->syncPermissions(PermisoSistema::todos());

        return $usuario;
    }

    private function cuota(User $usuario): Cuota
    {
        $gasto = Gasto::create([
            'clave' => (string) Str::uuid(), 'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => 'Proveedor de prueba', 'concepto' => 'Servicio de prueba',
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'moneda' => 'USD', 'importe' => '100.00', 'documentacion' => 'pendiente',
            'responsable_id' => $usuario->id, 'registrado_por' => $usuario->id,
        ]);

        return $gasto->cuotas()->create(['numero' => 1, 'importe' => '100.00', 'vence' => '2026-09-10']);
    }

    private function pagar(User $usuario, Cuota $cuota): Pago
    {
        return app(RegistrarPago::class)->registrar($usuario, [
            'clave' => (string) Str::uuid(), 'importe' => '40.00', 'fecha' => '2026-09-05',
            'metodo' => 'efectivo', 'pagado_por' => $usuario->id, 'referencia' => null, 'sin_comprobante' => 'sin recibo',
        ], [['cuota_id' => $cuota->id, 'importe' => '40.00']]);
    }

    private function acreditar(User $usuario, Cuota $cuota): void
    {
        app(RegistrarAjuste::class)->registrar($usuario, $cuota, [
            'clave' => (string) Str::uuid(), 'direccion' => 'credito', 'tipo' => 'correccion',
            'importe' => '10.00', 'motivo' => 'Corrección de prueba.',
        ]);
    }

    /** Corre `$movimiento` en la otra conexión y deja su transacción abierta. */
    private function abiertoEnOtraConexion(\Closure $movimiento): void
    {
        config(['database.connections.mysql_concurrente' => config('database.connections.mysql')]);
        $principal = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql_concurrente');
        DB::connection('mysql_concurrente')->beginTransaction();
        $movimiento();
        DB::setDefaultConnection($principal);
    }

    private function esperaEnElTurno(\Closure $movimiento): void
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $movimiento();
            $this->fail('No esperó: el otro movimiento sigue abierto con el turno tomado.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
            $this->assertStringContainsString('`secuencias`', $e->getSql(),
                'Tiene que esperar en el turno, antes de tomar nada del saldo: '.$e->getSql());
        }
        DB::connection('mysql_concurrente')->commit();
        $movimiento();
    }

    public function test_un_pago_hace_fila_tras_el_de_la_cuota_vecina_de_otro_gasto(): void
    {
        [$ana, $beto] = [$this->operador(), $this->operador()];
        $primera = $this->cuota($ana);
        $segunda = $this->cuota($beto);

        $this->abiertoEnOtraConexion(fn () => $this->pagar($beto, $segunda));
        $this->esperaEnElTurno(fn () => $this->pagar($ana, $primera));

        $this->assertSame(2, DB::table('gastos_pago_aplicaciones')->count());
    }

    public function test_un_pago_hace_fila_tras_el_credito_de_la_cuota_vecina_de_otro_gasto(): void
    {
        [$ana, $beto] = [$this->operador(), $this->operador()];
        $primera = $this->cuota($ana);
        $segunda = $this->cuota($beto);

        $this->abiertoEnOtraConexion(fn () => $this->acreditar($beto, $segunda));
        $this->esperaEnElTurno(fn () => $this->pagar($ana, $primera));

        $this->assertSame(1, DB::table('gastos_pago_aplicaciones')->count());
        $this->assertSame(1, DB::table('gastos_ajustes')->count());
    }

    public function test_un_credito_hace_fila_tras_el_pago_de_la_cuota_vecina_de_otro_gasto(): void
    {
        [$ana, $beto] = [$this->operador(), $this->operador()];
        $primera = $this->cuota($ana);
        $segunda = $this->cuota($beto);

        $this->abiertoEnOtraConexion(fn () => $this->pagar($beto, $segunda));
        $this->esperaEnElTurno(fn () => $this->acreditar($ana, $primera));

        $this->assertSame(1, DB::table('gastos_ajustes')->count());
    }
}
