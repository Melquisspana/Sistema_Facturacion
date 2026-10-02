<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoVinculacionAlbaran;
use App\Enums\EstadoDte;
use App\Models\Cobros\CobroDocumento;
use App\Models\PpqAlbaran;
use App\Models\PpqItem;
use App\Models\User;
use App\Support\Albaran;
use App\Support\Dinero;
use App\Support\IdentidadPpq;
use App\Support\OrdenCompra;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Decide QUÉ ALBARÁN corresponde a un documento de cobro, y se niega a decidir cuando no
 * puede hacerlo sin suponer.
 *
 * ════════════════════════ La pregunta que este servicio contesta ════════════════════════
 *
 * No es «¿qué albarán se parece más?» sino «¿hay UNO SOLO que sea, sin contradicciones?».
 * Son preguntas distintas y la primera siempre tiene respuesta, que es justo el problema:
 * un albarán elegido por parecido presenta la factura con el respaldo de otra entrega, y
 * eso no se descubre hasta que el cliente lo rechaza semanas después.
 *
 * Por eso el resultado tiene tres formas y no dos: VINCULADO, SIN ALBARÁN y REVISAR. La
 * tercera no es un fallo del algoritmo: es la respuesta correcta cuando los datos no
 * alcanzan, y viene con su motivo y sus candidatos para que una persona resuelva en
 * segundos lo que la máquina no debe resolver nunca.
 *
 * ═══════════════════════════ Qué identifica y qué solo contradice ═══════════════════════════
 *
 * IDENTIFICAN (pueden CREAR un vínculo):
 *
 *   · el `dte_id` del albarán — vínculo explícito, el más fuerte;
 *   · la ORDEN DE COMPRA, sobre albaranes de ENTREGA (AC01) — es la llave que Calleja
 *     comparte entre el pedido y la factura.
 *
 * NO IDENTIFICAN NUNCA, ni juntos ni por separado:
 *
 *   · el IMPORTE — dos entregas del mismo día a la misma sala valen lo mismo con
 *     frecuencia, y una diferencia de centavos es normal (por eso existe la columna de
 *     diferencia en el formato del cliente);
 *   · la FECHA — un albarán se factura días después y varios caen el mismo día;
 *   · el CORRELATIVO suelto — `0986` existe en P001 y en P002.
 *
 * Esos tres sí sirven para lo contrario: para CONTRADECIR. Si el único candidato por OC
 * resulta ser de otra sala o de un período imposible, el vínculo no se hace y se explica.
 * Descartar es seguro; afirmar no.
 *
 * ═══════════════════════════ Auditar antes de automatizar ═══════════════════════════
 *
 * {@see auditar()} calcula el veredicto SIN escribir nada. Es el paso previo obligatorio:
 * permite ver sobre los datos reales cuántos vínculos saldrían únicos, cuántos ambiguos y
 * por qué, antes de dejar que el sistema los aplique solo. {@see aplicar()} solo escribe
 * lo que la auditoría ya dijo, y deja constancia de quién y cuándo.
 */
class VinculadorAlbaranes
{
    /**
     * Días de margen alrededor de la emisión del documento dentro de los que un albarán
     * es PLAUSIBLE. Fuera de eso no se descarta el candidato: se marca como contradicción
     * y va a revisión, porque un albarán de otro trimestre para la misma OC es
     * exactamente el caso que hay que mirar con ojos humanos.
     */
    private const DIAS_PLAUSIBLES = 120;

