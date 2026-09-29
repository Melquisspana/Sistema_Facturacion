<?php

namespace Database\Seeders;

use App\Models\Gastos\Ajuste;
use App\Models\Gastos\Gasto;
use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Datos FICTICIOS de Gastos para revisar el módulo en desarrollo.
 *
 * NO ES UNA IMPORTACIÓN HISTÓRICA. La fecha de arranque y la carga de pendientes
 * reales siguen sin decidirse, y cuando se decidan será un proceso revisado
 * documento por documento, no un seeder. Esto solo pinta la pantalla con casos
 * representativos para poder juzgarla.
 *
 * Se niega a correr fuera de local/testing: sembrar nombres inventados en una base
 * de verdad sería peor que no tener datos.
 *
 * Ejecutar:  php artisan db:seed --class=GastosDemoSeeder
 */
class GastosDemoSeeder extends Seeder
{
    private const MARCA = '[demo]';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('GastosDemoSeeder solo corre en local o testing: son datos inventados.');
        }

        $usuarios = User::where('activo', true)->orderBy('id')->get();
        if ($usuarios->isEmpty()) {
            throw new RuntimeException('No hay usuarios activos a los que asignar responsable.');
        }

        $ana = $usuarios->first();
        $otro = $usuarios->count() > 1 ? $usuarios[1] : $ana;

        // Idempotente: se limpia lo sembrado antes por esta misma marca, así se puede
        // volver a correr sin acumular. Solo toca filas con la marca.
        $this->limpiar();

        $hoy = now();

        // ── Vencido con abono parcial: el caso que enseña los dos ejes a la vez ──
        $mantenimiento = $this->gasto($ana, [
            'beneficiario' => 'Refrigeración Industrial SA',
            'concepto' => 'Mantenimiento del cuarto frío '.self::MARCA,
            'categoria' => 'Mantenimiento', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'importe' => '450.00', 'documentacion' => 'adjunto',
        ], [['450.00', $hoy->copy()->subDays(12)->toDateString()]]);
        $this->pago($ana, $mantenimiento, [[0, '150.00']], $hoy->copy()->subDays(5), 'transferencia', 'TRF-88213');

        // ── Cuotas con fechas distintas: una vencida cubierta y otra futura ──
        $seguro = $this->gasto($ana, [
            'beneficiario' => 'Aseguradora del Pacífico',
            'concepto' => 'Póliza de flota, semestre '.self::MARCA,
            'categoria' => 'Seguros', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'importe' => '1200.00', 'documentacion' => 'adjunto',
        ], [
            ['400.00', $hoy->copy()->subDays(20)->toDateString()],
            ['400.00', $hoy->copy()->addDays(10)->toDateString()],
            ['400.00', $hoy->copy()->addDays(40)->toDateString()],
        ]);
        $this->pago($ana, $seguro, [[0, '400.00']], $hoy->copy()->subDays(18), 'transferencia', 'TRF-88010');

        // ── Vence hoy, sin pagos ──
        $this->gasto($otro, [
            'beneficiario' => 'Compañía de Telecomunicaciones',
            'concepto' => 'Internet y telefonía '.self::MARCA,
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'importe' => '89.90', 'documentacion' => 'pendiente',
        ], [['89.90', $hoy->toDateString()]]);

        // ── Próximo, proveedor sin documento fiscal ──
        $this->gasto($otro, [
            'beneficiario' => 'Taller El Progreso',
            'concepto' => 'Reparación de portón '.self::MARCA,
            'categoria' => 'Mantenimiento', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'importe' => '75.00', 'documentacion' => 'no_entregaron',
            'observaciones' => 'Trabajo pagado a plazo. No emite CCF.',
        ], [['75.00', $hoy->copy()->addDays(6)->toDateString()]]);

        // ── Impuesto enviado por la contadora ──
        $iva = $this->gasto($ana, [
            'beneficiario' => 'Ministerio de Hacienda',
            'concepto' => 'IVA del período anterior '.self::MARCA,
            'categoria' => 'Impuestos', 'ambito' => 'empresarial', 'naturaleza' => 'tributo',
            'importe' => '612.45', 'documentacion' => 'adjunto',
            'periodo_desde' => $hoy->copy()->subMonth()->startOfMonth()->toDateString(),
            'periodo_hasta' => $hoy->copy()->subMonth()->endOfMonth()->toDateString(),
        ], [['612.45', $hoy->copy()->addDays(3)->toDateString()]]);

        // ── Pagado completo: sirve para ver la pestaña «Pagados» ──
        $energia = $this->gasto($ana, [
            'beneficiario' => 'Distribuidora Eléctrica',
            'concepto' => 'Energía eléctrica del mes pasado '.self::MARCA,
            'categoria' => 'Servicios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'importe' => '318.20', 'documentacion' => 'adjunto',
        ], [['318.20', $hoy->copy()->subDays(9)->toDateString()]]);
        $this->pago($ana, $energia, [[0, '318.20']], $hoy->copy()->subDays(8), 'transferencia', 'TRF-87755');

        // ── Saldada solo con AJUSTE: se informa «Saldada por ajuste», no «Pagada» ──
        $devolucion = $this->gasto($ana, [
            'beneficiario' => 'Insumos y Empaques SA',
            'concepto' => 'Cajas defectuosas devueltas '.self::MARCA,
            'categoria' => 'Insumos', 'ambito' => 'empresarial', 'naturaleza' => 'compra',
            'importe' => '95.00', 'documentacion' => 'adjunto',
        ], [['95.00', $hoy->copy()->subDays(4)->toDateString()]]);
        $this->ajuste($ana, $devolucion, 'credito', 'nota_credito', '95.00',
            'Nota de crédito 4417: se devolvió el lote completo por daño de fábrica.');

        // ── Esperando monto: no suma a pendiente ni a vencido ──
        $this->gasto($otro, [
            'beneficiario' => 'Contadora externa',
            'concepto' => 'Honorarios del trimestre, falta el recibo '.self::MARCA,
            'categoria' => 'Honorarios', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'importe' => null, 'documentacion' => 'pendiente',
        ], []);

        // ── Personales: solo los ve quien tenga `gastos.personales` ──
        $universidad = $this->gasto($ana, [
            'beneficiario' => 'Universidad Centroamericana',
            'concepto' => 'Cuota mensual de la carrera '.self::MARCA,
            'categoria' => 'Educación', 'ambito' => 'personal', 'persona' => 'Andrea',
            'naturaleza' => 'operativo', 'importe' => '210.00', 'documentacion' => 'adjunto',
        ], [['210.00', $hoy->copy()->addDays(8)->toDateString()]]);

        $this->gasto($ana, [
            'beneficiario' => 'Instituto de Inglés',
            'concepto' => 'Clases particulares '.self::MARCA,
            'categoria' => 'Educación', 'ambito' => 'personal', 'persona' => 'Andrea',
            'naturaleza' => 'operativo', 'importe' => '60.00', 'documentacion' => 'no_entregaron',
        ], [['60.00', $hoy->copy()->subDays(2)->toDateString()]]);

        // ── PAGO MIXTO: mismo destinatario y moneda, empresa + personal ──
        // Es el caso que hay que poder mirar para juzgar la regla de permisos: quien no
        // alcanza lo personal ve su parte y su subtotal, nunca el total de 260.
        $librosEmpresa = $this->gasto($ana, [
            'beneficiario' => 'Librería Universitaria',
            'concepto' => 'Papelería de oficina '.self::MARCA,
            'categoria' => 'Insumos', 'ambito' => 'empresarial', 'naturaleza' => 'compra',
            'importe' => '160.00', 'documentacion' => 'adjunto',
        ], [['160.00', $hoy->copy()->subDays(3)->toDateString()]]);

        $librosPersonal = $this->gasto($ana, [
            'beneficiario' => 'Librería Universitaria',
            'concepto' => 'Libros de texto '.self::MARCA,
            'categoria' => 'Educación', 'ambito' => 'personal', 'persona' => 'Andrea',
            'naturaleza' => 'compra', 'importe' => '100.00', 'documentacion' => 'adjunto',
        ], [['100.00', $hoy->copy()->subDays(3)->toDateString()]]);

        $this->pagoMultiple($ana, [
            [$librosEmpresa, 0, '160.00'],
            [$librosPersonal, 0, '100.00'],
        ], '260.00', $hoy->copy()->subDays(1), 'tarjeta', 'VISA-4417');

        // ── Pago REVERTIDO: el saldo volvió y el registro quedó en el historial ──
        $alquiler = $this->gasto($otro, [
            'beneficiario' => 'Inmobiliaria del Centro',
            'concepto' => 'Alquiler de bodega '.self::MARCA,
            'categoria' => 'Alquileres', 'ambito' => 'empresarial', 'naturaleza' => 'operativo',
            'importe' => '500.00', 'documentacion' => 'adjunto',
        ], [['500.00', $hoy->copy()->subDays(7)->toDateString()]]);
        $revertido = $this->pago($otro, $alquiler, [[0, '500.00']], $hoy->copy()->subDays(6), 'transferencia', 'TRF-ERROR');
        $revertido->update([
            'revertido_at' => $hoy->copy()->subDays(5),
            'revertido_por' => $ana->id,
            'motivo_reversion' => 'Se registró con el mes equivocado; el pago correcto se cargará aparte.',
        ]);
        $this->evento(null, $revertido->id, $ana->id, 'pago_revertido', ['motivo' => $revertido->motivo_reversion]);

        $this->command?->info('Gastos de demostración sembrados. Todos llevan la marca '.self::MARCA.'.');
    }

    /** @param  array<int, array{0: string, 1: ?string}>  $cuotas */
    private function gasto(User $usuario, array $datos, array $cuotas): Gasto
    {
        $gasto = Gasto::create($datos + [
            'clave' => (string) Str::uuid(),
            'huella_peticion' => str_repeat('0', 64),
            'moneda' => 'USD',
            'responsable_id' => $usuario->id,
            'registrado_por' => $usuario->id,
        ]);

        foreach ($cuotas as $i => [$importe, $vence]) {
            $gasto->cuotas()->create(['numero' => $i + 1, 'importe' => $importe, 'vence' => $vence]);
        }

        $this->evento($gasto->id, null, $usuario->id, 'gasto_registrado', ['importe' => $gasto->importe]);

        return $gasto->fresh();
    }

    /** @param  array<int, array{0: int, 1: string}>  $aplicaciones  índice de cuota => importe */
    private function pago(User $usuario, Gasto $gasto, array $aplicaciones, $fecha, string $metodo, ?string $referencia): Pago
    {
        $mapeadas = array_map(
            fn (array $a) => [$gasto, $a[0], $a[1]],
            $aplicaciones
        );

        $total = array_reduce($aplicaciones, fn ($c, $a) => $c + Dinero::centavos($a[1]), 0);

        return $this->pagoMultiple($usuario, $mapeadas, Dinero::decimal($total), $fecha, $metodo, $referencia);
    }

    /** @param  array<int, array{0: Gasto, 1: int, 2: string}>  $filas */
    private function pagoMultiple(User $usuario, array $filas, string $importe, $fecha, string $metodo, ?string $referencia): Pago
    {
        $primero = $filas[0][0];

        $pago = Pago::create([
            'clave' => (string) Str::uuid(),
            'huella_peticion' => str_repeat('0', 64),
            'beneficiario' => $primero->beneficiario,
            'moneda' => $primero->moneda,
            'importe' => $importe,
            'fecha' => $fecha->toDateString(),
            'metodo' => $metodo,
            'pagado_por' => $usuario->id,
            'registrado_por' => $usuario->id,
            'referencia' => $referencia,
            // Un caso con «falta comprobante» y su motivo, para poder verlo en pantalla.
            'sin_comprobante' => $referencia === null ? 'Pago en efectivo sin recibo; se pedirá copia.' : null,
        ]);

        foreach ($filas as [$gasto, $indice, $monto]) {
            $cuota = $gasto->cuotas()->orderBy('numero')->get()[$indice];
            DB::table('gastos_pago_aplicaciones')->insert([
                'pago_id' => $pago->id, 'cuota_id' => $cuota->id, 'importe' => $monto,
            ]);
        }

        $this->evento(null, $pago->id, $usuario->id, 'pago_registrado', ['importe' => $importe]);

        return $pago->fresh();
    }

    private function ajuste(User $usuario, Gasto $gasto, string $direccion, string $tipo, string $importe, string $motivo): void
    {
        $cuota = $gasto->cuotas()->orderBy('numero')->first();

        $ajuste = Ajuste::create([
            'clave' => (string) Str::uuid(),
            'cuota_id' => $cuota->id,
            'direccion' => $direccion,
            'tipo' => $tipo,
            'importe' => $importe,
            'motivo' => $motivo,
            'registrado_por' => $usuario->id,
        ]);

        $this->evento($gasto->id, null, $usuario->id, 'ajuste_registrado', ['motivo' => $motivo]);
        DB::table('gastos_eventos')->where('gasto_id', $gasto->id)
            ->where('accion', 'ajuste_registrado')->update(['ajuste_id' => $ajuste->id]);
    }

    private function evento(?int $gastoId, ?int $pagoId, int $usuarioId, string $accion, array $datos): void
    {
        DB::table('gastos_eventos')->insert([
            'gasto_id' => $gastoId, 'pago_id' => $pagoId, 'usuario_id' => $usuarioId,
            'accion' => $accion, 'datos' => json_encode($datos, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    /** Retira lo sembrado antes por este mismo seeder. Nada más. */
    private function limpiar(): void
    {
        $gastos = Gasto::where('concepto', 'like', '%'.self::MARCA)->pluck('id');
        if ($gastos->isEmpty()) {
            return;
        }

        $cuotas = DB::table('gastos_cuotas')->whereIn('gasto_id', $gastos)->pluck('id');
        $pagos = DB::table('gastos_pago_aplicaciones')->whereIn('cuota_id', $cuotas)->pluck('pago_id')->unique();

        DB::table('gastos_eventos')->whereIn('gasto_id', $gastos)->orWhereIn('pago_id', $pagos)->delete();
        DB::table('gastos_adjuntos')->whereIn('gasto_id', $gastos)->orWhereIn('pago_id', $pagos)->delete();
        DB::table('gastos_ajustes')->whereIn('cuota_id', $cuotas)->delete();
        DB::table('gastos_pago_aplicaciones')->whereIn('cuota_id', $cuotas)->delete();
        DB::table('gastos_pagos')->whereIn('id', $pagos)->delete();
        DB::table('gastos_fuentes')->whereIn('gasto_id', $gastos)->delete();
        DB::table('gastos_cuotas')->whereIn('gasto_id', $gastos)->delete();
        DB::table('gastos')->whereIn('id', $gastos)->delete();
    }
}
