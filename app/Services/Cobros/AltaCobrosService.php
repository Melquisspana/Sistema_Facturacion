<?php

namespace App\Services\Cobros;

use App\Enums\Cobros\OrigenCobroDocumento;
use App\Enums\TipoDte;
use App\Facades\Ajustes;
use App\Models\Cliente;
use App\Models\Cobros\CobroDocumento;
use App\Models\Dte;
use App\Models\PpqItem;
use App\Models\User;
use App\Support\EjercicioControl;
use App\Support\IdentidadPpq;
use App\Support\NumeroControl;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Da de alta en el seguimiento de cobros los documentos del cliente: los CCF/NC ACEPTADOS
 * por Hacienda (fuente principal) y los que se incorporan a mano.
 *
 * ───────────────────────── Por qué el alta es automática ─────────────────────────
 *
 * El objetivo del módulo es controlar TODAS las facturas, «incluyendo las que nunca se
 * presentaron». Si el alta dependiera de que alguien agregue el documento —como pasa con
 * los lotes PPQ—, la factura olvidada sería justo la que nunca aparece: el sistema no
 * puede avisar de lo que no sabe que existe. Por eso el alta la dispara la ACEPTACIÓN del
 * documento, que es un hecho que ya ocurre solo.
 *
 * ────────────────────── El duplicado entre las dos fuentes ──────────────────────
 *
 * Un documento incorporado a mano y el mismo documento emitido por nosotros son el MISMO
 * cobro. La llave que lo impide es `numero_control_norm` junto con `ejercicio_control` y la normalización
 * es la que ya usa PPQ ({@see IdentidadPpq}). Cuando el alta automática se encuentra con
 * un documento que ya existe como externo, NO crea otro: lo ADOPTA, rellenando el
 * `dte_id` y los datos fiscales que el externo no podía tener (el sello, el importe real).
 * Así la incorporación manual deja de ser un callejón sin salida.
 *
 * ───────────────────────── Lo viejo no se declara pendiente ─────────────────────────
 *
 * El módulo nace con años de facturas detrás y sin rastro documento a documento de qué se
 * presentó o se cobró por el circuito anterior. Antes que suponer, se APROVECHA lo que sí
 * consta —los renglones PPQ, con su conciliación— y lo que queda sin respaldo se marca
 * para REVISIÓN HISTÓRICA, visible y con motivo. Un pendiente inventado se reclama dos
 * veces; un cobrado inventado se deja de reclamar. Las dos cosas cuestan dinero.
 *
 * No emite, no firma, no transmite y no toca ningún valor fiscal.
 */
class AltaCobrosService
{
    /** Tipos que entran al seguimiento: el CCF que se cobra y la NC que lo descuenta. */
    private const TIPOS = [TipoDte::CreditoFiscal->value, TipoDte::NotaCredito->value];

    /** Minutos durante los que un CCF aceptado que todavía no entró se anuncia como «entrando». */
    private const MINUTOS_RECIEN_ACEPTADO = 15;

    /** Motivo de revisión de un documento viejo que entró sin ningún antecedente. */
    public const MOTIVO_SIN_ANTECEDENTE = 'Emitida antes de que existiera este seguimiento y sin '
        .'ningún antecedente: no consta si se presentó ni si se cobró.';

    /**
     * Sincroniza el seguimiento del cliente con sus documentos aceptados.
     *
     * Es IDEMPOTENTE: correrla dos veces no crea nada nuevo ni pisa estados. Solo agrega
     * los que faltan y adopta los externos que ahora tienen su DTE.
     *
     * Un número que ya está en el seguimiento de OTRO cliente no se da de alta: la llave es
     * única en todo el sistema y crearlo tumbaba la corrida entera. Se cuenta en
     * `en_otro_cliente`, se anota en el log y se sigue con los demás.
     *
     * @return array{creados: int, adoptados: int, sin_cambio: int, revision_historica: int, en_otro_cliente: int}
     */
    public function sincronizar(Cliente $cliente): array
    {
        $resumen = ['creados' => 0, 'adoptados' => 0, 'sin_cambio' => 0, 'revision_historica' => 0, 'en_otro_cliente' => 0];

        $existentes = CobroDocumento::deCliente($cliente->id)
            ->get(['id', 'cliente_id', 'dte_id', 'numero_control_norm', 'ejercicio_control'])
            ->keyBy(fn ($d) => EjercicioControl::llave($d->numero_control_norm, $d->ejercicio_control));

        $this->elegibles($cliente)->chunkById(200, function ($documentos) use (&$resumen, $existentes, $cliente) {
            foreach ($documentos as $dte) {
                $clave = EjercicioControl::llave($dte->numero_control, EjercicioControl::de($dte->fecha_emision));
                $alta = $this->incorporarDte($cliente, $dte, $clave === null ? null : $existentes->get($clave));

                if ($alta === null) {
                    continue;
                }

                [$resultado, $documento] = $alta;
                $resumen[$resultado]++;

                if ($documento !== null) {
                    if ($documento->revisar_historico) {
                        $resumen['revision_historica']++;
                    }

                    $existentes->put($clave, $documento);
                }
            }
        });

        return $resumen;
    }

