<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué tan firme es el importe de una obligación.
 *
 * Nace de un caso real y no de una abstracción: Proveedor A y Proveedor B se cargaron con un saldo
 * ACORDADO DE PALABRA al arrancar  mientras que el de Distribuidora Ejemplo salió
 * de un estado de cuenta en papel. Los tres se deben igual y los tres suman al mismo
 * total, pero no valen lo mismo como dato: el primero puede moverse cuando aparezca el
 * papel, el segundo no.
 *
 * Hasta ahora esa diferencia vivía SOLO dentro del texto del concepto («Saldo inicial
 * provisional al 15/09»). Alcanzaba para que una persona lo leyera, y no alcanzaba para
 * nada más: no se puede filtrar por una subcadena, no sobrevive a que alguien reescriba
 * el concepto, y una pantalla que quisiera distinguirlos tendría que buscar la palabra
 * «provisional» dentro de un campo libre. Eso es exactamente la clase de acoplamiento
 * que después falla en silencio.
 *
 * NULL es lo normal y significa «sin marca»: la inmensa mayoría de los gastos no
 * necesitan esta distinción y no se les inventa una. Solo se marca lo que de verdad
 * está pendiente de confirmar.
 *
 * No cambia ningún cálculo. Un saldo provisional se debe, vence y se paga igual que
 * cualquier otro; lo único que cambia es que la pantalla puede DECIRLO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $t) {
            $t->string('certeza', 20)->nullable()->after('importe');
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $t) {
            $t->dropColumn('certeza');
        });
    }
};