    /**
     * Veredicto para un documento, SIN escribir nada.
     *
     * @return array{
     *     estado: EstadoVinculacionAlbaran,
     *     albaran_id: ?int,
     *     motivo: ?string,
     *     candidatos: array<int, array<string, mixed>>
     * }
     */
    public function auditar(CobroDocumento $documento): array
    {
        if ($documento->estaInvalidado()) {
            return [
                'estado' => EstadoVinculacionAlbaran::SinAlbaran,
                'albaran_id' => null,
                'motivo' => 'El CCF fue invalidado en Hacienda; no se le vincula albarán.',
                'candidatos' => [],
            ];
        }

        // 1 · Vínculo EXPLÍCITO: el albarán ya dice a qué DTE pertenece.
        $explicitos = $documento->dte_id === null
            ? collect()
            : PpqAlbaran::where('dte_id', $documento->dte_id)->get();

        if ($explicitos->count() === 1) {
            // El vínculo explícito manda, pero no tapa en silencio un item PPQ histórico
            // que apunta con la misma identidad a OTRA entrega: eso se mira a mano.
            $historico = $this->evidenciaPpq($documento);
            // Contradicción positiva del historial (dos albaranes, identidad mezclada, OC
            // distinta, albarán reclamado por otro documento): tampoco se tapa.
            if (isset($historico['revisar'])) {
                // Acotado a la columna (VARCHAR 255): el comienzo ya dice que hay que revisar,
                // y el detalle completo queda en los candidatos.
                return $this->revisar(
                    Str::limit('El albarán apunta explícitamente a este documento, pero el historial PPQ se contradice: '
                        .$historico['revisar'], 250),
                    collect([$explicitos->first()])->concat($historico['candidatos'])->unique('id')->values(),
                );
            }
            if (isset($historico['albaran']) && $historico['albaran']->id !== $explicitos->first()->id) {
                return $this->revisar(
                    'El albarán apunta explícitamente a este documento, pero el lote PPQ '
                        .$historico['lotes'].' guardó otro albarán para el mismo CCF.',
                    collect([$explicitos->first(), $historico['albaran']]),
                );
            }

            return $this->veredictoUnico($documento, $explicitos->first(), 'vínculo explícito del albarán al documento');
        }

        if ($explicitos->count() > 1) {
            return $this->revisar(
                'Hay '.$explicitos->count().' albaranes apuntando al mismo documento. '
                    .'Dos entregas no pueden ser la misma factura: hay que decidir cuál.',
                $explicitos,
            );
        }

        // 1b · ALBARÁN GUARDADO en un item PPQ del circuito anterior para ESTE CCF. No es
        // una elección humana probada —el item pudo recibirlo prellenado— ni prueba de
        // presentación o pago: es un candidato con identidad, que pasa por las MISMAS
        // contradicciones y la misma regla de CCF que comparten OC que el de la OC.
        $historico = $this->evidenciaPpq($documento);
        if (isset($historico['revisar'])) {
            return $this->revisar($historico['revisar'], $historico['candidatos']);
        }
        if (isset($historico['albaran'])) {
            return $this->veredictoUnico($documento, $historico['albaran'], 'albarán guardado en el lote PPQ '.$historico['lotes']);
        }

        // 2 · ORDEN DE COMPRA sobre albaranes de ENTREGA.
        $oc = OrdenCompra::normalizar($documento->dte?->numero_orden_compra);

        if ($oc === '') {
            return [
                'estado' => EstadoVinculacionAlbaran::SinAlbaran,
                'albaran_id' => null,
                'motivo' => 'El documento no tiene orden de compra registrada, que es la llave con la que '
                    .'Calleja relaciona la entrega con la factura.',
                'candidatos' => [],
            ];
        }

        $candidatos = PpqAlbaran::query()
            ->deEntrega()
            ->whereRaw("REPLACE(REPLACE(numero_orden_compra, '-', ''), ' ', '') = ?", [$oc])
            ->get();

        if ($candidatos->isEmpty()) {
            return [
                'estado' => EstadoVinculacionAlbaran::SinAlbaran,
                'albaran_id' => null,
                'motivo' => "No hay ningún albarán de entrega registrado con la orden de compra {$oc}.",
                'candidatos' => [],
            ];
        }

        // Un albarán ya tomado por OTRO documento no es candidato: sería cobrar la misma
        // entrega dos veces. Se informa en vez de ignorarlo en silencio.
        $tomados = CobroDocumento::query()
            ->whereIn('ppq_albaran_id', $candidatos->pluck('id'))
            ->where('id', '!=', $documento->id)
            ->pluck('numero_control', 'ppq_albaran_id');

        $libres = $candidatos->reject(fn (PpqAlbaran $a) => $tomados->has($a->id));

        if ($libres->isEmpty()) {
            return $this->revisar(
                "Los albaranes de la orden {$oc} ya están vinculados a otros documentos ("
                    .$tomados->values()->implode(', ').').',
                $candidatos,
                $tomados,
            );
        }

        if ($libres->count() > 1) {
            return $this->revisar(
                'La orden de compra '.$oc.' tiene '.$libres->count().' albaranes de entrega sin vincular. '
                    .'La OC sola no dice cuál es: hay que elegirlo.',
                $libres,
                $tomados,
            );
        }

        return $this->veredictoUnico($documento, $libres->first(), "orden de compra {$oc}, albarán de entrega único");
    }

