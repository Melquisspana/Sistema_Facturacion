<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\EstadoPresentacionCobro;
use App\Enums\Cobros\EstadoSolicitudCobro;
use App\Enums\Cobros\TipoEventoCobro;
use App\Exceptions\CopiaArchivadaInservibleException;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Cobros\CobroEvento;
use App\Models\Cobros\CobroSolicitud;
use App\Models\Cobros\CobroSolicitudItem;
use App\Models\Cobros\CobroSolicitudNota;
use App\Models\NcExportacion;
use App\Models\User;
use App\Services\Cobros\Exportadores\ExportadorSolicitudCargaMasivaV1;
use App\Services\Cobros\Exportadores\ExportadorSolicitudFactory;
use App\Services\Dte\PerfilDocumentoResolver;
use App\Services\Ppq\NcExportacionService;
use App\Support\Dinero;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * SOLICITUDES de cobro: armar el archivo con los documentos elegidos, registrar que
 * alguien lo presentó y guardar lo que el cliente respondió.
 *
 * ═══════════════════ Generar, presentar y recibir son tres hechos ═══════════════════
 *
 * El sistema solo puede afirmar el primero. Subir el archivo al portal lo hace una persona
 * y el acuse lo da el cliente, así que los otros dos se REGISTRAN explícitamente y nunca se
 * deducen del paso del tiempo ni de una descarga. Un lote descargado y nunca subido tiene
 * que verse distinto de uno entregado; si no, el día que falte un pago nadie sabrá cuál de
 * los dos fue.
 *
 * ═══════════════════════ Reenviar no duplica la deuda ═══════════════════════
 *
 * Corregir una solicitud crea una NUEVA con los documentos que se le indiquen y marca la
 * anterior como corregida; los documentos se MUEVEN, no se copian. Por eso un documento
 * apunta a una sola solicitud vigente: si se copiara, el mismo CCF quedaría contado en dos
 * presentaciones y el cliente vería la misma deuda dos veces. La solicitud anterior se
 * conserva entera —su archivo, su selección, su fecha— porque es lo que efectivamente se
 * entregó ese día.
 *
 * ═══════════════ Las NC no entran en el archivo, pero van PRIMERO ═══════════════
 *
 * El formato de carga masiva del portal no tiene dónde ponerlas y las notas de crédito ya
 * tienen su propio circuito ({@see NcExportacionService}). Arrastrarlas
 * acá sería descontarlas dos veces.
 *
 * Pero Calleja no paga un CCF subido sin sus notas: en el portal se carga primero el
 * archivo de NC y después el de quedan. Por eso un quedan solo se prepara cuando TODAS las
 * NC aceptadas de sus CCF están en un lote de NC con su carga al portal registrada
 * ({@see NotasDelQuedan}), y al crearlo se congela qué nota y qué lote respaldaron a cada
 * CCF. Descargar el archivo de NC no cuenta como cargarlo.
 *
 * No emite, no firma, no transmite y no toca ningún valor fiscal.
 */
class SolicitudCobroService
{
    public function __construct(
        private readonly ExportadorSolicitudFactory $exportadores,
        private readonly PerfilDocumentoResolver $perfiles,
        private readonly NotasDelQuedan $notas,
        private readonly NcExportacionService $exportacionesNc,
    ) {}

    /**
     * Documentos que pueden entrar HOY en una solicitud: CCF del cliente, sin presentación
     * vigente, de la más antigua a la más reciente.
     *
     * Devuelve también los INCOMPLETOS: una factura sin albarán tiene que verse, porque es
     * justo la que hay que resolver. Lo que no se puede es exportarla
     * ({@see verificarCompletos()}).
     *
     * @return Collection<int, CobroDocumento>
     */
    public function presentables(Cliente $cliente): Collection
    {
        return CobroDocumento::deCliente($cliente->id)
            ->where('tipo_dte', '03')
            ->whereNull('cobro_solicitud_id')
            ->with(['albaran', 'dte:id,numero_orden_compra'])
            ->porAntiguedad()
            ->get();
    }

