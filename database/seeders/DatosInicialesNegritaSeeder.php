<?php

namespace Database\Seeders;

use App\Enums\TamanioContribuyente;
use App\Enums\TipoCliente;
use App\Enums\TipoDocumentoCliente;
use App\Enums\TipoEstablecimiento;
use App\Enums\TipoImpuesto;
use App\Enums\TipoPersona;
use App\Enums\TipoProducto;
use App\Models\ActividadEconomica;
use App\Models\Cliente;
use App\Models\Correlativo;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Establecimiento;
use App\Models\Municipio;
use App\Models\Pais;
use App\Models\Producto;
use App\Models\ProductoPrecioCliente;
use App\Models\PuntoVenta;
use App\Models\UnidadMedida;
use Illuminate\Database\Seeder;

/**
 * Datos iniciales REALES de prueba para Dulces La Negrita.
 *
 * ⚠️ EDITABLE: ajustá los valores marcados (NIT/NRC, actividad, precios, etc.) con
 * los datos reales antes de producción. Es IDEMPOTENTE (updateOrCreate /
 * firstOrCreate) y NO borra nada: se puede correr varias veces sin duplicar ni
 * reiniciar correlativos ya consumidos.
 *
 * Ejecutar:  php artisan db:seed --class=DatosInicialesNegritaSeeder
 */
class DatosInicialesNegritaSeeder extends Seeder
{
    // --- Valores editables del emisor ---
    private const EMPRESA_RAZON_SOCIAL = 'Dulces La Negrita, S.A. de C.V.';
    private const EMPRESA_NOMBRE_COMERCIAL = 'Dulces La Negrita';
    private const EMPRESA_NIT = '0000-000000-000-0';   // EDITAR con el NIT real
    private const EMPRESA_NRC = '000000-0';            // EDITAR con el NRC real
    private const EMPRESA_TELEFONO = '2200-0000';
    private const EMPRESA_CORREO = 'facturacion@dulceslanegrita.sv';
    private const ESTAB_CODIGO = 'M001';
    private const PUNTO_VENTA_CODIGO = 'P001';

    public function run(): void
    {
        // Garantiza que existan los catálogos base (CatalogosMhSeeder es idempotente).
        $this->call(CatalogosMhSeeder::class);

        $elSalvador = Pais::where('codigo', 'SV')->first();
        $municipio = Municipio::where('nombre', 'like', '%Olocuilta%')->first();
        $departamento = $municipio?->departamento ?? Departamento::where('nombre', 'like', '%Paz%')->first();
        $actividad = ActividadEconomica::query()->orderBy('id')->first();
        $unidad = UnidadMedida::where('codigo', '59')->first()
            ?? UnidadMedida::whereNotNull('codigo')->first()
            ?? UnidadMedida::query()->first();

        $emisor = $this->emisor($elSalvador, $departamento, $municipio, $actividad);
        $estab = $this->establecimiento($emisor, $elSalvador, $departamento, $municipio);
        $pv = $this->puntoVenta($estab);
        $this->correlativos($estab, $pv);
        $this->clienteCalleja($elSalvador, $departamento, $municipio, $actividad);
        $this->clienteExportacion();
        $this->productos($unidad);
        $this->preciosEspeciales();
        $this->productosCalleja($unidad);
    }

    private function emisor(?Pais $pais, ?Departamento $depto, ?Municipio $muni, ?ActividadEconomica $actividad): Empresa
    {
        return Empresa::updateOrCreate(
            ['razon_social' => self::EMPRESA_RAZON_SOCIAL],
            [
                'nombre_comercial' => self::EMPRESA_NOMBRE_COMERCIAL,
                'nit' => self::EMPRESA_NIT,
                'nrc' => self::EMPRESA_NRC,
                'actividad_economica_id' => $actividad?->id,
                'pais_id' => $pais?->id,
                'departamento_id' => $depto?->id,
                'municipio_id' => $muni?->id,
                'direccion' => 'Olocuilta, La Paz',
                'telefono' => self::EMPRESA_TELEFONO,
                'correo' => self::EMPRESA_CORREO,
                'ambiente' => '00', // pruebas
                'activo' => true,
            ]
        );
    }

    private function establecimiento(Empresa $emisor, ?Pais $pais, ?Departamento $depto, ?Municipio $muni): Establecimiento
    {
        return Establecimiento::updateOrCreate(
            ['empresa_id' => $emisor->id, 'codigo' => self::ESTAB_CODIGO],
            [
                'nombre' => 'Casa Matriz',
                'tipo_establecimiento' => TipoEstablecimiento::CasaMatriz->value,
                'pais_id' => $pais?->id,
                'departamento_id' => $depto?->id,
                'municipio_id' => $muni?->id,
                'direccion' => 'Olocuilta, La Paz',
                'telefono' => self::EMPRESA_TELEFONO,
                'correo' => self::EMPRESA_CORREO,
                'activo' => true,
            ]
        );
    }