    /**
     * Da de alta UN documento en el momento en que Hacienda lo acepta, sin esperar a la tarea
     * programada. Lo llama el observador del DTE cuando el estado pasa a Aceptado.
     *
     * No tiene reglas propias: pregunta por ESTE documento a la misma consulta de elegibles
     * y lo incorpora por el mismo camino que {@see sincronizar()}. Lo único que agrega son las
     * dos condiciones que la tarea programada trae de afuera: la llave `cobros.alta.automatica`
     * y que el cliente tenga perfil documental activo. Por eso la tarea y este método no se
     * pisan: el que llega segundo encuentra el documento y no hace nada.
     *
     * No vincula albaranes ni marca revisión histórica por antigüedad: eso sigue en la tarea.
     *
     * @return 'creados'|'adoptados'|'sin_cambio'|'en_otro_cliente'|null `null` si no le corresponde entrar
     */
    public function darDeAlta(Dte $dte): ?string
    {
        $tipo = $dte->tipo_dte instanceof TipoDte ? $dte->tipo_dte->value : (string) $dte->tipo_dte;

        if (! in_array($tipo, self::TIPOS, true) || ! Ajustes::bool('cobros.alta.automatica')) {
            return null;
        }

        $cliente = Cliente::query()
            ->whereKey($dte->cliente_id)
            ->whereHas('perfilDocumento', fn ($q) => $q->where('activo', true))
            ->first();

        // Se lee de nuevo de la base: lo que decide es lo que quedó guardado, no el modelo en memoria.
        $elegible = $cliente === null ? null : $this->elegibles($cliente)->whereKey($dte->id)->first();

        if ($elegible === null) {
            return null;
        }

        return $this->incorporarDte($cliente, $elegible)[0] ?? null;
    }

    /**
     * Cuántos CCF del cliente aceptados hace instantes todavía NO están en el seguimiento.
     *
     * Lo normal es cero, porque el alta ocurre al aceptarse. Si quedó alguno afuera (el alta
     * falló y lo recoge la tarea programada), la pantalla lo anuncia en vez de mostrar la
     * lista como si estuviera completa. Con el alta automática apagada nada «está entrando»,
     * así que no se anuncia nada.
     */
    public function recienAceptadosSinEntrar(Cliente $cliente): int
    {
        if (! Ajustes::bool('cobros.alta.automatica')) {
            return 0;
        }

        return $this->elegibles($cliente)
            ->where('tipo_dte', TipoDte::CreditoFiscal->value)
            ->where('fecha_procesamiento_mh', '>=', now()->subMinutes(self::MINUTOS_RECIEN_ACEPTADO))
            ->whereNotIn('id', CobroDocumento::query()->whereNotNull('dte_id')->select('dte_id'))
            ->count();
    }