    /**
     * Lo que los items PPQ del circuito anterior dicen del albarán de ESTE CCF.
     *
     * Devuelve:
     *   · null — no hay evidencia utilizable: se sigue con la lógica de siempre;
     *   · ['albaran' => …, 'lotes' => …] — un único albarán de ENTREGA, sin contradicción
     *     de identidad ni de OC y sin otro documento que lo reclame (las contradicciones de
     *     importe, sala, cliente y período las aplica después {@see veredictoUnico()});
     *   · ['revisar' => motivo, 'candidatos' => …] — la evidencia existe pero se contradice.
     *
     * Solo para CCF locales con DTE y OC. La identidad es la de {@see IdentidadPpq}: el
     * `dte_id` del item o, en los snapshots de Gmail, el número de control COMPLETO
     * normalizado. Nunca el correlativo, el importe, la fecha ni la sala.
     *
     * @return array{albaran?: PpqAlbaran, lotes?: string, revisar?: string, candidatos?: Collection<int, PpqAlbaran>}|null
     */
    private function evidenciaPpq(CobroDocumento $documento): ?array
    {
        $dte = $documento->dte_id === null ? null : $documento->dte;

        if ($dte === null || $documento->tipo_dte !== '03' || $dte->tipo_dte?->value !== '03') {
            return null;
        }

        $oc = OrdenCompra::normalizar($dte->numero_orden_compra);
        $clave = IdentidadPpq::normalizar($dte->numero_control);

        // Seguimiento y DTE tienen que ser el mismo documento; si no, no hay identidad.
        if ($oc === '' || $clave === null || IdentidadPpq::normalizar($documento->numero_control) !== $clave) {
            return null;
        }

        $items = PpqItem::query()
            ->where(fn ($q) => $q->where('dte_id', $dte->id)
                ->orWhere(IdentidadPpq::columnaNormalizada(), $clave))
            ->with('lote:id,referencia')
            ->orderBy('id')
            ->get();

        // SEGUNDA IDENTIDAD. El mismo número de control puede existir en dos ambientes
        // ({@see IdentidadPpq}), así que en un snapshot sin `dte_id` el número solo no
        // acredita este DTE: hace falta el código de generación o el sello, iguales. Se
        // comparan tal cual, en mayúsculas y sin espacios alrededor; nada más laxo.
        $comparable = fn ($v): ?string => filled($v) ? strtoupper(trim((string) $v)) : null;
        $codigoDte = $comparable($dte->codigo_generacion);
        $selloDte = $comparable($dte->sello_recepcion);
        $contradice = fn ($delItem, ?string $delDte): bool => $comparable($delItem) !== null && $delDte !== null
            && $comparable($delItem) !== $delDte;
        $confirma = fn ($delItem, ?string $delDte): bool => $comparable($delItem) !== null && $comparable($delItem) === $delDte;

        // Un item que se dice el mismo documento por una llave y otro por otra no es
        // evidencia de nada: se muestra, no se usa. Vale también para los items con
        // `dte_id` exacto: un código o sello poblado que no es el del DTE los contradice.
        $contradictorios = $items->filter(fn (PpqItem $i) => ($i->dte_id !== null && (int) $i->dte_id !== (int) $dte->id)
            || (filled($i->numero_control) && IdentidadPpq::normalizar($i->numero_control) !== $clave)
            || (filled($i->tipo_dte) && $i->tipo_dte !== '03')
            || $contradice($i->codigo_generacion, $codigoDte)
            || $contradice($i->sello_recepcion, $selloDte));
        if ($contradictorios->isNotEmpty()) {
            return [
                'revisar' => 'Un item PPQ del circuito anterior mezcla la identidad de este CCF con la de otro documento '
                    .'(dte, número, código de generación o sello distintos); no se usa su albarán sin revisarlo.',
                'candidatos' => PpqAlbaran::whereIn('id', $contradictorios->pluck('ppq_albaran_id')->filter()->unique())->get(),
            ];
        }

        $conAlbaran = $items->filter(fn (PpqItem $i) => $i->tieneAlbaran());
        if ($conAlbaran->isEmpty()) {
            return null;
        }

        $ids = $conAlbaran->pluck('ppq_albaran_id')->unique()->values();
        if ($ids->count() > 1) {
            return [
                'revisar' => 'Los lotes PPQ del circuito anterior guardaron '.$ids->count().' albaranes distintos para este CCF.',
                'candidatos' => PpqAlbaran::whereIn('id', $ids)->get(),
            ];
        }

        // Borrado (dado de baja) o de un tipo que no prueba entrega: no es evidencia.
        $albaran = PpqAlbaran::find($ids->first());
        if ($albaran === null || ! $albaran->esDeEntrega()) {
            return null;
        }

        // OC presente y distinta en el item o en el albarán: contradicción positiva.
        $ocDistinta = $conAlbaran->contains(fn (PpqItem $i) => ($o = OrdenCompra::normalizar($i->numero_orden_compra)) !== '' && $o !== $oc);
        if ($ocDistinta || OrdenCompra::normalizar($albaran->numero_orden_compra) !== $oc) {
            return [
                'revisar' => "El lote PPQ guardó el albarán {$albaran->numero_albaran}, pero su orden de compra no es la "
                    ."del documento ({$oc}).",
                'candidatos' => collect([$albaran]),
            ];
        }

        // Solo acreditan los items de tipo 03, con OC PROPIA igual a la del DTE y con identidad
        // completa: `dte_id` exacto, o snapshot con código de generación o sello iguales.
        // Sin eso no es evidencia automática: se sigue con la lógica de la OC.
        // Un item SIN tipo no dice que sea un CCF: tampoco acredita.
        $usables = $conAlbaran->filter(fn (PpqItem $i) => $i->tipo_dte === '03'
            && OrdenCompra::normalizar($i->numero_orden_compra) === $oc
            && ((int) $i->dte_id === (int) $dte->id
                || $confirma($i->codigo_generacion, $codigoDte)
                || $confirma($i->sello_recepcion, $selloDte)));
        if ($usables->isEmpty()) {
            return null;
        }

        $ajenos = PpqItem::where('ppq_albaran_id', $albaran->id)
            ->whereNotIn('id', $items->pluck('id'))
            ->orderBy('id')
            ->pluck('numero_control');
        if ($ajenos->isNotEmpty()) {
            // Dos números como muestra y el total: el motivo tiene que caber en 255.
            $muestra = $ajenos->filter()->unique()->values();
            $lista = $muestra->take(2)->implode(', ').($muestra->count() > 2 ? ' y '.($muestra->count() - 2).' más' : '');

            return [
                'revisar' => "El albarán {$albaran->numero_albaran} guardado en PPQ también figura en items de otros documentos ("
                    .$lista.').',
                'candidatos' => collect([$albaran]),
            ];
        }

        return [
            'albaran' => $albaran,
            'lotes' => $this->referenciaLotes($usables),
        ];
    }

