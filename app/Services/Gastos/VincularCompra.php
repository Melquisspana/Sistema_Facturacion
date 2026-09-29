<?php

namespace App\Services\Gastos;

use App\Models\DocumentoRecibido;
use App\Models\Gastos\Fuente;
use App\Models\Gastos\Gasto;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Compras como fuente OPCIONAL. Todo gasto puede registrarse sin pasar por acá.
 *
 * El riesgo central del módulo es crear una deuda que no existe, y viene casi
 * siempre de un documento: importar dos veces la misma factura, o convertir en
 * deuda un papel que no representa un monto por pagar. Los tres controles:
 *
 *  1. UN DOCUMENTO ORIGINA UNA SOLA DEUDA. Lo garantiza un índice único en base
 *     (`deuda_unica`), no una consulta previa que dos peticiones simultáneas
 *     podrían pasar a la vez.
 *  2. Si el gasto YA EXISTE, el documento se cuelga como `respaldo`. No se crea
 *     otra obligación: adjuntar el papel que llegó tarde no duplica lo que se
 *     debe.
 *  3. NO TODO `total` ES DEUDA. Las retenciones (tipo 07) llevan monto sujeto a
 *     retención, y las notas de crédito RESTAN. Ninguno de los dos se convierte
 *     en obligación; se ofrecen como ajuste o como respaldo, con revisión humana.
 *
 * El vínculo no toca el `estado` de Compras: una factura puede estar enviada a
 * contabilidad y pendiente de pago, y otra pagada y sin enviar.
 */
final class VincularCompra
{
    /** Tipos que NUNCA generan una obligación por su `total`. */
    public const NO_GENERAN_DEUDA = [
        '05' => 'Es una nota de crédito: resta deuda, no la crea. Aplicala como ajuste sobre el gasto que corrige.',
        '07' => 'Es un comprobante de retención: su total es el monto sujeto a retención, no un importe por pagar.',
    ];

    public function __construct(private AccesoGastos $acceso) {}

    /** ¿Es un documento que RESTA deuda, o sea aplicable como crédito? */
    public function esNotaDeCredito(DocumentoRecibido $documento): bool
    {
        return $documento->tipo_documento === '05';
    }

    /**
     * Cuánto de una nota de crédito ya se aplicó y cuánto queda por aplicar.
     *
     * Una NC puede repartirse entre varias deudas —es normal cuando cubre parte de
     * un pedido—, así que el control no puede ser «se usó o no se usó»: es acumulado
     * contra el total del documento. Ese acumulado es lo que impide descontarla dos
     * veces, y el remanente es lo que queda identificado como pendiente de aplicar en
     * vez de perderse.
     *
     * Los ajustes REVERTIDOS no cuentan como aplicados: devuelven su parte al
     * remanente, igual que una reversión de pago devuelve saldo a la deuda.
     *
     * @return array{total: int, aplicado: int, disponible: int}
     */
    public function creditoDelDocumento(DocumentoRecibido $documento): array
    {
        $total = $documento->total !== null
            ? Dinero::centavos(number_format((float) $documento->total, 2, '.', ''))
            : 0;

        $aplicado = DB::table('gastos_ajustes')
            ->where('documento_recibido_id', $documento->id)
            ->where('direccion', 'credito')
            ->whereNull('revertido_at')
            ->pluck('importe')
            ->sum(fn ($v) => Dinero::centavos((string) $v));

        return [
            'total' => $total,
            'aplicado' => $aplicado,
            'disponible' => max($total - $aplicado, 0),
        ];
    }

    /** Notas de crédito de Compras con remanente sin aplicar. */
    public function notasDeCreditoConRemanente(): Collection
    {
        return DocumentoRecibido::where('tipo_documento', '05')
            ->whereNotNull('total')
            ->orderByDesc('fecha_dte')
            ->get()
            ->map(fn (DocumentoRecibido $d) => (object) array_merge(['documento' => $d], $this->creditoDelDocumento($d)))
            ->filter(fn ($fila) => $fila->disponible > 0)
            ->values();
    }

    /** ¿Este documento puede originar una deuda, y si no, por qué? */
    public function motivoNoGeneraDeuda(DocumentoRecibido $documento): ?string
    {
        if (isset(self::NO_GENERAN_DEUDA[$documento->tipo_documento])) {
            return self::NO_GENERAN_DEUDA[$documento->tipo_documento];
        }

        if (! in_array($documento->tipo_documento, DocumentoRecibido::TIPOS_SOPORTADOS, true)) {
            return 'El tipo de documento no tiene un total interpretado en este módulo. Revisalo antes de convertirlo en deuda.';
        }

        if (blank($documento->total) || (float) $documento->total <= 0) {
            return 'El documento no trae un total utilizable. Registrá el gasto a mano con el importe revisado.';
        }

        return null;
    }

    /** El gasto que ya nació de este documento, si existe. */
    public function gastoDeLaDeuda(DocumentoRecibido $documento): ?Gasto
    {
        $fuente = Fuente::where('documento_recibido_id', $documento->id)->where('papel', 'deuda')->first();

        return $fuente?->gasto;
    }