    /**
     * Incorpora al seguimiento UN documento elegible. Es el único lugar donde se decide si se
     * crea, se adopta o se deja: lo usan la sincronización del cliente y el alta al aceptar.
     *
     * Si no llega el seguimiento ya conocido, se busca en la base. La sincronización trae el
     * suyo de una lista leída al empezar, y entre esa lectura y este documento el alta al
     * aceptar pudo haberlo creado: por eso «no está en la lista» nunca alcanza para crear.
     *
     * Devuelve el resultado y, si se creó, el documento; `null` sin número de control.
     *
     * @return array{0: 'creados'|'adoptados'|'sin_cambio'|'en_otro_cliente', 1: ?CobroDocumento}|null
     */
    private function incorporarDte(Cliente $cliente, Dte $dte, ?CobroDocumento $existente = null): ?array
    {
        if (IdentidadPpq::normalizar($dte->numero_control) === null) {
            // Sin número de control no hay identidad posible y no se inventa una.
            return null;
        }

        $existente ??= $this->enSeguimiento($dte);

        if ($existente !== null && (int) $existente->cliente_id !== (int) $cliente->id) {
            Log::warning('Cobros: el documento ya está en el seguimiento de otro cliente; no se dio de alta.', [
                'numero_control' => $dte->numero_control,
                'dte_id' => $dte->id,
                'cliente_id' => $cliente->id,
                'cobro_documento_id' => $existente->id,
                'otro_cliente_id' => (int) $existente->cliente_id,
            ]);

            return ['en_otro_cliente', null];
        }

        if ($existente !== null) {
            return [$this->adoptar($existente->id, $dte) ? 'adoptados' : 'sin_cambio', null];
        }

        return ['creados', $this->crearDesdeDte($cliente, $dte)];
    }

    /**
     * Qué HARÍA {@see sincronizar()}, sin escribir una sola fila.
     *
     * Recorre exactamente los mismos documentos y aplica exactamente los mismos criterios;
     * lo único que no hace es guardar. Es el paso previo obligatorio antes de dejar que el
     * alta corra sola: sobre los datos reales se ve cuántos entrarían y cuántos vienen con
     * antecedente del circuito anterior.
     *
     * @return array{creados: int, adoptados: int, sin_cambio: int, revision_historica: int, en_otro_cliente: int}
     */
    public function previsualizar(Cliente $cliente): array
    {
        $resumen = ['creados' => 0, 'adoptados' => 0, 'sin_cambio' => 0, 'revision_historica' => 0, 'en_otro_cliente' => 0];

        $existentes = CobroDocumento::deCliente($cliente->id)
            ->get(['id', 'dte_id', 'numero_control_norm', 'ejercicio_control'])
            ->keyBy(fn ($d) => EjercicioControl::llave($d->numero_control_norm, $d->ejercicio_control));

        $this->elegibles($cliente)->chunkById(200, function ($documentos) use (&$resumen, $existentes, $cliente) {
            foreach ($documentos as $dte) {
                $clave = EjercicioControl::llave($dte->numero_control, EjercicioControl::de($dte->fecha_emision));

                if ($clave === null) {
                    continue;
                }

                $existente = $existentes->get($clave);

                if ($existente !== null) {
                    $existente->dte_id === null ? $resumen['adoptados']++ : $resumen['sin_cambio']++;

                    continue;
                }

                if ($this->enOtroCliente($cliente, $dte) !== null) {
                    $resumen['en_otro_cliente']++;

                    continue;
                }

                $resumen['creados']++;

                if ($this->antecedente($dte->numero_control, $dte->fecha_emision)['revisar']) {
                    $resumen['revision_historica']++;
                }
            }
        });

        return $resumen;
    }

    /**
     * Documentos del cliente que pueden entrar al seguimiento: CCF y NC realmente
     * aceptados por Hacienda.
     *
     * Se exige la aceptación REAL (sello del MH, no un sello simulado) porque antes de eso
     * el documento no es cobrable y ponerlo en la bandeja de cobro sería prometer un
     * ingreso que todavía puede no existir.
     *
     * @return Builder<Dte>
     */
    private function elegibles(Cliente $cliente): Builder
    {
        return Dte::query()
            ->where('cliente_id', $cliente->id)
            ->whereIn('tipo_dte', self::TIPOS)
            // Solo el ambiente en que opera el sistema: un CCF de pruebas (00) en el
            // servidor de producción no es una factura que se cobre.
            ->when(config('dte.ambiente') === '01', fn ($q) => $q->where('ambiente', '!=', '00'))
            ->aceptadoRealMh();
    }

    /** El seguimiento que ya tiene este número y ejercicio, sea del cliente que sea (la llave es única). */
    private function enSeguimiento(Dte $dte): ?CobroDocumento
    {
        return CobroDocumento::query()
            ->where('numero_control_norm', IdentidadPpq::normalizar($dte->numero_control))
            ->where('ejercicio_control', EjercicioControl::de($dte->fecha_emision) ?? EjercicioControl::hoy())
            ->first(['id', 'cliente_id', 'dte_id']);
    }

