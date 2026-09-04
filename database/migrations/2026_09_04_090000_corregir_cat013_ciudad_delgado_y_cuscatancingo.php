<?php

use App\Models\Municipio;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HOTFIX CAT-013 — Ciudad Delgado y Cuscatancingo son SAN SALVADOR CENTRO, no ESTE.
 *
 * Hallazgo de producción (auditoría del DTE técnico 353, Súper Selectos Ciudad Delgado):
 * Hacienda rechazó el CCF con «[receptor.direccion.distrito] VALOR NO ES PERMITIDO»
 * porque salió con el trío 06/22/19. Los catálogos oficiales dicen:
 *
 *      CAT-008 19 = CIUDAD DELGADO      (el distrito era correcto)
 *      CAT-013 22 = SAN SALVADOR ESTE   (la agrupación NO)
 *      CAT-013 23 = SAN SALVADOR CENTRO (la que le corresponde)
 *
 * El trío correcto es 06/23/19. Cuscatancingo (CAT-008 04) arrastraba el mismo error.
 *
 * ORIGEN DEL DATO MALO: `database/data/distritos_el_salvador_2024.csv` los agrupaba bajo
 * «San Salvador Este». No es algo que el importador pudiera detectar: el XLSX oficial del
 * MH trae CAT-008 (distritos) y CAT-013 (municipios) como listas planas SIN la relación
 * distrito → municipio ni columna de departamento, así que la pertenencia sale del CSV y
 * solo del CSV. El CSV ya está corregido en el mismo commit; esta migración arregla las
 * bases que se sembraron con la versión anterior.
 *
 * QUÉ TOCA (y nada más):
 *   - `distritos.municipio` y `distritos.municipio_codigo` de esos DOS distritos del
 *     departamento 06.
 *   - `municipios.codigo` de las filas homónimas «Ciudad Delgado» y «Cuscatancingo» del
 *     departamento 06 (es el valor que viaja en `direccion.municipio`).
 *
 * QUÉ NO TOCA, DELIBERADAMENTE:
 *   - `distritos.codigo` (CAT-008): 19 y 04 nunca estuvieron mal.
 *   - Las otras agrupaciones de San Salvador (Norte 20, Oeste 21, Este 22, Sur 24) y sus
 *     distritos: la corrección está acotada por departamento Y por nombre de distrito.
 *   - `clientes` y `cliente_sucursales`: NINGUNA fila se reasigna, ni por nombre ni por
 *     ningún otro criterio. La sala productiva 173 («Súper Selectos Ciudad Delgado») ya
 *     apunta a las entidades correctas —`municipios` 23 y `distritos` 158, ambas de
 *     Ciudad Delgado—, así que corregir el CÓDIGO del catálogo referenciado la deja
 *     emitiendo 06/23/19 sin cambiar su identidad. Reasignar salas por LIKE de nombre
 *     sería adivinar.
 *   - DTE históricos, su JSON, JWS, sellos, correlativos y estados: intactos. En
 *     particular el DTE 353 rechazado NO se modifica ni se retransmite; un documento ya
 *     sellado conserva el código con el que se selló y esta corrección aplica a lo que se
 *     emita de acá en adelante.
 *
 * IDEMPOTENTE: escribe solo las filas cuyo valor difiere del objetivo, así que la segunda
 * corrida no cambia una sola fila ni su `updated_at`. Y no lee `catalogos_mh`: usa los
 * valores oficiales explícitos, de modo que da lo mismo si corre antes o después de
 * `php artisan dte:catalogos`. Mismo criterio que
 * 2026_09_03_090000_corregir_cat013_cabanas_catalogo_julio_2026.
 */
return new class extends Migration
{
    private const DEPARTAMENTO = '06'; // CAT-012 San Salvador

    private const AGRUPACION = 'San Salvador Centro';

    private const CODIGO_AGRUPACION = '23'; // CAT-013 SAN SALVADOR CENTRO

    /** Los dos distritos mal agrupados. Nadie más se toca. */
    private const DISTRITOS = ['Ciudad Delgado', 'Cuscatancingo'];

    public function up(): void
    {
        DB::transaction(function () {
            $departamentoId = DB::table('departamentos')
                ->where('codigo', self::DEPARTAMENTO)
                ->value('id');

            // Base sin sembrar (instalación nueva, suite de pruebas): nada que corregir.
            // Los datos nacen ya correctos del CSV corregido.
            if (! $departamentoId) {
                return;
            }

            // 1. Los dos distritos → su agrupación 2024 y su código CAT-013 correctos.
            DB::table('distritos')
                ->where('departamento_id', $departamentoId)
                ->whereIn('nombre', self::DISTRITOS)
                ->where(fn ($q) => $q
                    ->where('municipio', '!=', self::AGRUPACION)
                    ->orWhereNull('municipio_codigo')
                    ->orWhere('municipio_codigo', '!=', self::CODIGO_AGRUPACION))
                ->update([
                    'municipio' => self::AGRUPACION,
                    'municipio_codigo' => self::CODIGO_AGRUPACION,
                    'updated_at' => now(),
                ]);

            // 2. Las filas homónimas de `municipios` → el código de esa agrupación.
            //
            // Regla determinista de la reforma 2024: cada municipio anterior pasó a ser un
            // DISTRITO homónimo, así que el código de la fila `municipios` es el
            // `municipio_codigo` de su distrito homónimo en el mismo departamento. Es la
            // misma regla de VinculaMunicipioDistrito::completarCodigosMunicipio(), salvo
            // que aquí CORRIGE un valor equivocado en vez de rellenar solo los NULL.
            //
            // Acotado a los dos nombres: las demás filas de San Salvador (Soyapango,
            // Ilopango, San Martín en la 22; San Salvador, Mejicanos, Ayutuxtepeque en la
            // 23; San Marcos, Santiago Texacuangos, Santo Tomás en la 24) ya están bien.
            DB::table('municipios')
                ->where('departamento_id', $departamentoId)
                ->whereIn('nombre', self::DISTRITOS)
                ->where(fn ($q) => $q
                    ->whereNull('codigo')
                    ->orWhere('codigo', '!=', self::CODIGO_AGRUPACION))
                ->update([
                    'codigo' => self::CODIGO_AGRUPACION,
                    'updated_at' => now(),
                ]);
        });

        // El mapa código → nombre fiscal se memoriza en caché de proceso y quedó calculado
        // con la agrupación vieja. Sin esto, la misma corrida seguiría mostrando
        // "San Salvador Este" para las salas recién corregidas.
        Municipio::olvidarNombresFiscales();
    }

    public function down(): void
    {
        // No se revierte: volver atrás reintroduciría el trío 06/22/19 y haría que
        // Hacienda rechazara de nuevo los CCF de Ciudad Delgado y Cuscatancingo. Igual
        // criterio que 2026_09_03_090000_corregir_cat013_cabanas_catalogo_julio_2026.
    }
};
