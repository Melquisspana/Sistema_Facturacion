<?php

use App\Models\Municipio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HOTFIX CAT-013 — catálogo oficial del MH vigente desde el 1 de julio de 2026.
 *
 * El catálogo de mayo tenía invertidas las dos agrupaciones de Cabañas. El oficial dice:
 *
 *      10 = CABAÑAS ESTE   (5 distritos: Dolores, Guacotecti, Sensuntepeque,
 *                           San Isidro, Victoria)
 *      11 = CABAÑAS OESTE  (4 distritos: Cinquera, Ilobasco, Jutiapa, Tejutepeque)
 *
 * Efecto en producción: los CCF de Sensuntepeque salían con municipio 11 y los de Ilobasco
 * con municipio 10 — exactamente al revés. Corregidos, Sensuntepeque serializa 09/10/06 e
 * Ilobasco 09/11/03.
 *
 * QUÉ TOCA (y nada más):
 *   - `distritos.municipio_codigo` de los 9 distritos de Cabañas.
 *   - `municipios.codigo` de las filas de Cabañas, derivado del distrito homónimo.
 *
 * QUÉ NO TOCA, DELIBERADAMENTE:
 *   - `distritos.codigo` (CAT-008): 03 = ILOBASCO y 06 = SENSUNTEPEQUE no cambiaron. El
 *     catálogo de julio los trae con cero a la izquierda ("03" en vez de "3"), pero eso es
 *     formato, no semántica, y el poblador ya normalizaba a dos dígitos.
 *   - `clientes` y `cliente_sucursales`: NINGUNA fila se reasigna. Las salas apuntan por
 *     id a `municipios`/`distritos`; corregir el código del catálogo referenciado deja la
 *     relación correcta sin cambiar la identidad de la sala. Verificado contra las salas
 *     productivas 196 y 218 (Ilobasco, municipio_id 39 / distrito_id 210) y 224
 *     (Sensuntepeque, municipio_id 38 / distrito_id 215).
 *   - DTE históricos, su JSON, JWS, sellos, correlativos y estados: intactos. Un DTE ya
 *     emitido conserva el código con el que se selló; esta corrección aplica a lo que se
 *     emita de acá en adelante.
 *
 * IDEMPOTENTE: escribe solo las filas cuyo valor difiere del objetivo, así que la segunda
 * corrida no cambia nada. Y no lee `catalogos_mh`: usa los valores oficiales explícitos, de
 * modo que da lo mismo si corre antes o después de `php artisan dte:catalogos`.
 */
return new class extends Migration
{
    /** Código CAT-013 oficial de cada agrupación de Cabañas (catálogo del 2026-07-01). */
    private const AGRUPACIONES = [
        'Cabañas Este' => '10',
        'Cabañas Oeste' => '11',
    ];

    private const DEPARTAMENTO = '09'; // CAT-012 Cabañas

    public function up(): void
    {
        DB::transaction(function () {
            $departamentoId = DB::table('departamentos')
                ->where('codigo', self::DEPARTAMENTO)
                ->value('id');

            // Base sin sembrar (suite de pruebas, instalación nueva): nada que corregir.
            // Los datos nacen ya correctos del catálogo de julio.
            if (! $departamentoId) {
                return;
            }

            // 1. Distritos → su agrupación CAT-013 correcta.
            foreach (self::AGRUPACIONES as $agrupacion => $codigo) {
                DB::table('distritos')
                    ->where('departamento_id', $departamentoId)
                    ->where('municipio', $agrupacion)
                    ->where(fn ($q) => $q->whereNull('municipio_codigo')->orWhere('municipio_codigo', '!=', $codigo))
                    ->update(['municipio_codigo' => $codigo, 'updated_at' => now()]);
            }

            // 2. Municipios → el código de la agrupación a la que pertenecen.
            //
            // Regla determinista de la reforma 2024: cada municipio anterior pasó a ser un
            // DISTRITO homónimo, así que el código de la fila `municipios` es el
            // `municipio_codigo` de su distrito homónimo en el mismo departamento. Es la
            // misma regla que ya usa VinculaMunicipioDistrito::completarCodigosMunicipio(),
            // salvo que aquí CORRIGE un valor equivocado en vez de rellenar solo los NULL.
            //
            // Si una fila no tiene distrito homónimo (o hay más de uno), se deja intacta:
            // no se inventa un código.
            $municipios = DB::table('municipios')
                ->where('departamento_id', $departamentoId)
                ->get(['id', 'nombre', 'codigo']);

            foreach ($municipios as $municipio) {
                $codigos = DB::table('distritos')
                    ->where('departamento_id', $departamentoId)
                    ->where('nombre', $municipio->nombre)
                    ->whereNotNull('municipio_codigo')
                    ->distinct()
                    ->pluck('municipio_codigo')
                    ->all();

                if (count($codigos) !== 1 || (string) $municipio->codigo === (string) $codigos[0]) {
                    continue;
                }

                DB::table('municipios')
                    ->where('id', $municipio->id)
                    ->update(['codigo' => $codigos[0], 'updated_at' => now()]);
            }
        });

        // El mapa código → nombre fiscal se memoriza en caché de proceso y quedó calculado
        // con los códigos viejos. Sin esto, la misma corrida seguiría mostrando
        // "Cabañas Oeste" para el código 10 recién corregido.
        Municipio::olvidarNombresFiscales();
    }

    public function down(): void
    {
        // No se revierte: volver atrás reintroduciría el CAT-013 invertido y haría que
        // Hacienda rechazara los DTE de Cabañas. Igual criterio que
        // 2026_08_11_231450_repair_san_salvador_sur_ubicaciones.
    }
};
