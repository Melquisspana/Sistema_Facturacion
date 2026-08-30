<?php

use App\Enums\ModoPapelFisico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué exige cada cliente respecto del CCF FÍSICO firmado y sellado antes de dejar que un
 * documento entre a cobro.
 *
 * ─────────────────────────── La realidad que modela ───────────────────────────
 *
 * En las cadenas que trabajan con documento impreso, el CCF viaja con el pedido, la sala
 * lo firma y lo sella, y el motorista debería traerlo de vuelta. A veces no vuelve, se
 * pierde, o aparece meses después; y mientras nadie lo encuentra, ese documento no se
 * puede cobrar aunque el pedido esté entregado hace semanas.
 *
 * El sistema conocía la ENTREGA —el albarán llega solo al correo— pero no tenía forma de
 * exigir el PAPEL. Eran dos hechos distintos, con dos dueños distintos y con días de
 * diferencia entre uno y otro, y el segundo no estaba modelado en ninguna parte: era
 * disciplina de oficina. Por eso el hueco se volvía invisible justo cuando fallaba.
 *
 * ─────────────────────── Por qué es del CLIENTE y no del sistema ───────────────────────
 *
 * Porque no todos cobran igual. Una cadena que firma y sella el impreso no se cobra como
 * un cliente que paga contra la factura electrónica, y el día que entre un cliente nuevo
 * no puede hacer falta tocar código. Va acá, en el perfil documental, junto al resto de
 * las exigencias propias de cada cadena, y NUNCA como una comparación por nombre o por id
 * de cliente dentro de una condición.
 *
 * ─────────────────────────── El valor por defecto importa ───────────────────────────
 *
 * `no_requerir`, y esa es la garantía que hace segura la introducción de la regla:
 *
 *  - un cliente SIN perfil —que son casi todos— no se entera de que esto existe;
 *  - un cliente CON perfil que no declare el modo se comporta exactamente igual que antes;
 *  - solo cambia el comportamiento de quien lo active a propósito.
 *
 * Y no se activa desde acá. Esta migración no toca ni una fila existente: encenderlo para
 * un cliente es una decisión de operación que se toma con
 * `php artisan perfil-documento:cliente <id> --papel-fisico=bloquear`.
 *
 * ─────────────────────── Qué NO alcanza esta regla ───────────────────────
 *
 * Los documentos históricos que llegan por Gmail (ContaPortable / P001). No tienen DTE
 * local, entran al lote como snapshot por su propio camino y ahí esta condición no se
 * consulta. Aplicárselas los bloquearía a todos de golpe, que es lo contrario de lo que
 * hace falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_perfiles_documento', function (Blueprint $table) {
            $table->string('modo_papel_fisico', 20)
                ->default(ModoPapelFisico::NoRequerir->value)
                ->after('exige_albaran_en_nc')
                ->comment('ModoPapelFisico: bloquear | advertir | no_requerir. Por defecto no_requerir = comportamiento histórico');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_perfiles_documento', function (Blueprint $table) {
            $table->dropColumn('modo_papel_fisico');
        });
    }
};
