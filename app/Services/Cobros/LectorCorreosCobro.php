<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\TipoEventoCobro;
use App\Models\Cliente;
use App\Models\Cobros\CobroCorreo;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Guarda y ASOCIA los correos con que el cliente responde a una solicitud.
 *
 * ════════════════════════ Solo lectura, y de verdad ════════════════════════
 *
 * Este servicio recibe mensajes YA LEÍDOS y los persiste. No manda correos, no responde, no
 * marca como leído y no mueve etiquetas: lo único que toca del buzón es la lectura que hace
 * quien le pasa los mensajes. El correo original se conserva entero, que es lo que permite
 * discutir después qué dijo exactamente el cliente.
 *
 * ════════════════ Cada tipo se asocia por la evidencia que le corresponde ════════════════
 *
 *  · El ACUSE se ata a la SOLICITUD por el nombre exacto del archivo que nombra
 *    —«RECIBIDO (000123202609040951)»— o, en su defecto, por una referencia que ya
 *    estuviera registrada. Nunca por fecha ni por cercanía: el acuse de una solicitud puede
 *    llegar después del de otra.
 *
 *  · Las OBSERVACIONES se atan a DOCUMENTOS por código de generación o número de control,
 *    que es lo único que identifica un documento sin ambigüedad. La referencia sola no
 *    alcanza: dice de qué envío habla, no de qué factura.
 *
 * Sin evidencia suficiente el correo queda `sin_asociar`, con su motivo y a la vista. Eso
 * es un resultado normal, no un fallo: alguien lo resuelve en un minuto, y descartarlo en
 * silencio sería perder justo el caso que hay que mirar.
 *
 * ════════════════════════════ Sin duplicados ════════════════════════════
 *
 * `gmail_message_id` es único, así que releer el buzón no crea filas nuevas ni vuelve a
 * aplicar nada. Y los eventos que genera llevan su propia llave de evidencia.
 */
class LectorCorreosCobro
{
    public function __construct(
        private readonly CorreoCobroParser $parser,
        private readonly SolicitudCobroService $solicitudes,
    ) {}

    /**
     * Procesa una tanda de mensajes ya leídos del buzón.
     *
     * @param  array<int, array{id: string, threadId?: ?string, asunto: ?string, cuerpo?: ?string, remitente?: ?string, fecha?: ?string}>  $mensajes
     * @return array{nuevos: int, repetidos: int, asociados: int, sin_asociar: int, correos: Collection<int, CobroCorreo>}
     */
    public function procesar(Cliente $cliente, array $mensajes): array
    {
        $resumen = ['nuevos' => 0, 'repetidos' => 0, 'asociados' => 0, 'sin_asociar' => 0];
        $correos = collect();

        foreach ($mensajes as $mensaje) {
            $existente = CobroCorreo::where('gmail_message_id', $mensaje['id'])->first();

            if ($existente !== null) {
                $resumen['repetidos']++;
                $correos->push($existente);

                continue;
            }

            $correo = DB::transaction(fn () => $this->guardarYAsociar($cliente, $mensaje));

            $resumen['nuevos']++;
            $correo->estado === 'asociado' ? $resumen['asociados']++ : $resumen['sin_asociar']++;
            $correos->push($correo);
        }

        return $resumen + ['correos' => $correos];
    }

