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
 * Datos iniciales de EJEMPLO para probar el sistema. El repositorio es público: acá no
 * van datos reales de la empresa. Los reales se cargan en Configuración › Empresa emisora.
 *
 * ⚠️ EDITABLE: ajustá los valores marcados (actividad, precios, etc.) antes de usarlos. Es IDEMPOTENTE (updateOrCreate /
 * firstOrCreate) y NO borra nada: se puede correr varias veces sin duplicar ni
 * reiniciar correlativos ya consumidos.
 *
 * Ejecutar:  php artisan db:seed --class=DatosInicialesNegritaSeeder
 */
class DatosInicialesNegritaSeeder extends Seeder
{
    // --- Valores editables del emisor ---
    private const EMPRESA_RAZON_SOCIAL = 'Empresa de Ejemplo, S.A. de C.V.';

    private const EMPRESA_NOMBRE_COMERCIAL = 'Empresa de Ejemplo';

    private const EMPRESA_NIT = '0000-000000-000-0';   // ficticio

    private const EMPRESA_NRC = '000000-0';            // ficticio

    private const EMPRESA_TELEFONO = '2200-0000';

    private const EMPRESA_CORREO = 'facturacion@example.com';

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
        // La empresa emisora es un registro único. Si ya hay una, con el nombre que tenga,
        // se usa esa tal cual: este seeder no crea una segunda ni le pisa los datos.
        $existente = Empresa::query()->orderBy('id')->first();
        if ($existente !== null) {
            return $existente;
        }

        return Empresa::create(
            [
                'razon_social' => self::EMPRESA_RAZON_SOCIAL,
                'nombre_comercial' => self::EMPRESA_NOMBRE_COMERCIAL,
                'nit' => self::EMPRESA_NIT,
                'nrc' => self::EMPRESA_NRC,
                'actividad_economica_id' => $actividad?->id,
                'pais_id' => $pais?->id,
                'departamento_id' => $depto?->id,
                'municipio_id' => $muni?->id,
                'direccion' => 'Calle de Ejemplo 123, Localidad A',
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
                'direccion' => 'Calle de Ejemplo 123, Localidad A',
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
            ['num_documento' => '0614-010101-101-0'], // EDITAR con el NIT real de Calleja
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
                'correo' => 'compras@cliente-ejemplo.test',
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
                'nombre_comercial' => 'Importadora Ejemplo',
                'pais_id' => $extranjero?->id,
                // El schema oficial de exportación exige descActividad del receptor (CAT-019).
                'actividad_economica_id' => $actividad?->id,
                'direccion' => 'Ciudad de Guatemala',
                'correo' => 'importador1@ejemplo.test',
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
     * Productos de EJEMPLO con precio especial para el cliente "Calleja, S.A. de C.V.",
     * identificados por CÓDIGO DE BARRA. Todo es inventado: el repositorio es público y
     * acá no va el catálogo real ni los precios de ningún cliente. Los nombres son
     * «Producto de ejemplo N» y los códigos de barra usan el rango 200…, que GS1 reserva
     * para uso interno: no corresponden a ningún producto del comercio. El catálogo y
     * los precios reales se cargan desde las pantallas del sistema.
     *
     * Reglas (idempotente, se puede correr varias veces):
     *  - Se identifica por código de barra: si existe, se actualiza; si no, se crea.
     *  - El "código interno" es el declarado en la lista; para los que no lo traen se
     *    GENERA uno propio (CAL-…).
     *  - El precio se guarda como PRECIO ESPECIAL ACTIVO del cliente (no monto fijo
     *    global). Si ya hay uno activo, se actualiza; no se duplica.
     *  - Productos sin precio propio → $1.00.
     *  - Factor de empaque BOLSA (se anota en observaciones; no afecta inventario).
     */
    private function productosCalleja(?UnidadMedida $unidad): void
    {
        $calleja = Cliente::where('num_documento', '0614-010101-101-0')->first();
        if (! $calleja) {
            return;
        }

        // [nombre, codigo_interno|null, codigo_barra, precio_especial] — todo ficticio.
        $items = [
            ['Producto de ejemplo 1', 'EJ-0001', '2000000001001', '1.1000'],
            ['Producto de ejemplo 2', 'EJ-0002', '2000000001002', '1.2000'],
            ['Producto de ejemplo 3', 'EJ-0003', '2000000001003', '1.3000'],
            ['Producto de ejemplo 4', 'EJ-0004', '2000000001004', '1.4000'],
            ['Producto de ejemplo 5', 'EJ-0005', '2000000001005', '1.5000'],
            ['Producto de ejemplo 6', 'EJ-0006', '2000000001006', '1.6000'],
            ['Producto de ejemplo 7', 'EJ-0007', '2000000001007', '1.7000'],
            ['Producto de ejemplo 8', 'EJ-0008', '2000000001008', '1.8000'],
            ['Producto de ejemplo 9', 'EJ-0009', '2000000001009', '1.9000'],
            ['Producto de ejemplo 10', 'EJ-0010', '2000000001010', '2.0000'],
            ['Producto de ejemplo 11', 'EJ-0011', '2000000001011', '2.1000'],
            ['Producto de ejemplo 12', 'EJ-0012', '2000000001012', '2.2000'],
            ['Producto de ejemplo 13', 'EJ-0013', '2000000001013', '2.3000'],
            ['Producto de ejemplo 14', 'EJ-0014', '2000000001014', '2.4000'],
            // Sin código interno: se genera uno propio.
            ['Producto de ejemplo 15', null, '2000000001015', '2.5000'],
            // Sin precio propio → $1.00.
            ['Producto de ejemplo 16', null, '2000000001016', '1.0000'],
            ['Producto de ejemplo 17', null, '2000000001017', '1.0000'],
            ['Producto de ejemplo 18', null, '2000000001018', '1.0000'],
            ['Producto de ejemplo 19', null, '2000000001019', '1.0000'],
        ];

        foreach ($items as [$nombre, $codigoInterno, $codigoBarra, $precio]) {
            // Código interno propio cuando la lista no lo trae.
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
        $calleja = Cliente::where('num_documento', '0614-010101-101-0')->first();
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
