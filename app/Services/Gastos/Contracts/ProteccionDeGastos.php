<?php

namespace App\Services\Gastos\Contracts;

use App\Models\Gastos\Pago;
use App\Models\User;
use App\Services\Gastos\SinProteccion;
use Illuminate\Database\Query\Builder;

/**
 * Algunas obligaciones de Gastos NACEN EN OTRO MÓDULO y ese módulo tiene algo que
 * decir sobre quién las ve, quién las paga y quién las deshace.
 *
 * El caso que obligó a escribir esto: las obligaciones que genera una planilla son
 * gastos normales —lo son de verdad, y tienen que serlo para que el dinero viva en un
 * solo sitio—. Pero su beneficiario es una persona y su importe es su sueldo. Sin este
 * candado, cualquiera con `gastos.ver` abría /gastos y leía lo que cobra cada quien,
 * y cualquiera con `gastos.pagos.registrar` podía pagarlas sin tener ningún permiso
 * laboral. El módulo de Planilla protegía sus pantallas y dejaba la puerta de atrás
 * abierta.
 *
 * Gastos define ESTA interfaz —es suya— y pregunta. Quien responde es quien tiene el
 * contexto. Así Gastos no aprende nada de planilla, salarios ni anticipos.
 *
 * ═══════ Por qué se devuelve una SUBCONSULTA y no una lista de ids ═══════
 *
 * La primera versión devolvía `array<int>` y lo metía en un `whereNotIn`. Funcionaba, y
 * tenía dos defectos que se veían venir: la lista crece sin techo —una planilla por
 * semana durante años son miles de enteros viajando dentro de cada consulta— y hay que
 * decidir cuándo recalcularla, porque confirmar una planilla la deja rancia en el acto.
 * Una lista rancia acá no es un número mal pintado: es un sueldo que se ve.
 *
 * Una subconsulta no tiene ninguno de los dos problemas: la evalúa el motor en el
 * momento, siempre ve lo último y no viaja por PHP. `null` significa «no hay nada que
 * esconder», y entonces a la consulta no se le agrega ni una cláusula.
 *
 * La implementación por defecto ({@see SinProteccion}) no protege
 * nada, que es lo correcto cuando el otro módulo no está instalado.
 */
interface ProteccionDeGastos
{
    /**
     * Subconsulta con los ids de gasto que este usuario NO debe alcanzar, o null si no
     * hay ninguno.
     *
     * Se usa para RECORTAR CONSULTAS, no para filtrar al pintar: un total, un contador
     * de pestaña o una exportación delatarían exactamente lo mismo que la fila oculta.
     */
    public function ocultosPara(User $usuario): ?Builder;

    /**
     * Lo mismo, sin mirar a nadie, para las tuberías que NO son por usuario.
     *
     * La única hoy es la de avisos: se arman una vez por ámbito y después se reparten
     * entre destinatarios que pueden no alcanzar los importes, así que recortar por
     * usuario llegaría tarde —el aviso ya estaría escrito con el nombre y la cifra
     * dentro—.
     */
    public function protegidos(): ?Builder;

    /** ¿Este usuario puede ver este gasto, según el módulo que lo protege? */
    public function puedeVer(User $usuario, int $gastoId): bool;

    /**
     * Por qué este usuario NO puede pagar estas obligaciones, o null si sí puede.
     *
     * @param  array<int, int>  $gastoIds
     */
    public function motivoParaNoPagar(User $usuario, array $gastoIds): ?string;

    /** Por qué este usuario NO puede revertir este pago, o null si sí puede. */
    public function motivoParaNoRevertir(User $usuario, Pago $pago): ?string;

    /**
     * Por qué este pago NO se puede revertir HOY, sin importar quién lo pida, o null.
     *
     * Es distinto del anterior a propósito: uno es un permiso que a otra persona quizá
     * le sobre, y este es un estado que hay que resolver antes. Confundirlos haría que
     * el sistema respondiera «no tenés permiso» a quien lo tiene todo y solo necesita
     * saber qué desatar primero.
     */
    public function impedimentoParaRevertir(Pago $pago): ?string;
}
