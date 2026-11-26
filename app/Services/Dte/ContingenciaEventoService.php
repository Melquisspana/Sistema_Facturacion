<?php

namespace App\Services\Dte;

use App\Enums\AmbienteHacienda;
use App\Enums\EstadoDte;
use App\Exceptions\Dte\DteFirmaDeshabilitadaException;
use App\Exceptions\Dte\DteFirmaException;
use App\Exceptions\Dte\DteTransmisionDeshabilitadaException;
use App\Exceptions\Dte\DteTransmisionException;
use App\Models\Contingencia;
use App\Models\ContingenciaEvento;
use App\Models\Dte;
use App\Services\Dte\Serializadores\SerializadorContingenciaMh;
use App\Support\Dte\CandadoEndpointOficial;
use App\Support\Dte\CodigoGeneracion;
use App\Support\Dte\EndpointsHacienda;
use App\Support\Dte\PedidoSinSalida;
use App\Support\HoraNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContingenciaEventoService
{
    public function __construct(
        private readonly SerializadorContingenciaMh $serializador,
        private readonly DteSchemaValidator $validador,
        private readonly DteFirmaService $firma,
        private readonly DteConsultaService $consulta,
        private readonly DteTransmisionService $transmision,
    ) {}

    public function plazo(Contingencia $contingencia, ?ContingenciaEvento $evento = null): ?Carbon
    {
        $desde = $evento?->rechazado_en ?? $contingencia->cese;

        return $desde ? HoraNegocio::aLocal($desde)->addHours(24) : null;
    }

    public function requiereInformeTecnico(Contingencia $contingencia): bool
    {
        return HoraNegocio::aLocal($contingencia->cese ?? HoraNegocio::ahora())
            ->gte(HoraNegocio::aLocal($contingencia->inicio)->addDays(3));
    }

    private function verificar(Contingencia $contingencia, ?ContingenciaEvento $evento = null): void
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        $plazo = $this->plazo($contingencia, $evento);
        if (! $contingencia->cese || ! in_array($contingencia->estado, ['cerrada', 'informada'], true)) {
            $this->error('No se puede enviar una contingencia sin cese o fuera del estado permitido.');
        }
        if ($plazo === null || HoraNegocio::ahora()->gt($plazo)) {
            $this->error('El plazo de 24 horas vencio. Se requiere gestionar una prorroga ante el MH.');
        }
    }

    private function error(string $mensaje): never
    {
        throw ValidationException::withMessages(['contingencia' => $mensaje]);
    }

    /** Conserva los intentos anteriores; solo los ultimos de cada parte son vigentes. */
    public function partesVigentes(Contingencia $contingencia): Collection
    {
        return $contingencia->eventos()->orderBy('id')->get()->keyBy('parte')->sortKeys()->values();
    }

    /**
     * @param  bool  $reemplazarIncierto  una persona verificó en Hacienda que no tiene la
     *                                    parte en duda: se arma otra con código nuevo
     */
    public function preparar(Contingencia $contingencia, bool $reemplazarIncierto = false): Collection
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);

        return DB::transaction(function () use ($contingencia, $reemplazarIncierto) {
            $contingencia = Contingencia::whereKey($contingencia->id)->lockForUpdate()->firstOrFail();
            $ambiente = EndpointsHacienda::ambienteTransmision();
            CandadoEndpointOficial::verificar($ambiente, 'contingencia', EndpointsHacienda::contingenciaOficial($ambiente), EndpointsHacienda::contingencia($ambiente));
            $partes = $this->partesVigentes($contingencia);
            if ($partes->isNotEmpty()) {
                foreach ($partes as $parte) {
                    if ($parte->estado !== 'rechazado' && ! ($reemplazarIncierto && $parte->estado === 'incierto')) {
                        continue;
                    }
                    $this->verificar($contingencia, $parte);
                    if ($parte->estado === 'incierto') {
                        activity('dte_contingencia')->performedOn($parte)
                            ->log('Aviso en duda reemplazado por uno nuevo: se verificó en Hacienda que no lo tiene');
                    }
                    $documentos = $parte->dtes()->with(['establecimiento.empresa', 'puntoVenta'])->orderBy('id')->get();
                    $documentos = $this->consultarDocumentos($documentos);
                    if ($documentos->isEmpty()) {
                        // La respuesta rechazada sigue disponible; ningun DTE queda vinculado.
                        $parte->update(['estado' => 'sin_documentos']);

                        continue;
                    }
                    $this->crearParte($contingencia, $documentos, $parte->parte, $parte->rechazado_en);
                }

                return $this->partesVigentes($contingencia);
            }
            $this->verificar($contingencia);
            $documentos = $contingencia->dtes()->with(['establecimiento.empresa', 'puntoVenta'])
                ->where(fn ($query) => $query->whereNull('sello_recepcion')->orWhere('sello_recepcion', ''))
                ->orderBy('id')->get();
            $documentos = $this->consultarDocumentos($documentos);
            if ($documentos->isEmpty()) {
                return collect();
            }
            $numeroParte = 0;
            // El evento identifica un solo emisor/ambiente y lugar de transmision.
            foreach ($documentos->groupBy(fn ($dte) => $dte->ambiente->value.':'.$dte->establecimiento_id.':'.$dte->punto_venta_id)->values() as $grupo) {
                foreach ($grupo->chunk(1000) as $chunk) {
                    $this->crearParte($contingencia, $chunk, ++$numeroParte);
                }
            }

            return $this->partesVigentes($contingencia);
        });
    }

    private function consultarDocumentos(Collection $documentos): Collection
    {
        return $documentos->filter(function ($dte) {
            if ($dte->ambiente !== EndpointsHacienda::ambienteTransmision()) {
                $this->error('El ambiente de transmision no coincide con el documento.');
            }
            if ($dte->estado !== EstadoDte::Firmado) {
                $this->error('Todos los documentos del evento deben estar firmados.');
            }
            try {
                $resultado = $this->consulta->consultar($dte);
            } catch (DteTransmisionDeshabilitadaException $e) {
                throw $e;
            } catch (DteTransmisionException $e) {
                // Casi siempre es no poder iniciar sesión en Hacienda: no se armó ni se envió nada.
                Log::warning('Contingencia: no se pudo consultar un documento', ['dte_id' => $dte->id, 'error' => $e->getMessage()]);
                $this->error('No se pudo consultar en Hacienda el documento '.$dte->codigo_generacion.'. No se envió nada: probá de nuevo en unos minutos.');
            }
            if ($resultado['resultado'] === 'aceptado' && filled($resultado['sello'])) {
                $this->transmision->aplicarResultadoDeConsulta($dte, $resultado);
                // La aceptación de una consulta solo se guarda si se pudo comprobar (sello,
                // mismo código, mismo ambiente). Si no quedó aceptado, no se da por recibido.
                if ($dte->estado !== EstadoDte::Aceptado) {
                    $this->error('No se pudo confirmar que el MH no tiene el documento '.$dte->codigo_generacion.'.');
                }
                // Solo este servicio modifica los vinculos de regularizacion bajo el
                // bloqueo de la contingencia. El observer sigue impidiendo editarlos.
                Dte::whereKey($dte->id)->update(['contingencia_id' => null, 'contingencia_evento_id' => null]);

                return false;
            }
            if ($resultado['resultado'] !== 'no_encontrado') {
                $this->error('No se pudo confirmar que el MH no tiene el documento '.$dte->codigo_generacion.'.');
            }

            return true;
        })->values();
    }

    private function crearParte(Contingencia $contingencia, Collection $documentos, int $numero, ?Carbon $rechazado = null): void
    {
        if ($documentos->isEmpty()) {
            $this->error('No quedan documentos para incluir en el evento.');
        }
        $json = $this->serializador->serializar($contingencia, $documentos);
        $validacion = $this->validador->validarContingencia($json);
        if (! $validacion['valido']) {
            $this->error($validacion['mensaje'].' '.implode(' | ', $validacion['errores']));
        }
        $ruta = 'dte/contingencia/'.$contingencia->id.'/'.$json['identificacion']['codigoGeneracion'];
        $this->guardar($ruta.'.json', json_encode($json, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $evento = $contingencia->eventos()->create(['parte' => $numero,
            'codigo_generacion' => $json['identificacion']['codigoGeneracion'], 'estado' => 'preparado',
            'json_path' => $ruta.'.json', 'rechazado_en' => $rechazado]);
        foreach ($documentos as $dte) {
            Dte::whereKey($dte->id)->update(['contingencia_evento_id' => $evento->id]);
        }
    }

    /** @param  bool  $reemplazarIncierto  ver {@see preparar()} */
    public function enviar(Contingencia $contingencia, bool $reemplazarIncierto = false): array
    {
        $resultados = [];
        $partes = $this->preparar($contingencia, $reemplazarIncierto);
        if ($partes->isEmpty()) {
            return [['resultado' => 'sin_documentos', 'mensaje' => 'No quedan documentos pendientes para informar al MH.']];
        }
        foreach ($partes as $evento) {
            if (in_array($evento->estado, ['recibido', 'sin_documentos'], true)) {
                continue;
            }
            // Sin la verificación de una persona, una parte en duda no se vuelve a mandar.
            if ($evento->estado === 'incierto') {
                $resultados[] = ['resultado' => 'incierto', 'mensaje' => $this->mensajeEnDuda($evento)];

                continue;
            }
            $resultados[] = DB::transaction(function () use ($contingencia, $evento) {
                $actual = Contingencia::whereKey($contingencia->id)->lockForUpdate()->firstOrFail();
                $evento = ContingenciaEvento::whereKey($evento->id)->lockForUpdate()->firstOrFail();
                if ($evento->estado === 'recibido') {
                    return ['resultado' => 'recibido'];
                }
                if ($evento->estado !== 'preparado' || $this->partesVigentes($actual)->firstWhere('parte', $evento->parte)?->id !== $evento->id) {
                    $this->error('El evento ya fue enviado o reemplazado.');
                }
                $this->verificar($actual, $evento);
                $json = json_decode(Storage::disk(config('dte.storage.disk', 'local'))->get($evento->json_path), true, 512, JSON_THROW_ON_ERROR);
                $ambiente = AmbienteHacienda::from($json['identificacion']['ambiente']);
                app(ContingenciaTransporte::class)->verificar($ambiente, 'contingencia', EndpointsHacienda::contingenciaOficial($ambiente), EndpointsHacienda::contingencia($ambiente));
                $validacion = $this->validador->validarContingencia($json);
                if (! $validacion['valido']) {
                    $this->error($validacion['mensaje'].' '.implode(' | ', $validacion['errores']));
                }
                $ruta = substr($evento->json_path, 0, -5);
                $disco = Storage::disk(config('dte.storage.disk', 'local'));
                // Primero el token: sin poder iniciar sesión no se firma ni se guarda nada.
                $token = app(ContingenciaTransporte::class)->token();
                // El JWS se guarda justo antes de mandarlo: si ya está, este aviso pudo salir
                // una vez y no se supo qué hizo Hacienda con él (se cortó o contestó algo raro).
                $reintento = $disco->exists($ruta.'.jws');
                // Ante un timeout se reintenta EXACTAMENTE el mismo JSON, UUID y JWS.
                $jws = $reintento ? $disco->get($ruta.'.jws') : $this->firmar($json);
                if (! $disco->exists($ruta.'.jws')) {
                    $this->guardar($ruta.'.jws', $jws);
                }
                $url = EndpointsHacienda::contingencia($ambiente);
                $transferencias = 0;
                try {
                    $respuesta = Http::timeout((int) config('dte.transmision.timeout', 8))->acceptJson()
                        ->withHeaders(['Authorization' => $token, 'User-Agent' => config('dte.transmision.user_agent', 'DTE/1.0')])
                        ->withOptions(['on_stats' => function () use (&$transferencias): void {
                            $transferencias++;
                        }])
                        ->post($url, ['nit' => $json['emisor']['nit'], 'documento' => $jws]);
                } catch (ConnectionException $e) {
                    // Si cURL demuestra que este primer envío no salió de la máquina, Hacienda
                    // no pudo recibirlo: sin el JWS, el intento siguiente vuelve a ser el
                    // primero y su rechazo es un rechazo común. Si pudo salir, el JWS queda.
                    $noSalio = PedidoSinSalida::probado($e, $url, $transferencias);
                    if (! $reintento && $noSalio) {
                        Log::info('Contingencia: el aviso no salió de la máquina', ['evento_id' => $evento->id] + PedidoSinSalida::evidencia($e));
                        $disco->delete($ruta.'.jws');
                    }

                    // Salió sin respuesta no es «no se pudo conectar»: Hacienda puede tenerlo
                    // (simulacro del 26/11, F18).
                    return ['resultado' => 'error_conexion', 'mensaje' => $noSalio
                        ? 'El aviso no salió: no hubo conexión con Hacienda. Se puede reintentar.'
                        : 'El aviso salió, pero Hacienda no contestó: puede que lo tenga. Se puede reintentar; sale el mismo aviso, no uno nuevo.'];
                }
                $cuerpo = $respuesta->json();
                $estado = is_array($cuerpo) ? ($cuerpo['estado'] ?? null) : null;
                // Conserva tambien respuestas inciertas anteriores, sin sobrescribir evidencia.
                $rutaRespuesta = $ruta.'-respuesta-'.CodigoGeneracion::generar().'.json';
                $this->guardar($rutaRespuesta, $respuesta->body());
                if (! in_array($estado, ['RECIBIDO', 'RECHAZADO'], true)
                    || ! ($respuesta->successful() || $respuesta->status() === 400)
                    || ($estado === 'RECIBIDO' && (! $respuesta->successful() || blank($cuerpo['selloRecibido'] ?? null)))) {
                    return ['resultado' => 'respuesta_incierta', 'mensaje' => 'Respuesta del MH no concluyente. Revisar antes de reintentar.'];
                }
                $recibido = $estado === 'RECIBIDO';
                // Un rechazo al reintentar no prueba que Hacienda no tenga el primer envío:
                // puede estar rechazando el repetido («YA EXISTE UN REGISTRO CON ESE VALOR» en el
                // simulacro). Qué contesta Hacienda real a un aviso repetido no está documentado,
                // así que no se adivina: queda en duda y otro aviso con código nuevo sale solo si
                // una persona verifica en Hacienda. La hora del rechazo se guarda igual: si Hacienda
                // no lo tiene, el rechazo fue real y da 24 h para corregir; si lo tiene, valen las
                // 72 h de los lotes. Ninguno de los dos plazos se cuenta desde el cese.
                $enDuda = ! $recibido && $reintento;
                $evento->update(['estado' => $recibido ? 'recibido' : ($enDuda ? 'incierto' : 'rechazado'), 'jws_path' => $ruta.'.jws',
                    'respuesta_mh_path' => $rutaRespuesta, 'respuesta_mh' => $cuerpo,
                    'sello_recibido' => $recibido ? $cuerpo['selloRecibido'] : null,
                    'fecha_transmision' => HoraNegocio::ahora(), 'fecha_procesamiento' => $this->fechaMh($cuerpo['fechaHora'] ?? null),
                    'rechazado_en' => $recibido ? null : HoraNegocio::ahora()]);
                if ($this->partesVigentes($actual)->every(fn ($parte) => $parte->estado === 'recibido')) {
                    $actual->update(['estado' => 'informada']);
                }
                activity('dte_contingencia')->performedOn($evento)->withProperties(['estado' => $evento->estado])->log('Evento de contingencia enviado');

                return ['resultado' => $evento->estado, 'mensaje' => $enDuda ? $this->mensajeEnDuda($evento) : ($cuerpo['mensaje'] ?? ''), 'evento_id' => $evento->id];
            });
            if (in_array(end($resultados)['resultado'], ['error_conexion', 'respuesta_incierta'], true)) {
                break;
            }
        }

        if ($resultados === [] && $partes->contains('estado', 'sin_documentos')) {
            return [['resultado' => 'sin_documentos', 'mensaje' => 'Los documentos de las partes descartadas ya tienen sello del MH.']];
        }

        return $resultados;
    }

    private function mensajeEnDuda(ContingenciaEvento $evento): string
    {
        return 'La parte '.$evento->parte.' quedó en duda: un envío anterior se cortó y Hacienda pudo haberlo recibido. '
            .'Verificá en Hacienda antes de reenviar. Si lo tiene, apretá «Hacienda sí lo tiene» y no mandes otro aviso. '
            .'Si no lo tiene, marcá «Ya verifiqué en Hacienda» y enviá de nuevo.';
    }

    /**
     * La otra respuesta de la verificación de una parte en duda: Hacienda SÍ tiene el aviso
     * (simulacro del 17/11, caso 5c: el primer envío se cortó, pero Hacienda lo guardó). No
     * se manda otro aviso: la parte queda recibida y sus documentos salen en lote, que no
     * usa el sello del evento. Las 72 h cuentan desde la hora escrita en el aviso, anterior
     * a cualquier envío: nunca dan más plazo que el real.
     */
    public function darPorRecibido(ContingenciaEvento $evento): void
    {
        abort_unless(config('dte.contingencia.enabled', false), 404);
        DB::transaction(function () use ($evento) {
            $contingencia = Contingencia::whereKey($evento->contingencia_id)->lockForUpdate()->firstOrFail();
            $evento = ContingenciaEvento::whereKey($evento->id)->lockForUpdate()->firstOrFail();
            if ($evento->estado !== 'incierto' || $contingencia->estado !== 'cerrada'
                || $this->partesVigentes($contingencia)->firstWhere('parte', $evento->parte)?->id !== $evento->id) {
                $this->error('Esta parte del aviso ya no está en duda. No se cambió nada.');
            }
            $evento->update(['estado' => 'recibido', 'fecha_transmision' => $this->horaDelAviso($evento),
                'fecha_procesamiento' => null, 'rechazado_en' => null]);
            if ($this->partesVigentes($contingencia)->every(fn ($parte) => $parte->estado === 'recibido')) {
                $contingencia->update(['estado' => 'informada']);
            }
            activity('dte_contingencia')->performedOn($evento)->withProperties(['fecha_transmision' => $evento->fecha_transmision])
                ->log('Aviso en duda dado por recibido: se verificó en Hacienda que lo tiene');
        });
    }

    /** La hora escrita en el aviso (fTransmision y hTransmision): se armó antes de mandarlo. */
    public function horaDelAviso(ContingenciaEvento $evento): Carbon
    {
        $json = json_decode(Storage::disk(config('dte.storage.disk', 'local'))->get($evento->json_path), true, 512, JSON_THROW_ON_ERROR);

        return Carbon::createFromFormat('Y-m-d H:i:s', $json['identificacion']['fTransmision'].' '.$json['identificacion']['hTransmision'], HoraNegocio::zona());
    }

    /**
     * Con el firmador caído todavía no se envió nada: sale un mensaje para volver a probar,
     * no un error del servidor. La firma apagada sigue con su propio mensaje.
     */
    private function firmar(array $json): string
    {
        try {
            return $this->firma->firmarJson($json);
        } catch (DteFirmaDeshabilitadaException $e) {
            throw $e;
        } catch (DteFirmaException $e) {
            Log::warning('Contingencia: no se pudo firmar el aviso', ['error' => $e->getMessage()]);
            $this->error('No se pudo firmar el aviso. No se envió nada a Hacienda: revisá que el firmador esté encendido y probá de nuevo en unos minutos.');
        }
    }

    private function guardar(string $ruta, string $contenido): void
    {
        app(ContingenciaTransporte::class)->guardar($ruta, $contenido);
    }

    private function fechaMh(?string $fecha): ?Carbon
    {
        if (blank($fecha)) {
            return null;
        }

        return rescue(fn () => Carbon::createFromFormat('d/m/Y H:i:s', $fecha, HoraNegocio::zona()), null, false)
            ?: rescue(fn () => Carbon::parse($fecha, HoraNegocio::zona()), null, false);
    }
}