    private function puntoVenta(Establecimiento $estab): PuntoVenta
    {
        return PuntoVenta::updateOrCreate(
            ['establecimiento_id' => $estab->id, 'codigo' => self::PUNTO_VENTA_CODIGO],
            ['nombre' => 'Facturación principal', 'descripcion' => 'Terminal de facturación', 'activo' => true]
        );
    }

    private function correlativos(Establecimiento $estab, PuntoVenta $pv): void
    {
        // firstOrCreate: NO reinicia el último número si el correlativo ya existe.
        foreach (['01', '03', '05', '11'] as $tipo) {
            Correlativo::firstOrCreate(
                [
                    'tipo_dte' => $tipo,
                    'establecimiento_id' => $estab->id,
                    'punto_venta_id' => $pv->id,
                    'ambiente' => '00',
                ],
                ['ultimo_numero' => 0, 'activo' => true]
            );
        }
    }

    private function clienteCalleja(?Pais $pais, ?Departamento $depto, ?Municipio $muni, ?ActividadEconomica $actividad): void
    {
        $calleja = Cliente::updateOrCreate(
            ['num_documento' => '0614-010101-001-1'], // EDITAR con el NIT real de Calleja
            [
                'codigo' => 'CAL-001',
                'tipo_cliente' => TipoCliente::Contribuyente->value,
                'tipo_persona' => TipoPersona::Juridica->value,
                'tipo_documento' => TipoDocumentoCliente::Nit->value,
                'nrc' => '123456-7',
                'tamanio_contribuyente' => TamanioContribuyente::Grande->value,
                'es_agente_retencion' => true, // se deriva de ser "grande" contribuyente
                'descuento_global_default' => '0.00',
                'nombre' => 'Calleja, S.A. de C.V.',
                'nombre_comercial' => 'Super Selectos',
                'actividad_economica_id' => $actividad?->id,
                'pais_id' => $pais?->id,
                'departamento_id' => $depto?->id,
                'municipio_id' => $muni?->id,
                'direccion' => 'San Salvador',
                'correo' => 'compras@selectos.com.sv',
                'telefono' => '2500-0000',
                'requiere_orden_compra' => true,
                'etiqueta_orden_compra' => 'Orden de compra',
                'condicion_operacion_default' => 2, // Crédito por defecto para Calleja
                'activo' => true,
            ]
        );

        foreach (['Selectos Santa Rosa', 'Selectos Merliot', 'Selectos Cojutepeque'] as $i => $sala) {
            $calleja->sucursales()->updateOrCreate(
                ['nombre' => $sala],
                [
                    'codigo' => 'SALA-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'pais_id' => $pais?->id,
                    'requiere_orden_compra' => null, // hereda del cliente
                    'permite_ccf' => true,
                    'permite_nota_credito' => true,
                    'activo' => true,
                ]
            );
        }

        // Oficina Central: NO se crea aquí. Si YA existe, se ajusta para que solo
        // permita notas de crédito (no CCF). update() no crea si no hay coincidencia.
        $calleja->sucursales()
            ->where('nombre', 'Oficina Central')
            ->update(['permite_ccf' => false, 'permite_nota_credito' => true]);
    }

    private function clienteExportacion(): void
    {
        $extranjero = Pais::where('codigo', '!=', 'SV')->orderBy('id')->first();
        $actividad = ActividadEconomica::query()->orderBy('id')->first();

        Cliente::updateOrCreate(
            ['num_documento' => 'EXP-0001'],
            [
                'codigo' => 'EXP-001',
                'tipo_cliente' => TipoCliente::Exportacion->value,
                'tipo_persona' => TipoPersona::Juridica->value,
                'tipo_documento' => TipoDocumentoCliente::Pasaporte->value,
                'nombre' => 'Importadora Centroamericana, Inc.',
                'nombre_comercial' => 'ImpoCentro',
                'pais_id' => $extranjero?->id,
                // El schema oficial de exportación exige descActividad del receptor (CAT-019).
                'actividad_economica_id' => $actividad?->id,
                'direccion' => 'Ciudad de Guatemala',
                'correo' => 'import@impocentro.com',
                'requiere_orden_compra' => false,
                'activo' => true,
            ]
        );
    }

    private function productos(?UnidadMedida $unidad): void
    {
        $productos = [
            ['codigo' => 'DUL-001', 'nombre' => 'Pepitoria', 'codigo_barra' => '7400000000011', 'precio' => 0.50],
            ['codigo' => 'DUL-002', 'nombre' => 'Alegría', 'codigo_barra' => '7400000000028', 'precio' => 0.50],
            ['codigo' => 'DUL-003', 'nombre' => 'Conserva de coco', 'codigo_barra' => '7400000000035', 'precio' => 0.75],
            ['codigo' => 'DUL-004', 'nombre' => 'Semilla de marañón', 'codigo_barra' => null, 'precio' => 2.50],
        ];

        foreach ($productos as $p) {
            Producto::updateOrCreate(
                ['codigo' => $p['codigo']],
                [
                    'nombre' => $p['nombre'],
                    'codigo_barra' => $p['codigo_barra'],
                    'tipo_producto' => TipoProducto::Bien->value,
                    'unidad_medida_id' => $unidad?->id,
                    'precio_unitario' => $p['precio'],
                    'tipo_impuesto' => TipoImpuesto::Gravado->value,
                    'maneja_inventario' => false,
                    'activo' => true,
                ]
            );
        }
    }

