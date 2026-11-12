<?php

namespace Tests\Concerns;

use App\Enums\EstadoDte;
use App\Enums\TipoDte;
use App\Enums\TipoImpuesto;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Dte;
use App\Models\Establecimiento;
use App\Models\Producto;
use App\Models\PuntoVenta;
use App\Models\User;
use App\Services\Dte\DteBorradorService;
use App\Services\Dte\DteGeneracionService;
use App\Support\Dte\CandadoEmisionDte;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Un emisor completo y documentos en cada punto de la emisión (borrador, generado,
 * firmado), para las pruebas del candado por documento. Nada sale a la red. Datos ficticios.
 */
trait DocumentosParaEmitir
{
    use PreparaEmisorDte;

    protected Establecimiento $estab;

    protected PuntoVenta $pv;

    protected DteBorradorService $borradores;

    protected function prepararEmisor(): void
    {
        Storage::fake('local');
        foreach (['administrador', 'facturacion'] as $rol) {
            Role::findOrCreate($rol, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedCatalogosDte();
        ['estab' => $this->estab, 'pv' => $this->pv] = $this->crearEmisorDte();
        foreach (['03', '05'] as $tipo) {
            Correlativo::create([
                'tipo_dte' => $tipo, 'establecimiento_id' => $this->estab->id,
                'punto_venta_id' => $this->pv->id, 'ambiente' => '00',
                'ultimo_numero' => 0, 'activo' => true,
            ]);
        }
        $this->borradores = app(DteBorradorService::class);
    }

    protected function usuario(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    /** Candados abiertos contra APITEST, con firma simulada. Producción no se toca. */
    protected function habilitar(): void
    {
        config()->set('dte.transmision.enabled', true);
        config()->set('dte.transmision.real_confirmation', true);
        config()->set('dte.transmision.dry_run', false);
        config()->set('dte.transmision.sistema_actual_activo', false);
        config()->set('dte.transmision.modo_operacion', 'principal');
        config()->set('dte.transmision.allow_production', false);
        config()->set('dte.transmision.ambiente', 'testing');
        config()->set('dte.transmision.token', 'Bearer TOKEN_FAKE_NO_REAL');
        config()->set('dte.firma.enabled', true);
        config()->set('dte.firma.mock', true);
    }

    protected function borrador(): Dte
    {
        $dte = $this->borradores->crearBorrador([
            'tipo_dte' => TipoDte::CreditoFiscal,
            'establecimiento_id' => $this->estab->id,
            'punto_venta_id' => $this->pv->id,
            'cliente_id' => Cliente::factory()->contribuyente()->create(['correo' => 'cliente@ejemplo.test'])->id,
        ]);
        $producto = Producto::factory()->create([
            'precio_unitario' => 10, 'tipo_impuesto' => TipoImpuesto::Gravado->value,
        ]);
        $this->borradores->agregarLineaDesdeProducto($dte, $producto, cantidad: 10);

        return $dte->refresh();
    }

    protected function generado(): Dte
    {
        $dte = $this->borrador();
        app(DteGeneracionService::class)->generar($dte);

        return $dte->refresh();
    }

    /** Deja el documento FIRMADO, como si un intento anterior hubiera llegado hasta ahí. */
    protected function firmado(): Dte
    {
        $dte = $this->generado();
        $ruta = 'dte/firmados/dte-'.$dte->tipo_dte->value.'-'.$dte->id.'-'.$dte->codigo_generacion.'.jws';
        Storage::disk('local')->put($ruta, 'eyJ.fake.jws.compacta');
        $dte->json_firmado_path = $ruta;
        $dte->estado = EstadoDte::Firmado;
        $dte->save();

        return $dte->refresh();
    }

    /** El otro pedido: tiene el candado del documento y todavía no terminó. */
    protected function otroPedidoEmitiendo(Dte $dte): void
    {
        $this->assertTrue(Cache::lock(CandadoEmisionDte::clave($dte), 60)->get());
    }

    protected function candadoLibre(Dte $dte): bool
    {
        return (bool) Cache::lock(CandadoEmisionDte::clave($dte), 5)->get();
    }
}