    /**
     * VISTA PREVIA de la solicitud que {@see crear()} armaría HOY con estos documentos: los
     * mismos renglones, en el mismo orden y con los mismos datos congelados, porque salen
     * del mismo camino (misma verificación, mismo orden, mismo
     * {@see ExportadorSolicitudCargaMasivaV1::datosDeFila()}). No escribe nada, no genera
     * archivo y no bloquea filas.
     *
     * La huella resume esos renglones; al confirmar se vuelve a calcular dentro de la
     * transacción y, si no coincide, no se crea nada: lo que se confirma es lo que se vio.
     *
     * También dice qué NC tiene cada CCF y si ya se registró su carga al portal; la huella
     * incluye esas notas, su estado y su lote.
     *
     * @param  array<int, int>  $documentoIds
     * @return array{filas: array<int, array{orden: int, documento: CobroDocumento, datos: array<string, mixed>}>, total: string, huella: string, documentos: array<int, int>, notas: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function previsualizar(Cliente $cliente, array $documentoIds): array
    {
        $documentoIds = $this->normalizar($documentoIds);
        $filas = $this->filas($this->seleccion($cliente, $documentoIds, null, bloquear: false));
        $notas = $this->notas->analizar($cliente, collect(array_column($filas, 'documento')));

        $total = '0';
        foreach ($filas as $fila) {
            $total = Dinero::sumar($total, (string) ($fila['datos']['monto'] ?? '0'));
        }

        return [
            'filas' => $filas,
            'total' => Dinero::redondear($total),
            'huella' => $this->huella($cliente, $filas, $notas),
            'documentos' => array_map(fn (array $fila) => $fila['documento']->id, $filas),
            'notas' => $notas,
        ];
    }

    /**
     * Prepara el lote de NC de los CCF elegidos: SOLO sus NC aceptadas que todavía no
     * viajaron en ningún lote, con el mismo servicio y el mismo archivo que el formato de
     * NC de siempre. Las ya exportadas no se tocan: se usa su lote.
     *
     * Las notas salen de los CCF releídos del cliente dentro de la transacción, nunca de
     * lo que traiga el formulario. Si a alguna le falta un dato, o no hay ninguna nueva,
     * no se crea nada.
     *
     * @param  array<int, int>  $documentoIds
     *
     * @throws ValidationException
     */
    public function prepararNotas(
        Cliente $cliente,
        array $documentoIds,
        ?User $usuario = null,
        ?string $huellaEsperada = null,
    ): NcExportacion
    {
        $documentoIds = $this->normalizar($documentoIds);

        return DB::transaction(function () use ($cliente, $documentoIds, $usuario, $huellaEsperada) {
            $documentos = $this->seleccion($cliente, $documentoIds, null, bloquear: true);
            $notas = $this->notas->analizar($cliente, $documentos);

            if ($huellaEsperada !== null
                && ! hash_equals($huellaEsperada, $this->huella($cliente, $this->filas($documentos), $notas))) {
                throw ValidationException::withMessages([
                    'documentos' => 'No se preparó ningún archivo de NC: la selección o sus notas cambiaron '
                        .'desde la vista previa. Revísela de nuevo.',
                ]);
            }

            if ($notas['bloqueadas'] !== []) {
                throw ValidationException::withMessages([
                    'documentos' => 'No se preparó ningún archivo de NC: a estas notas les falta un dato y el '
                        .'sistema no lo inventa. — '.collect($notas['bloqueadas'])
                            ->map(fn (array $f) => $f['nc']->numero_control.': falta '.implode(', ', $f['faltantes']))
                            ->implode(' · '),
                ]);
            }

            if ($notas['por_exportar'] === []) {
                $lotes = collect(array_merge($notas['sin_presentar'], $notas['presentadas']))
                    ->map(fn (array $f) => $f['lote']->referencia)->unique()->implode(', ');

                throw ValidationException::withMessages([
                    'documentos' => 'No se preparó ningún archivo de NC: estos CCF no tienen notas aceptadas '
                        .'pendientes de exportar.'.($lotes !== '' ? ' Sus notas ya están en: '.$lotes.'.' : ''),
                ]);
            }

            return $this->exportacionesNc->crear($cliente, $notas['por_exportar'], $usuario);
        });
    }

