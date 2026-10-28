<?php

use App\Models\Municipio;
use App\Support\Ubicacion\OpcionesUbicacion;
use App\Support\Ubicacion\VinculaMunicipioDistrito;
use Database\Seeders\MunicipioSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FIX — Agrupaciones municipales 2024 que no se podían ELEGIR en el formulario.
 *
 * SÍNTOMA (reportado desde el formulario de sucursal): en el departamento San Salvador no
 * aparecía el municipio «San Salvador Norte», y por eso el distrito Guazapa era
 * imposible de seleccionar. No era un problema del CSV ni del filtro del selector: ambos
 * estaban correctos y `distritos` tenía las tres filas (Aguilares, El Paisnal, Guazapa)
 * bien agrupadas bajo San Salvador Norte con `municipio_codigo = 20`.
 *
 * CAUSA: el segundo select se arma con las filas de `municipios`
 * ({@see OpcionesUbicacion::municipios()}), y esa tabla nunca fue
 * un catálogo completo: {@see MunicipioSeeder} siembra a propósito un
 * "subconjunto útil para empezar a operar" de municipios PRE-2024. En San Salvador sembró
 * diez nombres, y NINGUNO de los tres que forman la agrupación 20 estaba entre ellos. Sin
 * una sola fila con `codigo = 20` en el departamento 06, la opción «San Salvador Norte»
 * no existía; y como el tercer select filtra por el municipio elegido —correctamente, es
 * lo que evita pares que Hacienda rechaza—, sus tres distritos quedaban inalcanzables.
 *
 * Es decir: el hueco estaba en `municipios`, no en `distritos` ni en el filtro.
 *
 * ALCANCE: el mismo hueco afecta a CINCO agrupaciones, no solo a la reportada:
 *
 *      02-16  Santa Ana Este        Coatepeque, El Congo
 *      04-34  Chalatenango Norte    La Palma, Citalá, San Ignacio
 *      06-20  San Salvador Norte    Aguilares, El Paisnal, Guazapa
 *      10-14  San Vicente Norte     Apastepeque, San Esteban Catarina, San Ildefonso,
 *                                   San Lorenzo, San Sebastián, Santa Clara, Santo Domingo
 *      13-27  Morazán Norte         Arambala, Cacaopera, Corinto, El Rosario, Joateca,
 *                                   Jocoaitique, Meanguera, Perquín, San Fernando,
 *                                   San Isidro, Torola
 *
 * Se corrigen las cinco: son el mismo defecto y arreglar solo San Salvador dejaría 24
 * distritos igual de inalcanzables. La reparación no va escrita a mano acá, sino que
 * delega en {@see VinculaMunicipioDistrito::asegurarAgrupaciones()} —fuente única, la
 * misma que ahora usan los seeders y `distritos:vincular-municipio`—, de modo que la
 * regla no exista duplicada en dos lugares que puedan divergir.
 *
 * QUÉ ESCRIBE: INSERTA la fila de `municipios` que falta por agrupación, nombrada con la
 * agrupación misma («San Salvador Norte»), con su código CAT-013 y activa.
 *
 * QUÉ NO TOCA, DELIBERADAMENTE:
 *   - `clientes`, `cliente_sucursales`, `empresas` y `establecimientos`: NINGUNA fila se
 *     reasigna. Las entidades apuntan a `municipio_id` y esta migración no cambia ni
 *     borra ningún id existente; solo agrega opciones nuevas al catálogo. Una sala que hoy
 *     apunta a otro municipio sigue apuntando exactamente al mismo.
 *   - `distritos`: ya estaban correctos; ni su `codigo` (CAT-008) ni su agrupación cambian.
 *   - Las filas de `municipios` que ya existen: no se renombran, no se desactivan y no se
 *     les cambia el código. Si una fila homónima tiene OTRO código CAT-013, se deja como
 *     está y se reporta, porque elegir entre dos códigos sería adivinar.
 *   - DTE históricos, su JSON, JWS, sellos, correlativos y estados: intactos. Lo ya
 *     sellado conserva lo que se selló; esto habilita lo que se emita de acá en adelante.
 *
 * IDEMPOTENTE: solo escribe agrupaciones sin representante, así que la segunda corrida no
 * cambia una sola fila. En una base nueva o en la suite de pruebas corre antes de que
 * existan distritos, no encuentra nada que hacer y el seeder se encarga después.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Base sin sembrar (instalación nueva, suite de pruebas): nada que reparar todavía.
        if (! DB::table('distritos')->whereNotNull('municipio_codigo')->exists()) {
            return;
        }

        DB::transaction(fn () => VinculaMunicipioDistrito::asegurarAgrupaciones());

        // El mapa código → nombre fiscal se memoriza en caché de proceso; sin esto las
        // filas recién creadas se mostrarían con el nombre calculado antes de existir.
        Municipio::olvidarNombresFiscales();
    }

    public function down(): void
    {
        // No se revierte: borrar estas filas volvería a esconder cinco agrupaciones
        // fiscales del formulario y, peor, dejaría huérfanas las entidades que para
        // entonces ya las estuvieran usando. Mismo criterio que
        // 2026_09_04_090000_corregir_cat013_ciudad_delgado_y_cuscatancingo.
    }
};
