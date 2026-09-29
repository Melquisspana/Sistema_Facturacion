<?php

namespace App\Services\Ppq;

use App\Models\Dte;
use App\Models\PpqAlbaran;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Support\Albaran;
use App\Support\IdentidadPpq;
use App\Support\NumeroAlbaran;
use Illuminate\Support\Carbon;

/**
 * CRITERIO ÚNICO sobre el albarán PROPIO de una nota de crédito local al llevarla a PPQ.
 *
 * La NC ya trae guardado en `dte_albaranes` el albarán de crédito que la originó. Este
 * servicio responde, en un solo lugar para la ficha y para el alta del item, qué se puede
 * hacer con él:
 *
 *   · SIN_DATOS  — no hay albarán guardado: se captura a mano, como siempre.
 *   · COMPLETO   — identidad válida con fecha y monto: se reutiliza sin pedir nada.
 *   · PARCIAL    — identidad válida pero falta fecha o monto: se reutiliza lo guardado
 *                  y solo se piden los faltantes, para el item PPQ (la NC no se toca).
 *   · INVALIDO   — lo guardado no identifica un albarán de crédito coherente: se detiene
 *                  con la explicación, sin afirmar ninguna reutilización.
 *
 * El monto fiscal de la nota NUNCA sustituye al del albarán: son magnitudes distintas.
 *
 * SOLO LECTURA salvo que se diga lo contrario: no escribe nada. Las consultas de
 * {@see contradiccion()} corren solo al guardar, nunca al dibujar la ficha.
 */
class AlbaranPropioNc
{
    public const SIN_DATOS = 'sin_datos';

    public const COMPLETO = 'completo';

    public const PARCIAL = 'parcial';

    public const INVALIDO = 'invalido';

    public function __construct(private readonly PerfilDocumentoResolver $perfiles) {}

    /**
     * Estado del albarán guardado de la NC, con los datos reutilizables.
     *
     * Usa la relación ya cargada (la búsqueda la precarga): no consulta por fila.
     *
     * @return array{estado: string, numero: ?string, fecha: ?string, monto: ?string, faltantes: array<int, string>, motivo: ?string}
     */
    public function evaluar(Dte $nc): array
    {
        $vacio = ['estado' => self::SIN_DATOS, 'numero' => null, 'fecha' => null, 'monto' => null, 'faltantes' => [], 'motivo' => null];

        if ($nc->tipo_dte?->value !== '05') {
            return $vacio;
        }

        $guardado = $nc->albaran;

        if ($guardado === null || (blank($guardado->numero_canonico) && blank($guardado->numero))) {
            return $vacio;
        }

        $base = [
            'numero' => $guardado->numero_canonico ?: null,
            'fecha' => optional($guardado->fecha)->format('Y-m-d'),
            'monto' => $guardado->total !== null ? (string) $guardado->total : null,
        ];

        if (($motivo = $this->motivoIdentidadInvalida($nc)) !== null) {
            return ['estado' => self::INVALIDO, 'faltantes' => [], 'motivo' => $motivo] + $base;
        }

        $faltantes = [];
        if ($base['fecha'] === null) {
            $faltantes[] = 'fecha';
        }
        if ($base['monto'] === null) {
            $faltantes[] = 'monto';
        }

        return [
            'estado' => $faltantes === [] ? self::COMPLETO : self::PARCIAL,
            'faltantes' => $faltantes,
            'motivo' => null,
        ] + $base;
    }

    /**
     * Por qué lo guardado no identifica un albarán de crédito válido; null si lo hace.
     *
     * El canónico tiene que desarmarse y coincidir con sus propias piezas (tipo, sala,
     * número), no puede ser el albarán de ENTREGA y, si el perfil del cliente declara el
     * tipo de esta modalidad, tiene que ser ese.
     */
    private function motivoIdentidadInvalida(Dte $nc): ?string
    {
        $g = $nc->albaran;
        $partido = NumeroAlbaran::desde((string) $g->numero_canonico);
        $texto = (string) ($g->numero_canonico ?: $g->numero);

        if ($partido === null || strtoupper(trim((string) $g->numero_canonico)) !== $partido->canonico) {
            return "El albarán guardado en la nota ({$texto}) no tiene un número completo reconocible.";
        }

        $tipo = strtoupper(trim((string) $g->tipo_codigo));
        $sala = filled($g->sala_codigo) ? str_pad(trim((string) $g->sala_codigo), 4, '0', STR_PAD_LEFT) : null;
        $numero = filled($g->numero) ? (ltrim(trim((string) $g->numero), '0') ?: '0') : null;

        if (($tipo !== '' && $tipo !== $partido->tipo)
            || ($sala !== null && $sala !== $partido->sala)
            || ($numero !== null && $numero !== $partido->numero)) {
            return "El albarán guardado en la nota ({$texto}) no coincide con su tipo, sala o número registrados.";
        }

        if ($partido->tipo === PpqAlbaran::tipoDeEntrega()) {
            return "El albarán guardado en la nota ({$texto}) es de entrega, no de crédito.";
        }

        $esperado = strtoupper((string) $this->perfiles->reglaNotaCredito($nc)?->codigo_externo);
        if ($esperado !== '' && $esperado !== $partido->tipo) {
            return "El albarán guardado en la nota ({$texto}) es {$partido->tipo}, pero esta modalidad corresponde a {$esperado}.";
        }

        return null;
    }