    /**
     * Crea la solicitud con los documentos indicados.
     *
     * Con `$huellaEsperada` (la de {@see previsualizar()}), los renglones tienen que salir
     * idénticos a los que se mostraron; si algo cambió entretanto, se rechaza sin escribir.
     *
     * @param  array<int, int>  $documentoIds
     *
     * @throws ValidationException
     */
    public function crear(
        Cliente $cliente,
        array $documentoIds,
        ?User $usuario = null,
        ?CobroSolicitud $corrige = null,
        ?string $huellaEsperada = null,
    ): CobroSolicitud {
        $documentoIds = $this->normalizar($documentoIds);

        return DB::transaction(function () use ($cliente, $documentoIds, $usuario, $corrige, $huellaEsperada) {
            // Se releen dentro de la transacción: lo que llegó del navegador es una
            // intención, no una autorización.
            $filas = $this->filas($this->seleccion($cliente, $documentoIds, $corrige, bloquear: true));
            $notas = $this->notas->analizar($cliente, collect(array_column($filas, 'documento')));

            // Se comprueba siempre, con o sin vista previa: una NC aparecida o cambiada
            // después de mirarla también cuenta.
            $bloqueos = NotasDelQuedan::bloqueos($notas);
            if ($bloqueos !== []) {
                throw ValidationException::withMessages([
                    'documentos' => 'No se preparó nada: en el portal se cargan primero las NC de estos CCF, y '
                        .'todavía no está registrada la carga de todas. — '.implode(' ', $bloqueos),
                ]);
            }

            if ($huellaEsperada !== null && ! hash_equals($huellaEsperada, $this->huella($cliente, $filas, $notas))) {
                throw ValidationException::withMessages([
                    'documentos' => 'No se preparó nada: los documentos cambiaron desde la vista previa '
                        .'(albarán, importe u orden). Vuelva a revisarla antes de confirmar.',
                ]);
            }

            $solicitud = CobroSolicitud::create([
                'cliente_id' => $cliente->id,
                'referencia' => $this->referencia($cliente),
                'formato' => ExportadorSolicitudFactory::actual(),
                'archivo_nombre' => $this->nombreArchivo($cliente),
                'estado' => EstadoSolicitudCobro::Generada->value,
                'corrige_a_id' => $corrige?->id,
                'user_id' => $usuario?->id,
            ]);

            foreach ($filas as ['orden' => $orden, 'documento' => $documento, 'datos' => $datos]) {
                CobroSolicitudItem::create([
                    'cobro_solicitud_id' => $solicitud->id,
                    'cobro_documento_id' => $documento->id,
                    'orden' => $orden,
                ] + $datos);

                $documento->forceFill([
                    'cobro_solicitud_id' => $solicitud->id,
                    'presentacion_estado' => EstadoPresentacionCobro::Preparada->value,
                ])->save();
            }

            // Qué NC —y en qué lote ya cargado— respaldó a cada CCF. Queda congelado aunque
            // la nota cambie después.
            foreach ($notas['presentadas'] as $fila) {
                CobroSolicitudNota::create([
                    'cobro_solicitud_id' => $solicitud->id,
                    'cobro_documento_id' => $fila['documento']->id,
                    'dte_id' => $fila['nc']->id,
                    'nc_exportacion_id' => $fila['lote']->id,
                ]);
            }

            if ($corrige !== null) {
                $corrige->forceFill([
                    'estado' => EstadoSolicitudCobro::Corregida->value,
                ])->save();
            }

            return $solicitud->refresh();
        });
    }