    /**
     * Procedencia BREVE para el motivo: los dos primeros lotes (por orden de item), cada
     * uno con su referencia acortada y su id, y cuántos más hay. `vinculacion_motivo` es
     * VARCHAR(255) y aplicar() lo persiste; el id deja rastrear el lote aunque la
     * referencia se recorte. Ej.: «PPQ-JUNIO (#12), PPQ-JULIO (#15) y 3 más».
     *
     * @param  Collection<int, PpqItem>  $items
     */
    private function referenciaLotes(Collection $items): string
    {
        $lotes = $items
            ->map(fn (PpqItem $i) => ['id' => (int) $i->ppq_lote_id, 'ref' => $i->lote?->referencia])
            ->unique('id')
            ->values();

        $texto = $lotes->take(2)
            ->map(fn (array $l) => filled($l['ref']) ? Str::limit((string) $l['ref'], 40).' (#'.$l['id'].')' : '#'.$l['id'])
            ->implode(', ');

        return $lotes->count() > 2 ? $texto.' y '.($lotes->count() - 2).' más' : $texto;
    }

    /**
     * Comprueba el candidato único contra lo que podría CONTRADECIRLO. Si algo no cuadra,
     * el resultado es «revisar» con el motivo; nunca un vínculo silencioso.
     *
     * @return array{estado: EstadoVinculacionAlbaran, albaran_id: ?int, motivo: ?string, candidatos: array<int, array<string, mixed>>}
     */
    private function veredictoUnico(CobroDocumento $documento, PpqAlbaran $albaran, string $porque): array
    {
        $contradicciones = $this->contradicciones($documento, $albaran);
        $ocupado = CobroDocumento::where('ppq_albaran_id', $albaran->id)->where('id', '!=', $documento->id)->first();
        if ($ocupado !== null) {
            $contradicciones[] = 'el albarán ya está vinculado a '.$ocupado->numero_control;
        }

        // La unicidad se comprueba contra TODOS los CCF vigentes, no solo los 500 visibles ni
        // los aún no vinculados. El importe no decide cuál de dos facturas es la buena.
        $oc = OrdenCompra::normalizar($albaran->numero_orden_compra);
        if ($oc !== '') {
            $competidores = CobroDocumento::where('tipo_dte', '03')
                ->where('id', '!=', $documento->id)
                ->whereHas('dte', fn ($q) => $q->whereRaw("REPLACE(REPLACE(numero_orden_compra, '-', ''), ' ', '') = ?", [$oc])
                    ->where('estado', '!=', EstadoDte::Invalidado->value))
                ->pluck('numero_control');
            if ($competidores->isNotEmpty()) {
                $contradicciones[] = 'otros CCF comparten la orden de compra: '.$competidores->implode(', ');
            }
        }

        if ($contradicciones !== []) {
            return $this->revisar(
                'Coincide por '.$porque.', pero algo no cuadra: '.implode('; ', $contradicciones).'.',
                collect([$albaran]),
            );
        }

        return [
            'estado' => EstadoVinculacionAlbaran::Vinculado,
            'albaran_id' => $albaran->id,
            'motivo' => 'Coincidencia única por '.$porque.'.',
            'candidatos' => [$this->resumen($albaran)],
        ];
    }