    /**
     * Contradicción entre el albarán propio (ya con los faltantes completados) y lo que
     * llega en el envío o ya existe en `ppq_albaranes`. Null si no hay ninguna.
     *
     * Se llama ANTES de escribir: ninguna evidencia existente se sobrescribe ni se elige
     * una versión por cuenta propia.
     *
     * @param  array{numero: string, fecha: ?string, monto: ?string}  $efectivo
     * @param  array<string, mixed>  $datos  lo recibido en el POST
     */
    public function contradiccion(Dte $nc, array $efectivo, array $datos): ?string
    {
        $clavePropia = self::clave($efectivo['numero']);

        $declarado = $datos['numero_albaran'] ?? null;
        if (filled($declarado) && self::clave($declarado) !== $clavePropia) {
            return 'la nota tiene guardado el albarán '.$efectivo['numero'].', pero el envío traía '.trim((string) $declarado).'.';
        }

        if (! empty($datos['ppq_albaran_id'])) {
            $elegido = PpqAlbaran::find($datos['ppq_albaran_id']);
            if ($elegido === null
                || self::clave($elegido->numero_albaran) !== $clavePropia
                || (string) $elegido->numero_orden_compra !== (string) $nc->numero_orden_compra
                || ($elegido->dte_id !== null && (int) $elegido->dte_id !== (int) $nc->id)) {
                return 'el albarán elegido ('.($elegido?->numero_albaran ?? '#'.$datos['ppq_albaran_id'])
                    .') no es el de esta nota ('.$efectivo['numero'].') o pertenece a otro documento u orden de compra.';
            }
        }

        // La MISMA identidad que usa el persistidor (número limpio + OC).
        $existente = PpqAlbaran::query()
            ->where('numero_albaran', Albaran::numeroLimpio($efectivo['numero']))
            ->where('numero_orden_compra', $nc->numero_orden_compra)
            ->first();

        if ($existente === null) {
            return null;
        }

        if ($existente->dte_id !== null && (int) $existente->dte_id !== (int) $nc->id) {
            return 'el albarán '.$efectivo['numero'].' ya está registrado en PPQ para otro documento.';
        }

        if ($efectivo['monto'] !== null && $existente->monto_albaran !== null
            && round((float) $existente->monto_albaran, 2) !== round((float) $efectivo['monto'], 2)) {
            return 'el albarán '.$efectivo['numero'].' ya está registrado en PPQ con monto $'
                .number_format((float) $existente->monto_albaran, 2).' y la nota dice $'
                .number_format((float) $efectivo['monto'], 2).'.';
        }

        $fechaExistente = optional($existente->fecha_albaran)->toDateString();
        if ($efectivo['fecha'] !== null && $fechaExistente !== null && $fechaExistente !== $efectivo['fecha']) {
            return 'el albarán '.$efectivo['numero'].' ya está registrado en PPQ con fecha '
                .$existente->fecha_albaran->format('d/m/Y').' y la nota dice '
                .Carbon::parse($efectivo['fecha'])->format('d/m/Y').'.';
        }

        // Otro documento ya usa este albarán en algún lote: sería acreditarlo dos veces.
        // Misma regla de identidad que los duplicados ({@see IdentidadPpq}): el vínculo
        // `dte_id` o, en los snapshots de Gmail, el número de control normalizado.
        $propio = IdentidadPpq::normalizar($nc->numero_control);
        $ajeno = $existente->items()->get(['dte_id', 'numero_control'])->contains(
            fn ($item) => $item->dte_id !== null
                ? (int) $item->dte_id !== (int) $nc->id
                : IdentidadPpq::normalizar($item->numero_control) !== $propio
        );
        if ($ajeno) {
            return 'el albarán '.$efectivo['numero'].' ya está vinculado en PPQ a otro documento.';
        }

        return null;
    }

    /** Identidad comparable: el canónico si se reconoce, si no el texto limpio. */
    public static function clave(?string $numero): ?string
    {
        $limpio = Albaran::numeroLimpio($numero);

        if ($limpio === null) {
            return null;
        }

        return NumeroAlbaran::desde($limpio)?->canonico
            ?? strtoupper((string) preg_replace('/\s+/', '', $limpio));
    }
}
