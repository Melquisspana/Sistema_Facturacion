<?php

namespace App\Http\Controllers\Ppq;

use App\Exceptions\Ppq\AlbaranDadoDeBajaException;
use App\Http\Controllers\Controller;
use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\PpqLote;
use App\Models\PpqSala;
use App\Services\Ppq\AlbaranPersistidor;
use App\Services\Ppq\AlbaranPropioNc;
use App\Services\Ppq\SalaResolver;
use App\Support\Albaran;
use App\Support\IdentidadPpq;
use App\Support\OrdenCompra;
use App\Support\PpqElegibilidad;
use App\Support\Sala;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Agrega/quita CCF o NC de un lote PPQ, con control de duplicados:
 *  - No permite el mismo CCF/NC dos veces en el lote (unique en BD + chequeo amable).
 *  - Avisa si el CCF/NC ya está usado en otro lote.
 *  - Avisa si el albarán ya fue vinculado antes.
 *
 * Una NOTA DE CRÉDITO local aporta lo que ya tiene guardado: su albarán de crédito
 * (`dte_albaranes`) se reutiliza tal cual y no se vuelve a pedir. Los datos salen de la
 * base y no del formulario, de modo que un envío manipulado no puede colgarle a la nota un
 * albarán ajeno; si el envío nombra otro, se rechaza sin escribir nada.
 *
 * Y con un CANDADO FISCAL sobre los documentos LOCALES: solo entra al lote un CCF/NC de
 * producción aceptado realmente por Hacienda ({@see PpqElegibilidad}). Los históricos
 * que llegan por Gmail siguen su propio camino —no tienen DTE local que evaluar— y ese
 * flujo no cambia.
 */
