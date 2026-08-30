<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Models\Dte;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Support\VigenciaFiscalDte;
use Illuminate\Support\Collection;

/**
 * Asociación AUTOMÁTICA de un CCF nuevo a la salida que lo está llevando.
 *
 * La regla completa, y no hay excepciones a ella:
 *
 *     CCF (tipo 03) de la serie P002, FISCALMENTE VIGENTE
 *       (producción, aceptado de verdad por Hacienda, no archivado ni invalidado
 *        — la decide {@see VigenciaFiscalDte}, no una condición propia)
 *       → tiene `cliente_sucursal_id`
 *       → esa sucursal tiene `ruta_id`
 *       → esa ruta tiene EXACTAMENTE UNA salida EN CURSO
 *       → el documento no pertenece ya a ninguna salida abierta
 *     ⇒ se asocia. En cualquier otro caso NO se hace nada.
 *
 * Por qué es tan restrictiva:
 *
 *  - 0 salidas en curso → nadie lo está llevando. Meterlo en una planificada sería
 *    inventar que ya salió.
 *  - 2 o más salidas en curso de la misma ruta → hay dos camiones en la misma zona
 *    y el sistema NO sabe cuál lleva este documento. Elegir «la más reciente» daría
 *    una respuesta siempre, y sería la correcta solo a veces: eso es exactamente el
 *    error caro, porque nadie revisa lo que el sistema hizo solo. Se deja como
 *    EXCEPCIÓN para que una persona lo asigne mirando.
 *  - ya asignado → jamás se mueve solo. Mover un documento es un acto con dueño y
 *    con auditoría (ver {@see AsignadorDocumentos::mover()}).
 *
 * Solo se automatiza la serie configurada (P002). Los documentos históricos P001 no
 * los emitió este sistema: se agregan a mano.
 *
 * Nada de esto se ejecuta solo. Lo dispara el comando `rutas:asociar-documentos` o
 * el botón de la pantalla de la salida; NO hay hook en la emisión del DTE, ni tarea
 * programada, a propósito: la emisión fiscal no debe depender de este módulo ni
 * enterarse de que existe.
 */
class AsignadorAutomaticoDocumentos
{
    public const ASIGNADO = 'asignado';

    public const SIN_SUCURSAL = 'sin_sucursal';

    public const SUCURSAL_SIN_RUTA = 'sucursal_sin_ruta';

    public const SIN_SALIDA_EN_CURSO = 'sin_salida_en_curso';

    public const VARIAS_SALIDAS_EN_CURSO = 'varias_salidas_en_curso';

    public const YA_ASIGNADO = 'ya_asignado';

    public const SERIE_NO_AUTOMATICA = 'serie_no_automatica';

    public const NO_ES_CCF_VIGENTE = 'no_es_ccf_vigente';

    /** Explicación para la pantalla y el comando. */
    public const MOTIVOS = [
        self::ASIGNADO => 'Asociado a la salida en curso de su ruta',
        self::SIN_SUCURSAL => 'El documento no tiene sala asignada',
        self::SUCURSAL_SIN_RUTA => 'La sala no pertenece a ninguna ruta',
        self::SIN_SALIDA_EN_CURSO => 'La ruta no tiene ninguna salida en curso',
        self::VARIAS_SALIDAS_EN_CURSO => 'La ruta tiene más de una salida en curso: hay que elegir a mano',
        self::YA_ASIGNADO => 'El documento ya pertenece a una salida',
        self::SERIE_NO_AUTOMATICA => 'La serie del documento no se asocia automáticamente',
        self::NO_ES_CCF_VIGENTE => 'No es un CCF vigente ante Hacienda (tipo 03, de producción, aceptado y sin archivar)',
    ];

    public function __construct(private readonly AsignadorDocumentos $asignador) {}

    /**
     * Decide QUÉ correspondería hacer, sin escribir nada. Separado de {@see asignar()}
     * para que la pantalla pueda explicar por qué un documento no se asoció solo, y
     * para poder correr el comando en seco.
     *
     * @return array{estado: string, motivo: string, salida: ?SalidaRuta}
     */
    public function evaluar(Dte $dte): array
    {
        // `tipo_dte` está casteado a TipoDte: se compara por su valor, no por el enum,
        // para no acoplar este módulo al catálogo fiscal más de lo imprescindible.
        if ($dte->tipo_dte?->value !== '03') {
            return $this->resultado(self::NO_ES_CCF_VIGENTE);
        }

        // El resto de la vigencia lo decide la regla COMPARTIDA, no una condición propia.
        // Antes acá solo se miraba «no archivado», así que un borrador o un documento del
        // ambiente de PRUEBAS podía asociarse solo a una salida —y arrastrar un número de
        // control que en producción pertenece a otro documento—.
        if (! VigenciaFiscalDte::esVigente($dte)) {
            return $this->resultado(self::NO_ES_CCF_VIGENTE);
        }

        $serie = config('rutas.punto_venta_automatico');
        if (blank($serie) || $dte->puntoVenta?->codigo !== $serie) {
            return $this->resultado(self::SERIE_NO_AUTOMATICA);
        }

        if ($dte->cliente_sucursal_id === null) {
            return $this->resultado(self::SIN_SUCURSAL);
        }

        $rutaId = $dte->clienteSucursal?->ruta_id;
        if ($rutaId === null) {
            return $this->resultado(self::SUCURSAL_SIN_RUTA);
        }

        // Se comprueba ANTES de mirar las salidas: si ya tiene dueño, cuántas salidas
        // haya en curso da igual, no se toca.
        if ($this->yaAsignado($dte)) {
            return $this->resultado(self::YA_ASIGNADO);
        }

        $enCurso = SalidaRuta::where('ruta_id', $rutaId)
            ->enEstado(EstadoSalidaRuta::EnCurso)
            ->get();

        return match ($enCurso->count()) {
            0 => $this->resultado(self::SIN_SALIDA_EN_CURSO),
            1 => $this->resultado(self::ASIGNADO, $enCurso->first()),
            default => $this->resultado(self::VARIAS_SALIDAS_EN_CURSO),
        };
    }