    /** @param array<string, mixed> $mensaje */
    private function guardarYAsociar(Cliente $cliente, array $mensaje): CobroCorreo
    {
        $leido = $this->parser->interpretar($mensaje['asunto'] ?? null, $mensaje['cuerpo_actual'] ?? $mensaje['cuerpo'] ?? null);

        $correo = CobroCorreo::create([
            'cliente_id' => $cliente->id,
            'gmail_message_id' => (string) $mensaje['id'],
            'gmail_thread_id' => $mensaje['threadId'] ?? null,
            'tipo' => $leido['tipo'],
            'asunto' => $mensaje['asunto'] ?? null,
            'remitente' => $mensaje['remitente'] ?? null,
            'fecha_mensaje' => $this->parser->fechaMensaje($mensaje['fecha'] ?? null),
            'cuerpo' => $mensaje['cuerpo'] ?? null,
            'archivo_referido' => $leido['archivo_referido'],
            'referencia_calleja' => $leido['referencia_calleja'],
            'fecha_programada_pago' => $leido['fecha_programada_pago'],
            'procesado_en' => now(),
        ]);

        match ($leido['tipo']) {
            'recibido' => $this->asociarRecibido($cliente, $correo, $leido),
            'observaciones' => $this->asociarObservaciones($cliente, $correo, $leido),
            default => $this->sinAsociar($correo, 'No se reconoce como acuse de recibo ni como observaciones.'),
        };

        return $correo->refresh();
    }

    /**
     * El acuse: se busca la solicitud cuyo ARCHIVO nombra el correo.
     *
     * @param  array<string, mixed>  $leido
     */
    private function asociarRecibido(Cliente $cliente, CobroCorreo $correo, array $leido): void
    {
        $solicitud = $this->solicitudDelArchivo($cliente, $leido['archivo_referido'])
            ?? $this->solicitudDeLaReferencia($cliente, $leido['referencia_calleja']);

        if ($solicitud === null) {
            $this->sinAsociar(
                $correo,
                $leido['archivo_referido'] === null
                    ? 'El acuse no nombra ningún archivo y no hay una referencia que ya conozcamos.'
                    : 'Ningún envío corresponde al archivo '.$leido['archivo_referido'].'.',
            );

            return;
        }

        if ($leido['referencia_calleja'] === null) {
            // Sin referencia no se puede registrar el acuse completo: la referencia es lo
            // que después ata el ajuste QD del archivo de pagos a este envío.
            $this->sinAsociar(
                $correo,
                'Se identificó el envío '.$solicitud->referencia.', pero el correo no trae la referencia '
                    .'del cliente. Captúrela a mano.',
            );
            $correo->forceFill(['cobro_solicitud_id' => $solicitud->id])->save();

            return;
        }

        $this->solicitudes->registrarRecibido(
            $solicitud,
            $leido['referencia_calleja'],
            $leido['fecha_programada_pago'] === null ? null : Carbon::parse($leido['fecha_programada_pago']),
            $correo->fecha_mensaje,
            null,
            'Acuse leído del correo: '.($correo->asunto ?? 'sin asunto'),
        );

        $correo->forceFill([
            'cobro_solicitud_id' => $solicitud->id,
            'estado' => 'asociado',
            'motivo' => 'Acuse aplicado al envío '.$solicitud->referencia.'.',
        ])->save();
    }