    /** El seguimiento de OTRO cliente que ya tiene este número y ejercicio, si lo hay. */
    private function enOtroCliente(Cliente $cliente, Dte $dte): ?CobroDocumento
    {
        $documento = $this->enSeguimiento($dte);

        return $documento !== null && (int) $documento->cliente_id !== (int) $cliente->id ? $documento : null;
    }

    /** Crea el seguimiento de un DTE aceptado, con sus antecedentes si los hay. */
    private function crearDesdeDte(Cliente $cliente, Dte $dte): CobroDocumento
    {
        $antecedente = $this->antecedente($dte->numero_control, $dte->fecha_emision);

        return DB::transaction(function () use ($cliente, $dte, $antecedente) {
            $documento = CobroDocumento::create([
                'cliente_id' => $cliente->id,
                'origen' => OrigenCobroDocumento::Dte->value,
                'dte_id' => $dte->id,
                'tipo_dte' => $dte->tipo_dte instanceof TipoDte ? $dte->tipo_dte->value : (string) $dte->tipo_dte,
                'numero_control' => (string) $dte->numero_control,
                'codigo_generacion' => $dte->codigo_generacion,
                'sello_recepcion' => $dte->sello_recepcion,
                'fecha_emision' => $dte->fecha_emision,
                'monto' => $dte->total_pagar,
                'establecimiento_codigo' => NumeroControl::establecimiento($dte->numero_control),
                'punto_venta_codigo' => NumeroControl::puntoVenta($dte->numero_control),
                'revisar_historico' => $antecedente['revisar'],
                'revisar_historico_motivo' => $antecedente['motivo'],
            ]);

            return $documento;
        });
    }

    /**
     * Rellena con los datos del DTE un seguimiento que se había creado a mano.
     *
     * Solo completa lo que FALTA y nunca pisa un dato ya presente: quien incorporó el
     * documento a mano pudo haber corregido algo, y el alta automática no tiene autoridad
     * para deshacerlo. Devuelve true si algo cambió.
     */
    private function adoptar(int $documentoId, Dte $dte): bool
    {
        $documento = CobroDocumento::find($documentoId);

        if ($documento === null || $documento->dte_id !== null) {
            return false;
        }

        $documento->forceFill(array_filter([
            'dte_id' => $dte->id,
            'origen' => OrigenCobroDocumento::Dte->value,
            'codigo_generacion' => $documento->codigo_generacion ?: $dte->codigo_generacion,
            'sello_recepcion' => $documento->sello_recepcion ?: $dte->sello_recepcion,
            'fecha_emision' => $documento->fecha_emision ?: $dte->fecha_emision,
            'monto' => $documento->monto ?: $dte->total_pagar,
        ], fn ($v) => $v !== null))->save();

        return true;
    }

    /**
     * Qué se sabe de este documento por el circuito ANTERIOR (los renglones PPQ).
     *
     * No se traduce el antecedente a un pago: un renglón PPQ conciliado dice que un archivo
     * de pagos lo mencionó, pero ese archivo se aplicó a OTRO seguimiento y su evidencia
     * vive allá. Copiar el importe acá lo daría por cobrado dos veces en dos sitios. Lo que
     * se hace es SEÑALARLO, con el motivo y el lote, para que una persona lo confirme.
     *
     * Público porque la importación desde Gmail ({@see ImportadorEnviadosService}) da de
     * alta documentos que pudieron viajar por PPQ y tiene que aplicar el mismo criterio.
     *
     * @return array{revisar: bool, motivo: ?string}
     */
    public function antecedente(?string $numeroControl, CarbonInterface|\DateTimeInterface|string|int|null $fecha = null): array
    {
        $clave = IdentidadPpq::normalizar($numeroControl);

        if ($clave === null) {
            return ['revisar' => false, 'motivo' => null];
        }

        $item = PpqItem::query()
            ->where(IdentidadPpq::columnaNormalizada('numero_control'), $clave)
            ->where('ejercicio_control', EjercicioControl::de($fecha) ?? EjercicioControl::hoy())
            ->with('lote:id,referencia')
            ->orderByDesc('id')
            ->first();

        if ($item !== null) {
            $lote = $item->lote?->referencia ?? ('#'.$item->ppq_lote_id);

            return $item->estaConciliado()
                ? [
                    'revisar' => true,
                    'motivo' => "Ya figura cobrado en el lote PPQ {$lote} por el circuito anterior. "
                        .'Confirmar antes de volver a reclamarlo.',
                ]
                : [
                    'revisar' => true,
                    'motivo' => "Ya viajó en el lote PPQ {$lote} por el circuito anterior, sin pago conciliado. "
                        .'Confirmar si se presentó y si se cobró.',
                ];
        }

        return ['revisar' => false, 'motivo' => null];
    }