    /**
     * Productos REALES de Calleja, identificados por CÓDIGO DE BARRA, con su
     * precio especial para "Calleja, S.A. de C.V.".
     *
     * Reglas (idempotente, se puede correr varias veces):
     *  - Se identifica por código de barra: si existe, se actualiza; si no, se crea.
     *  - El "código interno" viene de la tabla de precios cuando existe; para los
     *    que no lo traen se GENERA uno propio (CAL-…). NUNCA se usa la columna
     *    "B Item" como código interno (se ignora por completo).
     *  - El precio se guarda como PRECIO ESPECIAL ACTIVO de Calleja (no monto fijo
     *    global). Si ya hay uno activo, se actualiza; no se duplica.
     *  - Productos sin precio en la tabla → $1.00.
     *  - Factor de empaque BOLSA (se anota en observaciones; no afecta inventario).
     */
    private function productosCalleja(?UnidadMedida $unidad): void
    {
        $calleja = Cliente::where('num_documento', '0614-010101-001-1')->first();
        if (! $calleja) {
            return;
        }

        // [nombre, codigo_interno|null, codigo_barra, precio_calleja]
        $items = [
            ['CANILLITAS', '79873', '7412201700031', '1.0500'],
            ['COCO RALLADO', '106753', '7412201700079', '1.0000'],
            ['DULCE DE MIEL', '117925', '7412201700109', '0.9500'],
            ['DULCE DE NANCE', '79875', '7412201700055', '0.9500'],
            ['DULCE DE TAMARINDO', '79877', '7412201700062', '0.9800'],
            ['HUEVITOS', '232837', '7412201700185', '0.9000'],
            ['MANI CON AJONJOLI', '224195', '7412201700154', '1.0400'],
            ['MANI DULCE', '224194', '7412201700147', '1.0400'],
            ['MANI HORNEADO', '214362', '7412201700123', '1.0400'],
            ['PEPIAYIOTE / PEPITORIA', '218350', '7412201700130', '1.0000'],
            ['QUEBRADIENTE', '79869', '7412201700017', '1.0400'],
            ['SEMILLA DE MARAÑON DULCE', '265771', '7412201700222', '1.0500'],
            ['SEMILLA DE MARAÑON HORNEADA', '230477', '7412201700178', '1.2700'],
            ['LECHE DE BURRA', '79866', '7412201700024', '1.0900'],
            ['MIX', null, '7412201700135', '1.0400'],
            // Sin precio en la tabla → $1.00.
            ['DULCES DE ANIS', null, '7412201700192', '1.0000'],
            ['BESITOS', null, '7412201700284', '1.0000'],
            ['CONSERVA DE COCO', null, '7412201700048', '1.0000'],
            ['MAZAPÁN', null, '7412201700115', '1.0000'],
        ];

        foreach ($items as [$nombre, $codigoInterno, $codigoBarra, $precio]) {
            // Código interno propio cuando la tabla no lo trae (NO se usa B Item).
            $codigo = $codigoInterno ?? ('CAL-'.substr($codigoBarra, -6));

            // Identidad por CÓDIGO DE BARRA.
            $producto = Producto::updateOrCreate(
                ['codigo_barra' => $codigoBarra],
                [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'tipo_producto' => TipoProducto::Bien->value,
                    'unidad_medida_id' => $unidad?->id,
                    'precio_unitario' => $precio, // precio general por defecto
                    'tipo_impuesto' => TipoImpuesto::Gravado->value,
                    'maneja_inventario' => false,
                    'observaciones' => 'Factor de empaque: BOLSA',
                    'activo' => true,
                ]
            );

            // Precio ESPECIAL activo de Calleja (actualiza si ya existe; no duplica).
            ProductoPrecioCliente::updateOrCreate(
                ['producto_id' => $producto->id, 'cliente_id' => $calleja->id, 'cliente_sucursal_id' => null],
                ['precio' => $precio, 'activo' => true]
            );
        }
    }

    private function preciosEspeciales(): void
    {
        $pepitoria = Producto::where('codigo', 'DUL-001')->first();
        $calleja = Cliente::where('num_documento', '0614-010101-001-1')->first();
        $exportacion = Cliente::where('codigo', 'EXP-001')->first();

        if ($pepitoria && $calleja) {
            // Pepitoria para Calleja: 0.45 (precio general 0.50).
            ProductoPrecioCliente::updateOrCreate(
                ['producto_id' => $pepitoria->id, 'cliente_id' => $calleja->id, 'cliente_sucursal_id' => null],
                ['precio' => 0.45, 'activo' => true]
            );
        }
        if ($pepitoria && $exportacion) {
            ProductoPrecioCliente::updateOrCreate(
                ['producto_id' => $pepitoria->id, 'cliente_id' => $exportacion->id, 'cliente_sucursal_id' => null],
                ['precio' => 1.15, 'activo' => true]
            );
        }
    }
}