    /**
     * Lo que descartaría un candidato que por lo demás parecía el correcto.
     *
     * Ninguna de estas comprobaciones crea un vínculo: solo pueden impedirlo. Un dato que
     * no alcanza para afirmar sí alcanza para dudar, y dudar es barato.
     *
     * @return array<int, string>
     */
    private function contradicciones(CobroDocumento $documento, PpqAlbaran $albaran): array
    {
        $problemas = [];

        if ($albaran->dte_id !== null && $albaran->dte_id !== $documento->dte_id) {
            $problemas[] = 'el albarán apunta explícitamente a otro DTE';
        }
        if ($documento->monto === null || $albaran->monto_albaran === null) {
            $problemas[] = 'falta un importe para comprobar el documento y el albarán';
        } elseif (Dinero::comparar(Dinero::redondear($documento->monto), Dinero::redondear($albaran->monto_albaran)) !== 0) {
            $problemas[] = 'el importe del CCF ('.$documento->monto.') difiere del albarán ('.$albaran->monto_albaran.'); revisar la evidencia, no deducir una NC de la resta';
        }

        // SALA: la de la OC del documento contra la de la OC del albarán y la del número.
        $salaDocumento = OrdenCompra::salaDesde($documento->dte?->numero_orden_compra);
        $salaAlbaran = $albaran->sala_codigo ?: Albaran::salaDesdeNumero($albaran->numero_albaran);

        if (filled($salaDocumento) && filled($salaAlbaran) && $salaDocumento !== $salaAlbaran) {
            $problemas[] = "el documento es de la sala {$salaDocumento} y el albarán de la {$salaAlbaran}";
        }

        // PROVEEDOR/CLIENTE: la sucursal del albarán tiene que ser de este cliente.
        if ($albaran->cliente_sucursal_id !== null) {
            $albaran->loadMissing('clienteSucursal:id,cliente_id,nombre');
            $deOtroCliente = $albaran->clienteSucursal !== null
                && $albaran->clienteSucursal->cliente_id !== $documento->cliente_id;

            if ($deOtroCliente) {
                $problemas[] = 'el albarán pertenece a una sucursal de otro cliente';
            }
        }

        // PERÍODO: no identifica, pero un albarán de otro trimestre para la misma OC no se
        // vincula solo.
        if ($documento->fecha_emision !== null && $albaran->fecha_albaran !== null) {
            $dias = abs($albaran->fecha_albaran->diffInDays($documento->fecha_emision));

            if ($dias > self::DIAS_PLAUSIBLES) {
                $problemas[] = 'el albarán es de '.$albaran->fecha_albaran->format('d/m/Y')
                    .' y el documento de '.$documento->fecha_emision->format('d/m/Y')
                    .' ('.(int) $dias.' días de diferencia)';
            }
        }

        // TIPO: solo el albarán de ENTREGA respalda una factura. Uno de crédito (avería,
        // devolución) pertenece al circuito de notas de crédito, que es otro.
        if (! $albaran->esDeEntrega()) {
            $problemas[] = 'el albarán es de tipo '.($albaran->tipo_codigo ?: 'desconocido')
                .', que no prueba una entrega';
        }

        return $problemas;
    }