class PpqItemController extends Controller
{
    public function store(Request $request, PpqLote $lote): RedirectResponse
    {
        if (! $lote->esEditable()) {
            return back()->with('error', 'El lote está en estado '.$lote->estado->label().' y no admite cambios.');
        }

        // Documento que viene de Gmail (no está en la BD local): se snapshotea.
        if ($request->input('origen') === 'gmail') {
            return $this->agregarDesdeGmail($request, $lote);
        }

        $datos = $request->validate([
            'dte_id' => ['required', Rule::exists('dtes', 'id')->whereIn('tipo_dte', ['03', '05'])],
            'ppq_albaran_id' => ['nullable', Rule::exists('ppq_albaranes', 'id')],
            // Albarán capturado a mano (flujo NC): número/fecha/monto/observaciones.
            'numero_albaran' => ['nullable', 'string'],
            'fecha_albaran' => ['nullable', 'string'],
            'monto_albaran' => ['nullable', 'numeric'],
            'gmail_message_id' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:500'],
        ]);

        $dte = Dte::findOrFail($datos['dte_id']);

        // CANDADO. La pantalla ya no dibuja los botones para un documento que no se puede
        // cobrar, pero eso es una cortesía, no una defensa: el `dte_id` viaja en un campo
        // oculto de un formulario POST y cualquiera puede cambiarlo. La única comprobación
        // que cuenta es esta, del lado del servidor, y usa LA MISMA regla que la pantalla
        // ({@see PpqElegibilidad::motivo()}) para que no puedan discrepar.
        //
        // Va ANTES de registrar el albarán: un intento rechazado no debe dejar rastro.
        $motivo = PpqElegibilidad::motivo($dte);
        if ($motivo !== null) {
            return back()->with('error', 'Ese documento no se puede cobrar por PPQ y no se agregó al lote. '.$motivo);
        }

        // Anti-duplicado dentro del lote, por IDENTIDAD COMPLETA: no alcanza con
        // `dte_id`. Si el documento ya entró como snapshot de Gmail —que es como
        // entraron los 158 items que existen hoy, todos con `dte_id` en NULL—, cruzar
        // solo por el vínculo lo dejaría pasar y el mismo CCF quedaría dos veces en el
        // mismo lote, cobrado por duplicado.
        if ($this->itemsEquivalentes($dte->id, $dte->numero_control)->where('ppq_lote_id', $lote->id)->exists()) {
            return back()->with('error', 'Ese CCF/NC ya está en este lote.');
        }

        // Aviso: ya usado en otro lote (no bloquea, informa). Misma regla de identidad.
        $otroLote = $this->itemsEquivalentes($dte->id, $dte->numero_control)
            ->where('ppq_lote_id', '!=', $lote->id)
            ->orderBy('id')
            ->value('ppq_lote_id');

        $esNc = $dte->tipo_dte?->value === '05';

        // "Agregar sin albarán": se incluye el CCF/NC dejando vacíos los datos del
        // albarán (notas de crédito / casos especiales). Marca explícita en el item.
        $sinAlbaran = $request->boolean('sin_albaran');

        // ALBARÁN PROPIO DE LA NC. Se lee de la BASE, no del formulario: el albarán de
        // crédito (AC02/AC04) que originó la nota ya se capturó para poder emitirla y
        // vive en `dte_albaranes`. Aceptarlo del POST dejaría que un envío manipulado le
        // colgara a la nota un albarán ajeno. El criterio (sin datos / completo / parcial
        // / inválido) es el MISMO que usa la ficha: {@see AlbaranPropioNc}.
        //
        // `sin_albaran` sigue mandando: es una decisión explícita del operador.
        $propio = $esNc && ! $sinAlbaran ? app(AlbaranPropioNc::class)->evaluar($dte) : null;
        $efectivo = null;

        if ($propio !== null && $propio['estado'] === AlbaranPropioNc::INVALIDO) {
            return back()->with('error', 'No se agregó nada: '.$propio['motivo']
                .' Hay que corregirlo en la nota antes de llevarla a PPQ.');
        }

        if ($propio !== null && $propio['estado'] !== AlbaranPropioNc::SIN_DATOS) {
            // Lo guardado manda; el envío SOLO completa lo que falta (para el item PPQ: la
            // nota emitida no se toca). El total fiscal de la NC nunca hace de monto.
            $efectivo = [
                'numero' => $propio['numero'],
                'fecha' => $propio['fecha'] ?? Albaran::fecha($datos['fecha_albaran'] ?? null),
                'monto' => $propio['monto'] ?? (filled($datos['monto_albaran'] ?? null)
                    ? number_format(abs((float) $datos['monto_albaran']), 2, '.', '')
                    : null),
            ];

            // Evidencia contradictoria —otro número, un `ppq_albaran_id` de otro documento
            // u OC, o un registro PPQ previo con otro monto o fecha—: se corta ANTES de
            // escribir nada y sin elegir una versión por cuenta propia.
            if (($contra = app(AlbaranPropioNc::class)->contradiccion($dte, $efectivo, $datos)) !== null) {
                return back()->with('error', 'No se agregó nada: '.$contra
                    .' Revisá cuál corresponde; el albarán de la nota se corrige en el documento.');
            }
        }

        $salaCodigo = OrdenCompra::salaDesde($dte->numero_orden_compra);
        $salaNombre = Sala::nombrePreferido($salaCodigo, $dte->clienteSucursal?->nombre);

        // Albarán e item en UNA transacción: si el item no se puede crear, no queda un
        // albarán registrado a medias que después aparezca solo en otra ficha.
        try {
            [$albaran, $avisoAlbaran] = DB::transaction(function () use ($efectivo, $sinAlbaran, $datos, $dte, $esNc, $lote, $salaNombre) {
                $albaran = null;
                $avisoAlbaran = null;

                if ($efectivo !== null) {
                    // Mismo punto único de persistencia que el resto (número + OC, nunca
                    // duplica). No se usa `dte_albaranes.ppq_albaran_id`: ese vínculo se
                    // resolvió por el correlativo suelto, que no distingue AC04 de AC01.
                    $albaran = $this->registrarAlbaran([
                        'numero_albaran' => $efectivo['numero'],
                        'numero_orden_compra' => $dte->numero_orden_compra,
                        'fecha_albaran' => $efectivo['fecha'],
                        'monto_albaran' => $efectivo['monto'],
                    ], 'manual');

                    // El persistidor completa el monto vacío de un registro previo pero no la
                    // fecha. Acá, y solo acá, se completa una fecha VACÍA con la de la nota
                    // (ya comprobada compatible); nunca se pisa una existente. Dentro de la
                    // transacción: si el item falla, la fecha vuelve a quedar vacía.
                    if ($albaran->fecha_albaran === null && $efectivo['fecha'] !== null) {
                        $albaran->update(['fecha_albaran' => $efectivo['fecha']]);
                    }
                } elseif (! $sinAlbaran && ! empty($datos['ppq_albaran_id'])) {
                    $albaran = PpqAlbaran::find($datos['ppq_albaran_id']);
                    if ($albaran && $albaran->yaVinculado()) {
                        $avisoAlbaran = 'El albarán '.$albaran->numero_albaran.' ya estaba vinculado a otro item.';
                    }
                } elseif (! $sinAlbaran && filled($datos['numero_albaran'] ?? null)) {
                    // Albarán manual (NC sin albarán propio guardado): registra/reusa por número + OC.
                    $albaran = $this->registrarAlbaran($datos + ['numero_orden_compra' => $dte->numero_orden_compra], $esNc ? 'manual' : 'gmail');
                }

                $lote->items()->create([
                    'dte_id' => $dte->id,
                    'origen' => 'local',
                    'numero_control' => $dte->numero_control,
                    'codigo_generacion' => $dte->codigo_generacion,
                    'sello_recepcion' => $dte->sello_recepcion,
                    'tipo_dte' => $dte->tipo_dte?->value,
                    'fecha_documento' => $dte->fecha_emision,
                    'ppq_albaran_id' => $albaran?->id,
                    'sin_albaran' => $sinAlbaran || $albaran === null,
                    'numero_orden_compra' => $dte->numero_orden_compra,
                    'sala_nombre' => $salaNombre,
                    'monto_dte' => $dte->total_pagar,
                    'monto_albaran' => $albaran?->monto_albaran,
                    'observaciones' => $datos['observaciones'] ?? null,
                ]);

                return [$albaran, $avisoAlbaran];
            });
        } catch (AlbaranDadoDeBajaException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Enriquecer el mapa auxiliar de PPQ (no fiscal) para futuros documentos de esta
        // sala. Después del alta: si el alta falla, no hay nada que recordar.
        PpqSala::recordar($salaCodigo, $salaNombre, 'local');

        $tipoTxt = $esNc ? 'NC (resta)' : 'CCF';
        $mensaje = $albaran === null ? $tipoTxt.' agregado al lote sin albarán.' : $tipoTxt.' agregado al lote.';
        if ($efectivo !== null && $albaran !== null) {
            $mensaje .= ' Se reutilizó el albarán guardado en la nota ('.$albaran->numero_albaran.')';
            // Lo que dice el mensaje sale de lo PERSISTIDO en el albarán PPQ, no de lo que
            // llegó en el envío: un registro previo compatible puede haber aportado datos.
            $persistido = ['fecha' => $albaran->fecha_albaran, 'monto' => $albaran->monto_albaran];
            $completados = array_values(array_filter($propio['faltantes'], fn ($c) => $persistido[$c] !== null));
            $pendientes = array_values(array_diff($propio['faltantes'], $completados));
            $mensaje .= $completados === [] ? ', sin capturarlo de nuevo.' : '; se completó en PPQ: '.implode(' y ', $completados).'.';
            if ($pendientes !== []) {
                $mensaje .= ' Sigue sin '.implode(' ni ', $pendientes).'.';
            }
        }
        if ($otroLote) {
            $mensaje .= ' Aviso: ya estaba usado en el lote #'.$otroLote.'.';
        }
        if ($avisoAlbaran) {
            $mensaje .= ' '.$avisoAlbaran;
        }

        return back()->with('status', $mensaje);
    }

    /**
     * Consulta de los items que representan ESTE MISMO documento, según la regla única
     * de {@see IdentidadPpq}: el vínculo explícito `dte_id` o el número de control
     * NORMALIZADO. La usan por igual la vía local y la de Gmail, que es lo que impide
     * que una copia local y una copia de Gmail pasen por documentos distintos.
     *
     * Nunca casa por correlativo suelto, orden de compra, monto ni sala: una OC ampara
     * varios CCF de la misma sala y el correlativo `0986` existe en P001 y en P002. Con
     * cualquiera de esos, el aviso diría «ya cobrado» sobre algo que nadie cobró.
     *
     * Devuelve una consulta SIN ejecutar para que el llamador la acote al lote que le
     * interesa (dentro del mismo lote, o en cualquier otro).
     */
    private function itemsEquivalentes(?int $dteId, ?string $numeroControl): Builder
    {
        $clave = IdentidadPpq::normalizar($numeroControl);

        return PpqItem::query()->where(function (Builder $q) use ($dteId, $clave) {
            if ($dteId !== null) {
                $q->where('dte_id', $dteId);
            }

            if ($clave !== null) {
                $q->orWhere(IdentidadPpq::columnaNormalizada(), $clave);
            }

            // Sin ninguna de las dos llaves no hay identidad posible: no se puede
            // afirmar que algo sea el mismo documento, así que no coincide con nada.
            if ($dteId === null && $clave === null) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /** Agrega al lote un CCF/NC resuelto desde Gmail (snapshot, sin DTE local). */
    private function agregarDesdeGmail(Request $request, PpqLote $lote): RedirectResponse
    {
        $d = $request->validate([
            'numero_control' => ['required', 'string', 'max:40'],
            'codigo_generacion' => ['nullable', 'string', 'max:40'],
            'sello_recepcion' => ['nullable', 'string'],
            'tipo_dte' => ['nullable', 'string', 'max:2'],
            'fecha_documento' => ['nullable', 'date'],
            'numero_orden_compra' => ['nullable', 'string'],
            'monto_dte' => ['nullable', 'numeric'],
            'monto_albaran' => ['nullable', 'numeric'],
            'numero_albaran' => ['nullable', 'string'],
            'fecha_albaran' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'gmail_message_id' => ['nullable', 'string'],
            'sala_nombre' => ['nullable', 'string', 'max:255'],
        ]);

        // La MISMA regla de identidad que la vía local. Comparar el número crudo solo
        // acertaba cuando ambos lados estaban escritos igual; normalizado, un item
        // cargado como `DTE03…0986` y este correo con `DTE-03-…-0986` son el mismo
        // documento, y un item LOCAL del mismo documento también se detecta.
        if ($this->itemsEquivalentes(null, $d['numero_control'])->where('ppq_lote_id', $lote->id)->exists()) {
            return back()->with('error', 'Ese CCF/NC ya está en este lote.');
        }
        $otroLote = $this->itemsEquivalentes(null, $d['numero_control'])
            ->where('ppq_lote_id', '!=', $lote->id)
            ->orderBy('id')
            ->value('ppq_lote_id');

        // "Agregar sin albarán": incluye el CCF/NC dejando vacíos los datos del
        // albarán, aunque haya uno encontrado o esté incompleto (NC/casos especiales).
        $sinAlbaran = $request->boolean('sin_albaran');
        // En la NC el albarán se captura a MANO (no llega por correo); se registra
        // con origen 'manual'. El CCF reusa el albarán parseado de Gmail.
        $esNc = ($d['tipo_dte'] ?? null) === '05';

        // Albarán: registra/reusa por número (si vino y no se pidió "sin albarán").
        $albaran = null;
        if (! $sinAlbaran && filled($d['numero_albaran'] ?? null)) {
            try {
                $albaran = $this->registrarAlbaran($d, $esNc ? 'manual' : 'gmail');
            } catch (AlbaranDadoDeBajaException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        // Nombre de sala: el que ya resolvió la búsqueda (viene en el form) o, si no, se busca
        // el DTE local (el CCF lo emite este sistema) por código de generación, control u OC.
        $salaNombre = $d['sala_nombre'] ?? null;
        if (blank($salaNombre)) {
            $salaNombre = app(SalaResolver::class)->nombre(
                $d['numero_orden_compra'] ?? null,
                $d['codigo_generacion'] ?? null,
                $d['numero_control'],
            );
        }
        // Enriquecer el mapa auxiliar de PPQ (no fiscal). Si el nombre vino del formulario
        // (revisado por quien concilia) se marca 'manual' para que no lo pise una fuente auto.
        PpqSala::recordar(
            OrdenCompra::salaDesde($d['numero_orden_compra'] ?? null),
            $salaNombre,
            filled($d['sala_nombre'] ?? null) ? 'manual' : 'gmail',
        );

        $lote->items()->create([
            'origen' => 'gmail',
            'numero_control' => $d['numero_control'],
            'codigo_generacion' => $d['codigo_generacion'] ?? null,
            'sello_recepcion' => $d['sello_recepcion'] ?? null,
            'tipo_dte' => $d['tipo_dte'] ?? null,
            'fecha_documento' => $d['fecha_documento'] ?? null,
            'gmail_message_id' => $d['gmail_message_id'] ?? null,
            'ppq_albaran_id' => $albaran?->id,
            'sin_albaran' => $sinAlbaran || $albaran === null,
            'numero_orden_compra' => $d['numero_orden_compra'] ?? null,
            'sala_nombre' => $salaNombre,
            'monto_dte' => $d['monto_dte'] ?? 0,
            'monto_albaran' => $albaran?->monto_albaran,
            'observaciones' => $d['observaciones'] ?? null,
        ]);

        $tipoTxt = $esNc ? 'NC (resta)' : 'CCF';
        $msg = $albaran === null
            ? $tipoTxt.' agregado al PPQ sin albarán.'
            : $tipoTxt.' agregado al PPQ con albarán '.$albaran->numero_albaran.'.';
        if ($otroLote) {
            $msg .= ' Aviso: ya estaba usado en el lote #'.$otroLote.'.';
        }

        return back()->with('status', $msg);
    }

    /**
     * Registra (o reusa) un albarán por número + OC. `$origen` = 'gmail' (parseado del
     * correo) o 'manual' (capturado a mano para una NC).
     *
     * Las reglas de identidad y autocorrección viven en AlbaranPersistidor, compartidas
     * con la sincronización automática desde Gmail (`ppq:sincronizar-albaranes`), para que
     * el alta manual y la automática no se separen nunca.
     *
     * La SALA no se toca acá a propósito: `registrar()` deja `sala_codigo` y
     * `cliente_sucursal_id` intactos. Resolver el vínculo fiscal con la sucursal es
     * exclusivo de la sincronización automática, que además reporta las excepciones.
     *
     * @throws AlbaranDadoDeBajaException si esa identidad existe pero está dada de baja;
     *                                    los llamadores la traducen en un mensaje de error.
     */
    private function registrarAlbaran(array $d, string $origen): PpqAlbaran
    {
        return app(AlbaranPersistidor::class)->registrar($d, $origen);
    }

    public function destroy(PpqLote $lote, PpqItem $item): RedirectResponse
    {
        abort_unless($item->ppq_lote_id === $lote->id, 404);

        if (! $lote->esEditable()) {
            return back()->with('error', 'El lote está en estado '.$lote->estado->label().' y no admite cambios.');
        }

        $item->delete();

        return back()->with('status', 'Item quitado del lote.');
    }
}
