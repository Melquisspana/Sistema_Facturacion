<?php

namespace App\Services\Planilla;

use App\Models\Planilla\Planilla;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generar una planilla en un solo paso: abre el período, pone a cada persona con su
 * sueldo habitual y sus descuentos de ley, y la confirma.
 *
 * Antes eran tres pantallas —abrir el borrador, guardarlo y confirmarlo en otra—, y el
 * enlace para imprimir del formulario no guardaba lo escrito: se imprimía un borrador
 * que todavía podía cambiar. Ahora no queda nada a medias.
 *
 * Lo que se muestra antes de generar ({@see revisar()}) es la misma cuenta, hecha de
 * verdad y deshecha al final: se ve exactamente lo que se va a crear, con los mismos
 * reparos que frenan la confirmación.
 *
 * La excepción: si alguien de la planilla tiene algo que una persona debe decidir antes
 * de confirmar ({@see PrepararPlanilla::pendientesDeRevisar()}), no se confirma en un
 * paso. Queda el borrador y se termina en «Preparar», que es donde eso se elige.
 */
final class GenerarPlanilla
{
    public function __construct(
        private PrepararPlanilla $preparar,
        private ConfirmarPlanilla $confirmar,
        private TotalesPlanilla $totales,
    ) {}

    /**
     * Todo o nada: si algo frena la confirmación, no queda ni el borrador. Devuelve la
     * planilla confirmada, o el BORRADOR cuando hay algo que revisar en «Preparar».
     *
     * @param  array<string, mixed>  $datos
     */
    public function generar(User $usuario, array $datos): Planilla
    {
        // Lo mismo que pide confirmar. Va antes de crear nada: sin importes no se puede
        // abrir «Preparar», y quedaría un borrador que esa persona no puede terminar.
        abort_unless($usuario->can('planilla.salarios'), 403);

        // Con los candados en el orden de {@see PrepararPlanilla::bloquearFichas()}: tres
        // intentos ante un interbloqueo, y de nuevo si cambiaron las personas en medio.
        return $this->preparar->conCandados(function () use ($usuario, $datos) {
            // Lo primero de la transacción, antes de leer nada (MySQL, REPEATABLE READ): las
            // fichas de quienes van a estar en la planilla, antes que la planilla.
            $this->preparar->bloquearPersona($datos);
            $planilla = $this->preparar->abrir($usuario, $datos);

            if (! $planilla->wasRecentlyCreated) {
                throw ValidationException::withMessages(['periodo' => $this->yaExiste($planilla)]);
            }

            $planilla->load('detalles.conceptos', 'detalles.empleado');

            if ($this->preparar->pendientesDeRevisar($planilla, false) !== []) {
                return $planilla;
            }

            return $this->confirmar->confirmar($usuario, $planilla);
        });
    }

    /**
     * Qué se va a crear, sin dejar nada creado.
     *
     * @param  array<string, mixed>  $datos
     * @return array{existente: ?Planilla, planilla: ?Planilla, personas: array<int, array{nombre: string, a_pagar: int, nota: ?string}>, resumen: ?array<string, int>, avisos: array<int, string>, reparos: array<int, string>, por_preparar: bool}
     */
    public function revisar(User $usuario, array $datos): array
    {
        abort_unless($usuario->can('planilla.salarios'), 403);

        DB::beginTransaction();

        try {
            $this->preparar->bloquearPersona($datos);
            $planilla = $this->preparar->abrir($usuario, $datos);

            if (! $planilla->wasRecentlyCreated) {
                return ['existente' => $planilla, 'planilla' => null, 'personas' => [], 'resumen' => null, 'avisos' => [], 'reparos' => [], 'por_preparar' => false];
            }

            $planilla->load('detalles.conceptos', 'detalles.empleado', 'individual');

            return [
                'existente' => null,
                'planilla' => $planilla,
                'personas' => $planilla->detalles->map(fn ($d) => [
                    'nombre' => $d->nombre_snapshot,
                    'a_pagar' => $this->totales->deDetalle($d)['a_pagar'],
                    'nota' => $d->observaciones,
                ])->all(),
                'resumen' => $this->totales->dePlanilla($planilla),
                'avisos' => $this->preparar->avisosParaConfirmar($planilla, true),
                'reparos' => $this->preparar->reparosParaConfirmar($planilla),
                // Los motivos ya van dentro de los avisos; esto dice si «Generar» deja el borrador.
                'por_preparar' => $this->preparar->pendientesDeRevisar($planilla, false) !== [],
            ];
        } catch (PersonasCambiaron) {
            throw ValidationException::withMessages(['periodo' => 'Cambiaron las personas de la planilla mientras se revisaba. Intentá de nuevo.']);
        } finally {
            DB::rollBack();
        }
    }

    public function yaExiste(Planilla $planilla): string
    {
        // Solo llega uno vigente: los anulados no ocupan el lugar.
        if ($planilla->esVacaciones()) {
            return $planilla->borrador()
                ? 'Ya hay un borrador del '.lcfirst($planilla->nombreDeVacaciones()).' que quedó de antes. Terminalo o borralo.'
                : 'Ese '.lcfirst($planilla->nombreDeVacaciones()).' ya está hecho. Si hay que corregirlo, anulalo y pagalo de nuevo.';
        }

        // Despido o renuncia: el de esta baja, sea de la clase que sea.
        if ($planilla->esIndemnizacion()) {
            return $planilla->borrador()
                ? 'Ya hay un borrador del pago de esta baja que quedó de antes: '.lcfirst($planilla->nombreDelPago()).'. Terminalo o borralo.'
                : 'El pago de esta baja ya está hecho: '.lcfirst($planilla->nombreDelPago()).'. Si hay que corregirlo, anulalo y generalo de nuevo.';
        }

        if ($planilla->borrador()) {
            return 'Ya hay un borrador de '.$planilla->periodo.' que quedó de antes. Terminalo o borralo.';
        }

        return 'La planilla '.$planilla->periodo.' ya existe ('.mb_strtolower(Planilla::ESTADOS[$planilla->estado])
            .'). Para hacerla de nuevo, primero hay que anularla o borrarla.';
    }
}