    /**
     * @param  Collection<int, PpqAlbaran>  $candidatos
     * @param  Collection<int, string>|null  $tomados
     * @return array{estado: EstadoVinculacionAlbaran, albaran_id: ?int, motivo: ?string, candidatos: array<int, array<string, mixed>>}
     */
    private function revisar(string $motivo, Collection $candidatos, ?Collection $tomados = null): array
    {
        return [
            'estado' => EstadoVinculacionAlbaran::Revisar,
            'albaran_id' => null,
            'motivo' => $motivo,
            'candidatos' => $candidatos
                ->map(fn (PpqAlbaran $a) => $this->resumen($a) + ['tomado_por' => $tomados?->get($a->id)])
                ->values()
                ->all(),
        ];
    }

    /**
     * Lo mínimo de un albarán para poder elegirlo desde la pantalla sin abrir otra.
     *
     * @return array<string, mixed>
     */
    private function resumen(PpqAlbaran $albaran): array
    {
        return [
            'id' => $albaran->id,
            'numero' => $albaran->numero_albaran,
            'tipo' => $albaran->tipo_codigo,
            'sala' => $albaran->sala_codigo,
            'fecha' => $albaran->fecha_albaran?->format('d/m/Y'),
            'monto' => $albaran->monto_albaran === null ? null : (string) $albaran->monto_albaran,
            'orden_compra' => $albaran->numero_orden_compra,
        ];
    }