    /**
     * Las observaciones: se atan a los DOCUMENTOS que el correo identifica.
     *
     * No mueven ningún estado de presentación ni de pago —una observación es una nota, no
     * un hecho sobre el dinero— y por eso pueden coexistir con cualquier estado sin pisarlo.
     *
     * @param  array<string, mixed>  $leido
     */
    private function asociarObservaciones(Cliente $cliente, CobroCorreo $correo, array $leido): void
    {
        $resultado = $this->documentosMencionados($cliente, $leido);
        $documentos = $resultado['documentos'];

        if ($documentos->isEmpty()) {
            $this->sinAsociar(
                $correo,
                $resultado['pendientes'] !== []
                    ? 'Documentos no localizados o con identidad contradictoria: '.implode(', ', $resultado['pendientes']).'. Queda para revisión manual.'
                    : 'El correo no menciona ningún código de generación ni número de control. Queda para revisión manual.',
            );

            if ($leido['referencia_calleja'] !== null) {
                $solicitud = $this->solicitudDeLaReferencia($cliente, $leido['referencia_calleja']);
                $correo->forceFill(['cobro_solicitud_id' => $solicitud?->id])->save();
            }

            return;
        }

        foreach ($documentos as $documento) {
            CobroEvento::firstOrCreate(
                [
                    'cobro_documento_id' => $documento->id,
                    'tipo' => TipoEventoCobro::Observacion->value,
                    'evidencia_hash' => null,
                    'referencia_linea' => 'gmail-'.$correo->gmail_message_id,
                ],
                [
                    'origen' => 'correo',
                    'fecha' => $correo->fecha_mensaje?->toDateString(),
                    'detalle' => $correo->asunto."\n".implode("\n", $resultado['detalles'][$documento->id] ?? []),
                    'evidencia_nombre' => $correo->asunto,
                    'datos' => [
                        'gmail_message_id' => $correo->gmail_message_id,
                        'referencia_calleja' => $leido['referencia_calleja'],
                    ],
                ],
            );
        }

        $correo->forceFill([
            'estado' => $resultado['pendientes'] === [] ? 'asociado' : 'sin_asociar',
            'motivo' => 'Observación anotada en '.$documentos->count().' documento(s).'
                .($resultado['pendientes'] === [] ? '' : ' Pendientes de localizar o revisar: '.implode(', ', $resultado['pendientes']).'.'),
            'cobro_solicitud_id' => $this->solicitudDeLaReferencia($cliente, $leido['referencia_calleja'])?->id,
        ])->save();
    }

    /**
     * Documentos que el correo identifica sin ambigüedad.
     *
     * @param  array<string, mixed>  $leido
     * @return array{documentos: Collection<int, CobroDocumento>, pendientes: array, detalles: array}
     */
    private function documentosMencionados(Cliente $cliente, array $leido): array
    {
        $documentos = collect();
        $pendientes = [];
        $detalles = [];
        foreach ($leido['documentos'] as $identidad) {
            $coincidencias = CobroDocumento::deCliente($cliente->id)
                ->when($identidad['codigo_generacion'] !== null, fn ($q) => $q->where('codigo_generacion', $identidad['codigo_generacion']))
                ->when($identidad['numero_control'] !== null, fn ($q) => $q->where('numero_control_norm', $identidad['numero_control']))
                ->get();
            if ($coincidencias->count() !== 1) {
                $pendientes[] = implode(' / ', array_filter([$identidad['codigo_generacion'], $identidad['numero_control']]));

                continue;
            }
            $documento = $coincidencias->first();
            $documentos->put($documento->id, $documento);
            $detalles[$documento->id][] = $identidad['detalle'];
        }

        return compact('documentos', 'pendientes', 'detalles');
    }

    /**
     * La solicitud cuyo archivo nombra el correo. La comparación es sobre el nombre SIN
     * extensión: el cliente escribe «000123202609040951» y nosotros guardamos
     * «000123202609040951.xlsx».
     */
    private function solicitudDelArchivo(Cliente $cliente, ?string $archivo): ?CobroSolicitud
    {
        if (blank($archivo)) {
            return null;
        }

        return CobroSolicitud::where('cliente_id', $cliente->id)
            ->where(fn ($q) => $q
                ->where('archivo_nombre', $archivo)
                ->orWhere('archivo_nombre', $archivo.'.xlsx')
                ->orWhere('archivo_nombre', 'like', $archivo.'.%'))
            ->latest('id')
            ->first();
    }

    /** La solicitud que ya tenía registrada esa referencia del cliente. */
    private function solicitudDeLaReferencia(Cliente $cliente, ?string $referencia): ?CobroSolicitud
    {
        if (blank($referencia)) {
            return null;
        }

        return CobroSolicitud::where('cliente_id', $cliente->id)
            ->where('referencia_calleja', $referencia)
            ->latest('id')
            ->first();
    }

    private function sinAsociar(CobroCorreo $correo, string $motivo): void
    {
        $correo->forceFill(['estado' => 'sin_asociar', 'motivo' => $motivo])->save();
    }
}