    /**
     * Evalúa y, solo si la evaluación dice ASIGNADO, escribe. El alta pasa por
     * {@see AsignadorDocumentos}, así que hereda el candado de unicidad y la auditoría
     * (queda registrada como asociación automática, distinguible de la manual).
     *
     * @return array{estado: string, motivo: string, salida: ?SalidaRuta, documento: ?SalidaRutaDocumento}
     */
    public function asignar(Dte $dte, ?User $usuario = null): array
    {
        $decision = $this->evaluar($dte);

        if ($decision['estado'] !== self::ASIGNADO) {
            return $decision + ['documento' => null];
        }

        $documento = $this->asignador->traduciendoChoques(
            fn () => $this->asignador->agregarDte($decision['salida'], $dte, $usuario, automatica: true),
            (string) $dte->numero_control,
        );

        return $decision + ['documento' => $documento];
    }

    /**
     * Barrido: evalúa los CCF de los últimos `$dias` días y asocia los que cumplan.
     * Los que no, se devuelven agrupados por motivo — esa lista ES el informe de
     * excepciones, y es lo que una persona tiene que mirar.
     *
     * @return array<string, array<int, array{dte: Dte, salida: ?SalidaRuta}>>
     */
    public function barrer(int $dias, ?User $usuario = null, bool $enSeco = false): array
    {
        $desde = now()->subDays($dias)->toDateString();

        // El barrido ya no recorre borradores ni documentos de pruebas: la misma regla que
        // aplica `evaluar()` se aplica en la consulta, para no traerse a memoria miles de
        // filas que van a descartarse una por una.
        $candidatos = VigenciaFiscalDte::filtrar(Dte::query())
            ->where('tipo_dte', '03')
            ->whereNotNull('cliente_sucursal_id')
            ->whereDate('fecha_emision', '>=', $desde)
            ->with(['clienteSucursal:id,nombre,ruta_id', 'puntoVenta:id,codigo'])
            ->orderBy('id')
            ->get();

        $porMotivo = [];

        foreach ($candidatos as $dte) {
            $resultado = $enSeco ? $this->evaluar($dte) : $this->asignar($dte, $usuario);
            $porMotivo[$resultado['estado']][] = ['dte' => $dte, 'salida' => $resultado['salida']];
        }

        return $porMotivo;
    }

    /**
     * ¿ESTE documento ya pertenece a alguna salida abierta?
     *
     * Se pregunta primero por el vínculo explícito (`dte_id`), que es la respuesta exacta,
     * y solo si no hay ninguna fila con ese vínculo se mira el número de control ACOTADO
     * AL MISMO AMBIENTE. Sin esa segunda condición, un documento de pruebas asignado a una
     * salida haría que el sistema creyera que el CCF real de producción con el mismo
     * correlativo «ya tiene dueño», y ese CCF nunca se asociaría a la salida que de verdad
     * lo está llevando.
     *
     * El número de control se sigue mirando —y no solo `dte_id`— porque el documento pudo
     * cargarse antes como histórico, sin vínculo; las filas históricas tienen ambiente
     * NULL y por eso no compiten con las de un documento local.
     */
    private function yaAsignado(Dte $dte): bool
    {
        $vigentes = SalidaRutaDocumento::vigentes();

        if ($vigentes->clone()->where('dte_id', $dte->id)->exists()) {
            return true;
        }

        return $vigentes
            ->where('numero_control', (string) $dte->numero_control)
            ->where('ambiente', $dte->ambiente?->value)
            ->exists();
    }

    /** @return array{estado: string, motivo: string, salida: ?SalidaRuta} */
    private function resultado(string $estado, ?SalidaRuta $salida = null): array
    {
        return ['estado' => $estado, 'motivo' => self::MOTIVOS[$estado], 'salida' => $salida];
    }

    /**
     * Los motivos que representan una EXCEPCIÓN que alguien debe resolver, frente a
     * los que son simplemente «no aplica».
     *
     * @return Collection<int, string>
     */
    public static function motivosDeExcepcion(): Collection
    {
        return collect([self::VARIAS_SALIDAS_EN_CURSO, self::SUCURSAL_SIN_RUTA]);
    }
}