    /**
     * Aplica el veredicto al documento y deja constancia. Devuelve el estado resultante.
     *
     * Escribir el motivo y los candidatos SIEMPRE —también cuando el vínculo sale limpio—
     * es lo que permite auditar después por qué se decidió así, sin tener que recalcularlo
     * con datos que para entonces ya cambiaron.
     *
     * `$soloSiCambia` es para la corrida AUTOMÁTICA: un documento que sigue «revisar» o
     * «sin albarán» entra de nuevo en cada pasada porque `ppq_albaran_id` sigue null, y sin
     * este resguardo reescribiría `vinculado_en` con el mismo veredicto cada hora. Compara
     * también los candidatos: dos albaranes distintos pueden producir el mismo motivo
     * «hay 2 candidatos», y la cola de revisión debe mostrar los actuales. El botón manual no lo usa: una persona que pide
     * revisar espera que quede constancia de cuándo lo hizo, aunque el resultado sea igual.
     */
    public function aplicar(CobroDocumento $documento, ?User $usuario = null, bool $soloSiCambia = false): EstadoVinculacionAlbaran
    {
        return DB::transaction(function () use ($documento, $usuario, $soloSiCambia) {
            $documento = CobroDocumento::lockForUpdate()->findOrFail($documento->id);
            $veredicto = $this->auditar($documento);
            if ($veredicto['albaran_id'] !== null) {
                $albaran = PpqAlbaran::lockForUpdate()->findOrFail($veredicto['albaran_id']);
                // Lectura actual bajo el bloqueo del albarán: dos solicitudes simultáneas
                // no pueden apropiarse de la misma entrega.
                $ocupado = CobroDocumento::where('ppq_albaran_id', $albaran->id)
                    ->where('id', '!=', $documento->id)->lockForUpdate()->first();
                if ($ocupado !== null) {
                    $veredicto = $this->revisar('El albarán ya fue vinculado a '.$ocupado->numero_control.'.', collect([$albaran]));
                } else {
                    $bajoBloqueo = $this->veredictoUnico($documento, $albaran, 'identidad comprobada bajo bloqueo del albarán');
                    // Si la comprobación bajo bloqueo confirma EXACTAMENTE el mismo vínculo,
                    // se guarda el porqué de la auditoría (vínculo explícito, OC o lote PPQ),
                    // que es la evidencia; si da otra cosa, gana la comprobación nueva.
                    $confirma = $bajoBloqueo['estado'] === EstadoVinculacionAlbaran::Vinculado
                        && $bajoBloqueo['albaran_id'] === $veredicto['albaran_id'];
                    $veredicto = $confirma ? $veredicto : $bajoBloqueo;
                }
            }

            // La columna es VARCHAR(255) y el motivo puede listar muchos CCF de la misma OC.
            // Se recorta SOLO al guardar (auditar() lo sigue dando entero); el comienzo
            // conserva la categoría y los candidatos se guardan completos.
            $motivoGuardado = CobroDocumento::recortarTextos([
                'vinculacion_motivo' => $veredicto['motivo'],
            ])['vinculacion_motivo'];

            if ($soloSiCambia
                && $documento->vinculacion_estado === $veredicto['estado']
                && $documento->ppq_albaran_id === $veredicto['albaran_id']
                && $documento->vinculacion_motivo === $motivoGuardado
                && $documento->vinculacion_candidatos === ($veredicto['candidatos'] ?: null)) {
                return $veredicto['estado'];
            }

            $documento->forceFill([
                'ppq_albaran_id' => $veredicto['albaran_id'],
                'vinculacion_estado' => $veredicto['estado']->value,
                'vinculacion_motivo' => $motivoGuardado,
                'vinculacion_candidatos' => $veredicto['candidatos'] ?: null,
                'vinculado_en' => now(),
                'vinculado_por' => $usuario?->id,
            ])->save();

            return $veredicto['estado'];
        }, 3);
    }