    /**
     * Rehace la solicitud con una selección distinta, dejando la anterior como constancia.
     *
     * Los documentos que NO entren en el reenvío vuelven a estar sin presentar: si se
     * quedaron fuera es porque no debían haber ido, y dejarlos colgando de una solicitud
     * corregida los escondería de la bandeja.
     *
     * @param  array<int, int>  $documentoIds
     *
     * @throws ValidationException
     */
    public function corregir(CobroSolicitud $solicitud, array $documentoIds, string $motivo, User $usuario): CobroSolicitud
    {
        if (trim($motivo) === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Diga por qué se corrige: es lo único que explica dos presentaciones del mismo cobro.',
            ]);
        }

        return DB::transaction(function () use ($solicitud, $documentoIds, $motivo, $usuario) {
            $solicitud->loadMissing('cliente');

            // Se sueltan TODOS los de la solicitud vieja antes de armar la nueva; los que
            // vuelvan a entrar se reasignan enseguida.
            CobroDocumento::where('cobro_solicitud_id', $solicitud->id)->update([
                'cobro_solicitud_id' => null,
                'presentacion_estado' => EstadoPresentacionCobro::SinPresentar->value,
            ]);

            $nueva = $this->crear($solicitud->cliente, $documentoIds, $usuario, $solicitud);

            $nueva->forceFill(['motivo_correccion' => $motivo])->save();

            activity('cobros_solicitud')
                ->performedOn($nueva)
                ->causedBy($usuario)
                ->withProperties([
                    'corrige_a' => $solicitud->referencia,
                    'motivo' => $motivo,
                    'documentos' => count($documentoIds),
                ])
                ->log('corrigió una solicitud de cobro con un reenvío');

            return $nueva;
        });
    }

    /**
     * Registra que una persona SUBIÓ el archivo al portal. El sistema no lo sube ni lo
     * comprueba: esto es la declaración de alguien, con su nombre y su fecha.
     *
     * @throws ValidationException
     */
    public function registrarPresentacion(CobroSolicitud $solicitud, User $usuario, ?Carbon $cuando = null, ?string $nota = null): CobroSolicitud
    {
        if ($solicitud->estado === EstadoSolicitudCobro::Corregida) {
            throw ValidationException::withMessages([
                'solicitud' => 'Esta solicitud fue corregida por un reenvío posterior: presentar la versión '
                    .'anterior volvería a mandar lo que ya se reemplazó.',
            ]);
        }

        return DB::transaction(function () use ($solicitud, $usuario, $cuando, $nota) {
            $this->verificarCompletos($solicitud->documentos()->lockForUpdate()->get());
            $solicitud->forceFill([
                'estado' => EstadoSolicitudCobro::Presentada->value,
                'presentada_en' => $cuando ?? now(),
                'presentada_por' => $usuario->id,
                'presentada_nota' => $nota,
            ])->save();

            $this->propagarEstado($solicitud);

            foreach ($solicitud->documentos()->get() as $documento) {
                $this->evento($documento, TipoEventoCobro::Presentacion, [
                    'origen' => 'solicitud',
                    'fecha' => ($cuando ?? now())->toDateString(),
                    'detalle' => 'Presentada en el portal con la solicitud '.$solicitud->referencia
                        .($nota ? '. '.$nota : '.'),
                    'evidencia_hash' => $solicitud->archivo_hash,
                    'evidencia_nombre' => $solicitud->archivo_nombre,
                    'referencia_linea' => 'sol-'.$solicitud->id,
                    'user_id' => $usuario->id,
                ]);
            }

            return $solicitud->refresh();
        });
    }

    /**
     * Guarda el acuse del cliente: su referencia y, si la dio, la fecha programada de pago.
     *
     * Puede llegar de la lectura del correo o teclearse. En los dos casos es EVIDENCIA del
     * cliente, y por eso mueve el estado; el paso del tiempo nunca lo mueve.
     */
    public function registrarRecibido(
        CobroSolicitud $solicitud,
        string $referenciaCalleja,
        ?Carbon $fechaProgramada = null,
        ?Carbon $cuando = null,
        ?User $usuario = null,
        ?string $detalle = null,
    ): CobroSolicitud {
        return DB::transaction(function () use ($solicitud, $referenciaCalleja, $fechaProgramada, $cuando, $usuario, $detalle) {
            $solicitud->forceFill([
                'estado' => EstadoSolicitudCobro::Recibida->value,
                'referencia_calleja' => $referenciaCalleja,
                'recibida_en' => $cuando ?? now(),
                'fecha_programada_pago' => $fechaProgramada?->toDateString() ?? $solicitud->fecha_programada_pago,
                // Un acuse implica que se presentó, aunque nadie lo hubiera declarado.
                'presentada_en' => $solicitud->presentada_en ?? ($cuando ?? now()),
            ])->save();

            $this->propagarEstado($solicitud);

            foreach ($solicitud->documentos()->get() as $documento) {
                $this->evento($documento, TipoEventoCobro::Recibido, [
                    'origen' => $usuario !== null ? 'manual' : 'correo',
                    'fecha' => ($cuando ?? now())->toDateString(),
                    'detalle' => $detalle ?? ('Calleja acusó recibo con la referencia '.$referenciaCalleja
                        .($fechaProgramada ? '. Pago programado para '.$fechaProgramada->format('d/m/Y').'.' : '.')),
                    'referencia_linea' => 'ref-'.$referenciaCalleja,
                    'datos' => [
                        'referencia_calleja' => $referenciaCalleja,
                        'fecha_programada' => $fechaProgramada?->toDateString(),
                    ],
                    'user_id' => $usuario?->id,
                ]);
            }

            return $solicitud->refresh();
        });
    }

    /** Pone en los documentos el estado de presentación que corresponde a su solicitud. */
    private function propagarEstado(CobroSolicitud $solicitud): void
    {
        CobroDocumento::where('cobro_solicitud_id', $solicitud->id)->update([
            'presentacion_estado' => $solicitud->estado->presentacionDeDocumentos()->value,
        ]);
    }

    /**
     * Ruta TEMPORAL con el archivo de la solicitud (quien la entrega la borra al enviarla).
     *
     * ═════════ La primera descarga lo genera; las siguientes devuelven EL MISMO ═════════
     *
     * Con copia archivada (`archivo_hash` + `archivo_path`) se devuelven esos bytes, tras
     * comprobar que su SHA-256 es el registrado. No se regenera nunca: si la plantilla o el
     * exportador cambian, regenerar daría otro archivo que el que se entregó ese día. Si la
     * copia falta, está ilegible, no coincide con su huella o el registro está a medias, se
     * detiene con un error claro —no se sustituye por uno nuevo—.
     *
     * Sin copia archivada (ninguno de los dos datos) se genera con el formato con el que la
     * solicitud nació, se archiva, y se entregan esos mismos bytes. Todo bajo bloqueo de la
     * fila: dos primeras descargas simultáneas no pueden archivar dos archivos distintos; la
     * segunda espera y encuentra la copia de la primera.
     *
     * @throws RuntimeException
     */
    public function archivo(CobroSolicitud $solicitud): string
    {
        return DB::transaction(function () use ($solicitud) {
            $fila = CobroSolicitud::lockForUpdate()->findOrFail($solicitud->id);

            if ($fila->archivo_hash !== null || $fila->archivo_path !== null) {
                $contenido = $this->leerCopia($fila);
                $this->sincronizarCopia($solicitud, $fila);

                return $this->temporal($contenido);
            }

            $fila->load('items.documento');
            $generado = $this->exportadores->porSlug($fila->formato)->generar($fila);
            $contenido = file_get_contents($generado);
            @unlink($generado);

            if ($contenido === false || $contenido === '') {
                throw new RuntimeException("No se pudo generar el archivo de la solicitud {$fila->referencia}.");
            }

            // Si archivar falla, la excepción deshace la transacción: la solicitud queda sin
            // huella y la próxima descarga vuelve a intentarlo desde cero.
            $this->guardarCopia($fila, $contenido);
            $this->sincronizarCopia($solicitud, $fila);

            return $this->temporal($contenido);
        });
    }

    /**
     * Los bytes archivados, verificados contra su huella.
     *
     * @throws RuntimeException
     */
    private function leerCopia(CobroSolicitud $solicitud): string
    {
        $referencia = $solicitud->referencia;

        if ($solicitud->archivo_hash === null || $solicitud->archivo_path === null) {
            throw new CopiaArchivadaInservibleException("La solicitud {$referencia} tiene el registro de su archivo incompleto "
                .'(huella o ruta vacía). No se regenera: revisar la copia archivada antes de volver a descargarla.', (string) $referencia);
        }

        try {
            $disco = Storage::disk((string) config('dte.storage.disk', 'local'));
            $existe = $disco->exists($solicitud->archivo_path);
            $contenido = $existe ? $disco->get($solicitud->archivo_path) : null;
        } catch (Throwable $e) {
            throw new CopiaArchivadaInservibleException("No se pudo leer la copia archivada de la solicitud {$referencia}: "
                .$e->getMessage().'. No se regenera.', (string) $referencia, $e);
        }

        if (! $existe) {
            throw new CopiaArchivadaInservibleException("No se encontró la copia archivada de la solicitud {$referencia}. "
                .'No se regenera un archivo distinto del que se entregó.', (string) $referencia);
        }

        if (! is_string($contenido) || ! hash_equals((string) $solicitud->archivo_hash, hash('sha256', $contenido))) {
            throw new CopiaArchivadaInservibleException("La copia archivada de la solicitud {$referencia} no coincide con su huella "
                .'SHA-256 o no se pudo leer. No se entrega ni se regenera.', (string) $referencia);
        }

        return $contenido;
    }

    /**
     * Deja la copia direccionada por su contenido, igual que el archivo de pagos, y solo
     * registra la huella cuando la escritura está comprobada.
     *
     * @throws RuntimeException
     */
    private function guardarCopia(CobroSolicitud $solicitud, string $contenido): void
    {
        $hash = hash('sha256', $contenido);
        $directorio = trim((string) config('cobros.solicitudes.storage_dir', 'cobros/solicitudes'), '/');
        $destino = $directorio.'/'.$hash.'.xlsx';
        $disco = Storage::disk((string) config('dte.storage.disk', 'local'));

        try {
            $escrito = $disco->put($destino, $contenido);
            $releido = $escrito ? $disco->get($destino) : null;
        } catch (Throwable $e) {
            throw new RuntimeException("No se pudo archivar el archivo de la solicitud {$solicitud->referencia}: "
                .$e->getMessage(), 0, $e);
        }

        if (! is_string($releido) || ! hash_equals($hash, hash('sha256', $releido))) {
            throw new RuntimeException("No se pudo archivar el archivo de la solicitud {$solicitud->referencia}: "
                .'la copia escrita no se pudo comprobar.');
        }

        $solicitud->forceFill([
            'archivo_hash' => $hash,
            'archivo_path' => $destino,
        ])->save();
    }

    /** El modelo que tiene el llamador refleja la huella registrada, sin otra escritura. */
    private function sincronizarCopia(CobroSolicitud $solicitud, CobroSolicitud $fila): void
    {
        if ($solicitud === $fila) {
            return;
        }

        $solicitud->forceFill(['archivo_hash' => $fila->archivo_hash, 'archivo_path' => $fila->archivo_path]);
        $solicitud->syncOriginalAttributes(['archivo_hash', 'archivo_path']);
    }

    /**
     * Copia temporal de los bytes para entregarlos. Se usa el archivo que crea tempnam()
     * tal cual —sin agregarle extensión, que dejaba el original vacío huérfano—: el nombre
     * con que se descarga lo pone el controlador.
     *
     * @throws RuntimeException
     */
    private function temporal(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cobro_solicitud_');

        if ($ruta === false) {
            throw new RuntimeException('No se pudo preparar la descarga del archivo de la solicitud.');
        }

        if (file_put_contents($ruta, $contenido) !== strlen($contenido)) {
            @unlink($ruta);

            throw new RuntimeException('No se pudo preparar la descarga del archivo de la solicitud.');
        }

        return $ruta;
    }

    /**
     * @param  Collection<int, CobroDocumento>  $documentos
     * @param  array<int, int>  $pedidos
     *
     * @throws ValidationException
     */
    private function verificarSeleccion(Collection $documentos, array $pedidos, ?CobroSolicitud $corrige): void
    {
        $faltantes = array_diff($pedidos, $documentos->pluck('id')->all());

        if ($faltantes !== []) {
            throw ValidationException::withMessages([
                'documentos' => 'Alguno de los documentos seleccionados ya no corresponde a este cliente. '
                    .'Vuelva a cargar la lista.',
            ]);
        }

        $noCcf = $documentos->filter(fn (CobroDocumento $d) => $d->tipo_dte !== '03');

        if ($noCcf->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documentos' => 'Este formato solo lleva facturas (CCF). Las notas de crédito se presentan '
                    .'por su propio formato: '.$noCcf->pluck('numero_control')->implode(', ').'.',
            ]);
        }

        $invalidados = $documentos->filter(fn (CobroDocumento $d) => $d->estaInvalidado());
        if ($invalidados->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documentos' => 'No se puede preparar la solicitud: estos CCF fueron invalidados en Hacienda: '
                    .$invalidados->map(fn (CobroDocumento $d) => $d->correlativoCorto())->implode(', ').'.',
            ]);
        }

        // Ya presentados en OTRA solicitud vigente: incluirlos acá duplicaría la deuda.
        $yaEnOtra = $documentos->filter(
            fn (CobroDocumento $d) => $d->cobro_solicitud_id !== null && $d->cobro_solicitud_id !== $corrige?->id
        );

        if ($yaEnOtra->isNotEmpty()) {
            $lista = $yaEnOtra->map(
                fn (CobroDocumento $d) => $d->numero_control.' (solicitud #'.$d->cobro_solicitud_id.')'
            )->implode(', ');

            throw ValidationException::withMessages([
                'documentos' => "Estos documentos ya están en otra solicitud: {$lista}. "
                    .'Si hay que rehacerla, corrija aquella en vez de armar una segunda con lo mismo.',
            ]);
        }
    }

    /**
     * Ninguna fila se escribe a medias.
     *
     * @param  Collection<int, CobroDocumento>  $documentos
     *
     * @throws ValidationException
     */
    private function verificarCompletos(Collection $documentos): void
    {
        $exportador = $this->exportadores->porSlug(ExportadorSolicitudFactory::actual());

        $problemas = [];
        foreach ($documentos as $documento) {
            $faltantes = $exportador->faltantes($documento);
            if ($faltantes !== []) {
                $problemas[] = $documento->numero_control.': falta '.implode(', ', $faltantes);
            }
        }

        if ($problemas !== []) {
            throw ValidationException::withMessages([
                'documentos' => 'No se generó ninguna solicitud: a estos documentos les falta un dato que el '
                    .'formato exige, y el sistema no lo inventa. — '.implode(' · ', $problemas),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $documentoIds
     * @return array<int, int>
     *
     * @throws ValidationException
     */
    private function normalizar(array $documentoIds): array
    {
        $documentoIds = array_values(array_unique(array_map('intval', $documentoIds)));

        if ($documentoIds === []) {
            throw ValidationException::withMessages([
                'documentos' => 'Seleccione al menos un documento para incluir en la solicitud.',
            ]);
        }

        return $documentoIds;
    }

    /**
     * Los documentos pedidos, releídos del cliente, verificados y en el orden de la
     * solicitud. Es el ÚNICO camino por el que pasan la vista previa y la creación.
     *
     * @param  array<int, int>  $documentoIds
     * @return \Illuminate\Support\Collection<int, CobroDocumento>
     *
     * @throws ValidationException
     */
    private function seleccion(Cliente $cliente, array $documentoIds, ?CobroSolicitud $corrige, bool $bloquear): \Illuminate\Support\Collection
    {
        $documentos = CobroDocumento::deCliente($cliente->id)
            ->whereIn('id', $documentoIds)
            ->with('albaran')
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->get();

        $this->verificarSeleccion($documentos, $documentoIds, $corrige);
        $this->verificarCompletos($documentos);

        return $this->ordenar($documentos);
    }

    /**
     * Los renglones tal como se congelarán: orden y datos de fila del formato.
     *
     * @param  \Illuminate\Support\Collection<int, CobroDocumento>  $documentos
     * @return array<int, array{orden: int, documento: CobroDocumento, datos: array<string, mixed>}>
     */
    private function filas(\Illuminate\Support\Collection $documentos): array
    {
        $filas = [];
        foreach ($documentos->values() as $i => $documento) {
            $filas[] = [
                'orden' => $i + 1,
                'documento' => $documento,
                'datos' => ExportadorSolicitudCargaMasivaV1::datosDeFila($documento),
            ];
        }

        return $filas;
    }

    /**
     * Huella de los renglones: cliente, documento, orden y cada dato congelado, más la
     * identidad, el estado y el lote/carga de las NC de cada CCF. Cambia si cambia
     * cualquier cosa que iría al renglón o que respalda al CCF.
     *
     * @param  array<int, array{orden: int, documento: CobroDocumento, datos: array<string, mixed>}>  $filas
     * @param  array<string, mixed>  $notas  resultado de {@see NotasDelQuedan::analizar()}
     */
    private function huella(Cliente $cliente, array $filas, array $notas): string
    {
        $resumen = array_map(fn (array $fila) => [
            $fila['documento']->id,
            $fila['orden'],
            array_map(fn ($valor) => $valor === null ? null : (string) $valor, $fila['datos']),
        ], $filas);

        return hash('sha256', json_encode([$cliente->id, ExportadorSolicitudFactory::actual(), $resumen, $notas['huella']], JSON_THROW_ON_ERROR));
    }

    /**
     * Orden de la solicitud: por antigüedad del documento. Lo más viejo primero, que es lo
     * que más urge cobrar.
     *
     * @param  Collection<int, CobroDocumento>  $documentos
     * @return \Illuminate\Support\Collection<int, CobroDocumento>
     */
    private function ordenar(Collection $documentos): \Illuminate\Support\Collection
    {
        return $documentos
            ->sort(fn (CobroDocumento $a, CobroDocumento $b) => strcmp($a->fecha_emision?->toDateString() ?? '9999-12-31', $b->fecha_emision?->toDateString() ?? '9999-12-31')
                ?: strcmp((string) $a->numero_control, (string) $b->numero_control)
            )
            ->values();
    }

    /** Crea un evento si no existe ya uno idéntico (misma evidencia y misma línea). */
    private function evento(CobroDocumento $documento, TipoEventoCobro $tipo, array $datos): void
    {
        CobroEvento::firstOrCreate(
            [
                'cobro_documento_id' => $documento->id,
                'tipo' => $tipo->value,
                'evidencia_hash' => $datos['evidencia_hash'] ?? null,
                'referencia_linea' => $datos['referencia_linea'] ?? null,
            ],
            $datos + ['origen' => 'manual'],
        );
    }

    /**
     * Referencia legible y única: SOL-{codigo}-{YYYYMMDD}-{n del día}. La fecha es la de
     * GENERACIÓN del archivo, no la de los documentos, que pueden ser de cualquier fecha.
     */
    private function referencia(Cliente $cliente): string
    {
        $hoy = Carbon::today();
        $codigo = $this->perfiles->paraCliente($cliente->id)?->codigo_proveedor ?: ('CLI'.$cliente->id);

        $previas = CobroSolicitud::where('cliente_id', $cliente->id)
            ->whereDate('created_at', $hoy->toDateString())
            ->count();

        return sprintf('SOL-%s-%s-%02d', $codigo, $hoy->format('Ymd'), $previas + 1);
    }

    /**
     * Nombre con el que viaja el archivo: {codigo}{YYYYMMDDHHmm}.xlsx, la misma convención
     * que el cliente ya reconoce en sus acuses («RECIBIDO (000123202609040951)»). Se guarda
     * en la solicitud para que cada redescarga de la copia viaje con el mismo nombre.
     */
    private function nombreArchivo(Cliente $cliente): string
    {
        $codigo = $this->perfiles->paraCliente($cliente->id)?->codigo_proveedor
            ?: (string) config('ppq.codigo_proveedor', '000123');

        return $codigo.now('America/El_Salvador')->format('YmdHi').'.xlsx';
    }
}