    /**
     * Marca para revisión histórica los documentos VIEJOS que entraron sin antecedente.
     *
     * Va en un paso aparte y no dentro del alta porque la antigüedad se mide contra el día
     * en que el seguimiento empezó a existir para ese documento, no contra su emisión: una
     * factura de hace tres meses dada de alta hoy no tiene historia acá, pero una emitida
     * hoy sí la tendrá desde el primer día.
     *
     * @return int cuántos se marcaron
     */
    public function marcarRevisionHistorica(Cliente $cliente, ?Carbon $corte = null): int
    {
        // Con fecha de inicio configurada, solo lo emitido ANTES de ella es histórico: un CCF
        // nuevo que envejece sin presentarse es «no presentado», no un caso histórico.
        $inicio = Ajustes::texto('cobros.inicio_seguimiento');
        $dias = (int) Ajustes::entero('cobros.dias_revision_historica', 30);
        $corte ??= filled($inicio) ? Carbon::parse($inicio) : Carbon::today()->subDays($dias);

        return CobroDocumento::deCliente($cliente->id)
            ->where('revisar_historico', false)
            ->whereNotNull('fecha_emision')
            ->whereDate('fecha_emision', '<', $corte->toDateString())
            ->whereDoesntHave('eventos')
            ->update(CobroDocumento::recortarTextos([
                'revisar_historico' => true,
                'revisar_historico_motivo' => self::MOTIVO_SIN_ANTECEDENTE,
            ]));
    }

    /**
     * Incorpora a mano un documento que no está en nuestro sistema (los de contabilidad y
     * los que llegan por correo).
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException si ese documento ya está en el seguimiento
     */
    public function incorporar(Cliente $cliente, array $datos, ?User $usuario = null): CobroDocumento
    {
        $clave = IdentidadPpq::normalizar($datos['numero_control'] ?? null);

        if ($clave === null) {
            throw ValidationException::withMessages([
                'numero_control' => 'El número de control es obligatorio: es lo que identifica al documento.',
            ]);
        }

        $consulta = CobroDocumento::where('numero_control_norm', $clave);
        if (empty($datos['fecha_emision']) && (clone $consulta)->exists()) {
            $anios = (clone $consulta)->orderBy('ejercicio_control')->pluck('ejercicio_control')->unique()->implode(' · ');
            throw ValidationException::withMessages(['fecha_emision' => "Ese número de control ya existe en {$anios}: indicá la fecha de emisión para saber de qué año es."]);
        }
        $existente = $consulta->where('ejercicio_control', EjercicioControl::de($datos['fecha_emision'] ?? null) ?? EjercicioControl::hoy())->first();

        if ($existente !== null) {
            throw ValidationException::withMessages([
                'numero_control' => "Ese documento ya está en el seguimiento ({$existente->numero_control}, "
                    .$existente->origen->label().'). No se incorpora dos veces: sería reclamarlo dos veces.',
            ]);
        }

        return CobroDocumento::create([
            'cliente_id' => $cliente->id,
            'origen' => OrigenCobroDocumento::Externo->value,
            'tipo_dte' => (string) ($datos['tipo_dte'] ?? TipoDte::CreditoFiscal->value),
            'numero_control' => (string) $datos['numero_control'],
            'codigo_generacion' => $datos['codigo_generacion'] ?? null,
            'sello_recepcion' => $datos['sello_recepcion'] ?? null,
            'fecha_emision' => $datos['fecha_emision'] ?? null,
            'monto' => $datos['monto'] ?? null,
            'establecimiento_codigo' => NumeroControl::establecimiento($datos['numero_control']),
            'punto_venta_codigo' => NumeroControl::puntoVenta($datos['numero_control']),
            'observaciones' => $datos['observaciones'] ?? null,
        ]);
    }
}