    /**
     * Vínculo elegido A MANO, saltándose la ambigüedad. Es la salida de «Revisar
     * vinculación»: una persona mira los candidatos y decide.
     *
     * Queda registrado quién y cuándo, y el motivo dice explícitamente que fue una
     * decisión humana: que un vínculo sea manual no lo hace menos válido, pero sí
     * distinto de uno que el sistema pudo afirmar solo.
     */
    public function vincularAMano(CobroDocumento $documento, PpqAlbaran $albaran, User $usuario, ?string $nota = null): void
    {
        DB::transaction(function () use ($documento, $albaran, $usuario, $nota) {
            $documento = CobroDocumento::lockForUpdate()->findOrFail($documento->id);
            if ($documento->estaInvalidado()) {
                throw ValidationException::withMessages(['ppq_albaran_id' => 'El CCF fue invalidado en Hacienda: vincule el albarán al CCF que lo reemplazó.']);
            }
            if (trim((string) $nota) === '') {
                throw ValidationException::withMessages(['nota' => 'Indique la evidencia que respalda esta vinculación.']);
            }
            $albaran = PpqAlbaran::lockForUpdate()->findOrFail($albaran->id);
            $ocupado = CobroDocumento::where('ppq_albaran_id', $albaran->id)->where('id', '!=', $documento->id)->lockForUpdate()->exists();
            if ($ocupado || ! $albaran->esDeEntrega()
                || ($albaran->dte_id !== null && $albaran->dte_id !== $documento->dte_id)
                || ($albaran->clienteSucursal !== null && $albaran->clienteSucursal->cliente_id !== $documento->cliente_id)) {
                throw ValidationException::withMessages(['ppq_albaran_id' => 'El albarán está ocupado, pertenece a otro documento/cliente o no es de entrega. Revise su origen antes de vincular.']);
            }
            $contradicciones = $this->contradicciones($documento, $albaran);

            $motivo = 'Vinculado a mano por '.$usuario->name
                .($contradicciones === [] ? '.' : ', pese a: '.implode('; ', $contradicciones).'.')
                .($nota ? ' Nota: '.$nota : '');

            $documento->forceFill([
                'ppq_albaran_id' => $albaran->id,
                'vinculacion_estado' => EstadoVinculacionAlbaran::Vinculado->value,
                'vinculacion_motivo' => $motivo,
                'vinculacion_candidatos' => [$this->resumen($albaran)],
                'vinculado_en' => now(),
                'vinculado_por' => $usuario->id,
            ])->save();

            activity('cobros_vinculacion')
                ->performedOn($documento)
                ->causedBy($usuario)
                ->withProperties([
                    'albaran_id' => $albaran->id,
                    'albaran_numero' => $albaran->numero_albaran,
                    'contradicciones' => $contradicciones,
                    'nota' => $nota,
                ])
                ->log('vinculó a mano el albarán del documento de cobro');
        }, 3);
    }

    /**
     * Audita en bloque, sin escribir. Devuelve el recuento por estado y el detalle, que es
     * lo que se mira ANTES de dejar que la vinculación corra sola.
     *
     * @param  Collection<int, CobroDocumento>  $documentos
     * @return array{resumen: array<string, int>, detalle: array<int, array<string, mixed>>}
     */
    public function auditarLote(Collection $documentos): array
    {
        $resumen = ['vinculado' => 0, 'sin_albaran' => 0, 'revisar' => 0];
        $detalle = [];

        foreach ($documentos as $documento) {
            $veredicto = $this->auditar($documento);
            $resumen[$veredicto['estado']->value]++;
            $detalle[] = [
                'documento' => $documento,
                'estado' => $veredicto['estado'],
                'motivo' => $veredicto['motivo'],
                'candidatos' => $veredicto['candidatos'],
            ];
        }

        return ['resumen' => $resumen, 'detalle' => $detalle];
    }

    /** Fecha de referencia para los cálculos de plausibilidad. Aislada para las pruebas. */
    protected function hoy(): Carbon
    {
        return Carbon::today();
    }
}
