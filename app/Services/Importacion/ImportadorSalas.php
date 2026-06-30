<?php

namespace App\Services\Importacion;

use App\Models\Cliente;
use App\Models\Pais;
use Illuminate\Support\Str;

/**
 * Importa salas/sucursales de un cliente desde CSV. Idempotente (clave: cliente +
 * nombre de sucursal). Mapea departamento/municipio a los catálogos si coinciden;
 * si no, deja la ubicación en observaciones y agrega una advertencia, sin abortar.
 */
class ImportadorSalas
{
    private const ALIAS = [
        'nombre_comercial' => 'nombre',
        'nombre_de_sucursal' => 'nombre',
        'sucursal' => 'nombre',
        'nombre' => 'nombre',
        // Código de sala de 4 dígitos de Calleja (0230). Opcional.
        'codigo' => 'codigo',
        'codigo_de_sala' => 'codigo',
        'codigo_sala' => 'codigo',
        'sala' => 'codigo',
        'cod' => 'codigo',
        'direccion' => 'direccion',
        'distrito' => 'distrito',
        'municipio' => 'municipio',
        'departamento' => 'departamento',
        'requiere_orden_compra' => 'requiere_oc',
        'requiere_orden_de_compra' => 'requiere_oc',
        'orden_compra' => 'requiere_oc',
    ];

    /** Claves canónicas que debe contener la fila de encabezados. */
    private const REQUERIDAS = ['nombre', 'direccion', 'municipio', 'departamento'];

    public function __construct(
        private readonly LectorCsv $lector,
        private readonly ResolvedorUbicacionImportacion $ubicacion,
    ) {}

    public function importar(Cliente $cliente, string $ruta): ResultadoImportacion
    {
        $resultado = new ResultadoImportacion();

        try {
            $filas = $this->lector->leer($ruta, self::ALIAS, self::REQUERIDAS);
        } catch (EncabezadoNoEncontradoException) {
            $resultado->error(0, '(archivo)', 'No se encontró la fila de encabezados. El archivo debe contener columnas Nombre comercial, Dirección, Distrito, Municipio y Departamento.');

            return $resultado;
        }

        $elSalvador = Pais::where('codigo', '9300')->value('id');

        foreach ($filas as $registro) {
            $resultado->leidas++;
            $numero = $resultado->leidas;

            $nombre = $registro['nombre'] ?? '';
            if ($nombre === '') {
                $resultado->ignorada($numero, '(sin nombre)', 'Fila sin nombre de sucursal.');

                continue;
            }

            // Resolución flexible de ubicación (normalización + equivalencias).
            $ubic = $this->ubicacion->resolver(
                $registro['distrito'] ?? null,
                $registro['municipio'] ?? null,
                $registro['departamento'] ?? null,
            );
            $resultado->advertencias += count($ubic['advertencias']);

            $observaciones = [];
            if (! empty($registro['distrito'])) {
                $observaciones[] = 'Distrito: '.$registro['distrito'];
            }
            foreach ($ubic['advertencias'] as $aviso) {
                $observaciones[] = $aviso;
            }

            $datos = [
                'direccion' => $registro['direccion'] ?? null,
                'pais_id' => $elSalvador,
                'departamento_id' => $ubic['departamentoId'],
                'municipio_id' => $ubic['municipioId'],
                'requiere_orden_compra' => $this->parsearOrdenCompra($registro['requiere_oc'] ?? null),
                'activo' => true,
                'observaciones' => $observaciones === [] ? null : implode(' · ', $observaciones),
            ];

            // Código de sala: solo se asigna si vino en el CSV (no pisa con vacío).
            $codigo = $this->normalizarCodigo($registro['codigo'] ?? null);
            if ($codigo !== null) {
                $datos['codigo'] = $codigo;
            }

            $sucursal = $cliente->sucursales()->updateOrCreate(['nombre' => $nombre], $datos);

            // Detalle: equivalencias (info) y/o advertencias.
            $partes = array_merge(
                array_map(fn ($i) => '✓ '.$i, $ubic['infos']),
                array_map(fn ($a) => '⚠ '.$a, $ubic['advertencias']),
            );
            $detalle = implode(' · ', $partes);

            $sucursal->wasRecentlyCreated
                ? $resultado->creada($numero, $nombre, $detalle)
                : $resultado->actualizada($numero, $nombre, $detalle);
        }

        return $resultado;
    }

    /** Código de sala: dígitos a 4 con cero inicial (230→0230); vacío → null. */
    private function normalizarCodigo(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        return preg_match('/^\d{1,4}$/', $valor) ? str_pad($valor, 4, '0', STR_PAD_LEFT) : $valor;
    }

    /** Sí/No/1/0 → bool; vacío → null (hereda del cliente). */
    private function parsearOrdenCompra(?string $valor): ?bool
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return in_array(Str::lower(Str::ascii(trim($valor))), ['si', 'sí', '1', 'true', 'x', 'verdadero'], true);
    }
}