    /**
     * Datos con los que PRELLENAR «Registrar gasto». No crea nada: el operador sigue
     * revisando clasificación, período, vencimiento y responsable, que el documento
     * no trae. La moneda tampoco viaja en el modelo de Compras, así que se propone la
     * predeterminada y se deja a la vista.
     *
     * ───────────── EMITIDO NO ES VENCE, y antes acá se confundían ─────────────
     *
     * Este método proponía `fecha_dte` como vencimiento. Son dos fechas distintas y
     * en un recibo de servicio casi nunca coinciden: DELSUR emite el 3 y da hasta
     * el 20 y pico para pagar; un CCF a crédito se emite hoy y vence a treinta días.
     * Proponer la de emisión no es una aproximación cómoda, es una fecha límite
     * FALSA y más temprana que la real, que después dispara avisos de vencimiento
     * sobre algo que todavía no vence y ensucia la cifra de vencido.
     *
     * El modelo de Compras no guarda fecha límite —el parser extrae nueve campos y
     * ninguno lo es—, así que la respuesta honesta es NO PROPONER NINGUNA. Se deja
     * vacía para que una persona la escriba mirando el papel. Un gasto sin
     * vencimiento es un estado legítimo del módulo: no suma a vencido y no genera
     * avisos, que es exactamente lo correcto mientras nadie sepa la fecha.
     *
     * La fecha de emisión SÍ viaja, en su propia clave y con su propio nombre, para
     * que la pantalla pueda mostrarla como dato del documento sin que nada la
     * confunda con un vencimiento.
     *
     * @return array<string, mixed>
     */
    public function prellenado(DocumentoRecibido $documento): array
    {
        return [
            'beneficiario' => $documento->emisor_nombre,
            'concepto' => trim(($documento->tipo_documento === '03' ? 'CCF ' : 'Documento ')
                .($documento->numero_control ?: $documento->codigo_generacion ?: '')
                .' · '.($documento->emisor_nombre ?: '')),
            'importe' => $documento->total !== null ? Dinero::decimal(Dinero::centavos(number_format((float) $documento->total, 2, '.', ''))) : null,
            'moneda' => config('gastos.monedas')[0],
            // Vacío a propósito. Ver la explicación de arriba.
            'vence' => null,
            'fecha_documento' => $documento->fecha_dte?->format('Y-m-d'),
            'naturaleza' => 'compra',
            'documentacion' => 'adjunto',
        ];
    }

    /**
     * Cuelga el documento de un gasto.
     *
     * `deuda` es para el acto que convierte el papel en algo que se debe, y eso pasa en
     * dos momentos: cuando el gasto NACE del documento, y cuando un documento le pone la
     * cifra a una obligación que estaba esperándola —hasta entonces esa obligación no
     * debía nada—. En los dos casos es el índice único de `deuda_unica` el que impide
     * que el mismo papel origine una segunda deuda.
     *
     * Para un gasto que ya tenía importe, el papel es siempre `respaldo`: adjuntar el
     * documento que llegó tarde no puede cambiar lo que se debe.
     */
    public function vincular(User $usuario, Gasto $gasto, DocumentoRecibido $documento, string $papel): Fuente
    {
        abort_unless($usuario->activo && $usuario->can('gastos.registrar'), 403);
        abort_unless($this->acceso->ver($usuario, $gasto), 403);

        Validator::make(['papel' => $papel], [
            'papel' => ['required', 'in:deuda,respaldo'],
        ])->validate();

        if ($papel === 'deuda' && ($motivo = $this->motivoNoGeneraDeuda($documento))) {
            throw ValidationException::withMessages(['documento' => $motivo]);
        }

        try {
            return DB::transaction(function () use ($usuario, $gasto, $documento, $papel) {
                $fuente = Fuente::create([
                    'gasto_id' => $gasto->id,
                    'documento_recibido_id' => $documento->id,
                    'papel' => $papel,
                    // Solo se llena para `deuda`: es la columna que el índice único vigila.
                    'deuda_unica' => $papel === 'deuda' ? $documento->id : null,
                    // Fotografía de lo reutilizado. Si Compras se resincroniza, el gasto
                    // conserva lo que su operador vio el día que vinculó.
                    'snapshot' => [
                        'emisor_nombre' => $documento->emisor_nombre,
                        'emisor_nit' => $documento->emisor_nit,
                        'emisor_nrc' => $documento->emisor_nrc,
                        'tipo_documento' => $documento->tipo_documento,
                        'numero_control' => $documento->numero_control,
                        'codigo_generacion' => $documento->codigo_generacion,
                        'fecha_dte' => $documento->fecha_dte?->toDateString(),
                        'total' => $documento->total,
                        'vinculado_at' => now()->toIso8601String(),
                    ],
                    'registrado_por' => $usuario->id,
                ]);

                DB::table('gastos_eventos')->insert([
                    'gasto_id' => $gasto->id,
                    'usuario_id' => $usuario->id,
                    'accion' => $papel === 'deuda' ? 'compra_origino_gasto' : 'compra_vinculada_como_respaldo',
                    'datos' => json_encode([
                        'documento_recibido_id' => $documento->id,
                        'numero_control' => $documento->numero_control,
                        'papel' => $papel,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return $fuente;
            });
        } catch (QueryException $e) {
            // El índice único hizo su trabajo: dos operadores intentaron convertir el
            // mismo documento en deuda a la vez, o alguien repitió el vínculo.
            if (! $this->esViolacionDeUnicidad($e)) {
                throw $e;
            }

            $yaVinculado = Fuente::where('gasto_id', $gasto->id)
                ->where('documento_recibido_id', $documento->id)->first();

            if ($yaVinculado) {
                return $yaVinculado; // repetir el mismo vínculo no es un error
            }

            throw ValidationException::withMessages([
                'documento' => 'Este documento ya originó un gasto. Vinculalo como respaldo en vez de crear otra deuda.',
            ]);
        }
    }

    private function esViolacionDeUnicidad(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains(mb_strtolower($e->getMessage()), 'unique');
    }
}
