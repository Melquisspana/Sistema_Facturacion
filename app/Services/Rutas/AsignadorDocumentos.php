<?php

namespace App\Services\Rutas;

use App\Enums\EstadoSalidaRuta;
use App\Enums\MotivoRevisionDocumento;
use App\Exceptions\Rutas\DocumentoNoVigenteException;
use App\Exceptions\Rutas\DocumentoYaAsignadoException;
use App\Models\Dte;
use App\Models\SalidaRuta;
use App\Models\SalidaRutaDocumento;
use App\Models\User;
use App\Support\OrdenCompra;
use App\Support\Sala;
use App\Support\VigenciaFiscalDte;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Punto ÚNICO por el que un documento entra, sale o se mueve entre salidas, y por
 * el que se marcan los dos hechos manuales (papel recibido, requiere NC).
 *
 * Todo pasa por acá para que la regla de unicidad y la auditoría no dependan de
 * que cada controlador se acuerde de aplicarlas.
 *
 * La unicidad se defiende en DOS capas, y las dos hacen falta:
 *
 *  1. Una consulta previa dentro de una transacción, que permite responder con un
 *     mensaje útil («está en la salida de San Miguel del 14 de agosto»).
 *  2. El índice único de la tabla, que es el que de verdad garantiza el invariante
 *     cuando dos personas guardan a la vez desde dos pestañas. Si salta, se traduce
 *     a la misma excepción de dominio en vez de reventar con un error de SQL.
 *
 * NADA de lo que hay acá escribe en `dtes`, `ppq_albaranes`, correlativos ni
 * ningún artefacto fiscal. El DTE original no se toca jamás.
 */
class AsignadorDocumentos
{
    /**
     * Agrega un CCF que existe en `dtes` (camino P002).
     *
     * Los datos visibles NO se copian: se leen del DTE cada vez. Solo se guarda lo
     * necesario para identificarlo, para poder buscar su albarán (control y OC) y para
     * saber de qué AMBIENTE es.
     *
     * El candado fiscal está acá y no en cada controlador, por la misma razón que la
     * unicidad y la auditoría: para que no dependa de que cada vía se acuerde de
     * aplicarlo. Un borrador, un rechazado o un documento de pruebas no viaja en una
     * salida de ruta, se agregue desde donde se agregue.
     *
     * @throws DocumentoNoVigenteException
     */
    public function agregarDte(SalidaRuta $salida, Dte $dte, ?User $usuario, bool $automatica = false): SalidaRutaDocumento
    {
        $this->exigirSalidaAbierta($salida);
        $this->exigirDocumentoVigente($dte);

        return $this->crear($salida, [
            'dte_id' => $dte->id,
            'numero_control' => (string) $dte->numero_control,
            'numero_orden_compra' => $dte->numero_orden_compra,
            'cliente_sucursal_id' => $dte->cliente_sucursal_id,
            'origen' => SalidaRutaDocumento::ORIGEN_P002,
            // Snapshot del ambiente: es la otra mitad de la identidad fiscal del
            // documento, y sin ella un CCF de pruebas y uno real con el mismo número de
            // control son indistinguibles en esta tabla.
            'ambiente' => $dte->ambiente?->value,
        ], $usuario, $automatica);
    }

    /**
     * Agrega un documento HISTÓRICO P001, que no está en `dtes` y no se va a importar.
     *
     * Lo único obligatorio es el número de control. El resto es un snapshot de lo que
     * se haya podido averiguar: se guarda tal cual llegó y no se completa con
     * suposiciones. Un campo vacío se muestra vacío.
     *
     * @param  array{numero_control: string, numero_orden_compra?: ?string, cliente_nombre?: ?string, sala_nombre?: ?string, monto?: mixed, fecha_documento?: ?string}  $datos
     */
    public function agregarHistorico(SalidaRuta $salida, array $datos, ?User $usuario): SalidaRutaDocumento
    {
        $this->exigirSalidaAbierta($salida);

        $orden = filled($datos['numero_orden_compra'] ?? null)
            ? OrdenCompra::normalizar((string) $datos['numero_orden_compra'])
            : null;

        // La sala se intenta resolver desde la OC con el mismo mecanismo de PPQ. Si no
        // resuelve a una sucursal fiscal, queda solo el nombre —o nada—: nunca se da
        // de alta una sucursal para tapar el hueco.
        $codigoSala = OrdenCompra::salaDesde($orden);

        return $this->crear($salida, [
            'dte_id' => null,
            'numero_control' => trim((string) $datos['numero_control']),
            'numero_orden_compra' => $orden,
            'cliente_sucursal_id' => null,
            'origen' => SalidaRutaDocumento::ORIGEN_P001,
            'cliente_nombre' => $datos['cliente_nombre'] ?? null,
            'sala_nombre' => $datos['sala_nombre'] ?? Sala::nombre($codigoSala),
            'monto' => filled($datos['monto'] ?? null) ? (float) $datos['monto'] : null,
            'fecha_documento' => $datos['fecha_documento'] ?? null,
        ], $usuario, automatica: false);
    }

    /** Quita el documento de la salida. No borra ni altera el DTE: borra el renglón de la bitácora. */
    public function quitar(SalidaRutaDocumento $documento, ?User $usuario): void
    {
        $this->exigirSalidaAbierta($documento->salida);

        $salida = $documento->salida;
        $numero = $documento->numeroLegible();

        $documento->delete();

        $this->auditar($salida, $usuario, 'quitó el documento de la salida', [
            'numero_control' => $numero,
            'dte_id' => $documento->dte_id,
        ]);
    }

    /**
     * Mueve el documento a otra salida. Es la única forma de que un documento cambie
     * de dueño, y es siempre un acto de una persona: el barrido automático nunca mueve
     * nada (ver {@see AsignadorAutomaticoDocumentos}).
     */
    public function mover(SalidaRutaDocumento $documento, SalidaRuta $destino, ?User $usuario): SalidaRutaDocumento
    {
        $origen = $documento->salida;

        $this->exigirSalidaAbierta($origen);
        $this->exigirSalidaAbierta($destino);

        if ($origen->id === $destino->id) {
            return $documento;
        }

        // Cambia de salida conservando la MISMA fila: así el papel recibido y la marca
        // de NC viajan con el documento en vez de perderse por el camino.
        $documento->update([
            'salida_ruta_id' => $destino->id,
            'bloqueo_asignacion' => $this->bloqueoPara($destino),
        ]);

        $descripcion = 'movió el documento de salida';
        $propiedades = [
            'numero_control' => $documento->numeroLegible(),
            'dte_id' => $documento->dte_id,
            'salida_origen' => $origen->id,
            'salida_destino' => $destino->id,
        ];

        // Se audita en las DOS salidas: mirando cualquiera de las dos historias tiene
        // que poder verse que el documento se fue o que llegó.
        $this->auditar($origen, $usuario, $descripcion, $propiedades);
        $this->auditar($destino, $usuario, 'recibió un documento movido desde otra salida', $propiedades);

        return $documento->refresh();
    }

    // ------------------------------------------------------- documentación física

    /**
     * Marca que volvió el papel firmado de uno o varios documentos. Manual por
     * naturaleza: no hay ningún dato del que se pueda derivar.
     *
     * @param  array<int, int>  $ids
     * @return int cuántos cambiaron de verdad
     */
    public function marcarDocumentacionFisica(SalidaRuta $salida, array $ids, ?User $usuario): int
    {
        $this->exigirSalidaNoCancelada($salida);

        $documentos = $salida->documentos()->whereIn('id', $ids)->whereNull('documentacion_fisica_recibida_at')->get();

        foreach ($documentos as $documento) {
            $documento->update([
                'documentacion_fisica_recibida_at' => now(),
                'documentacion_fisica_recibida_por' => $usuario?->id,
            ]);

            $this->auditar($salida, $usuario, 'registró la documentación física recibida', [
                'numero_control' => $documento->numeroLegible(),
                'dte_id' => $documento->dte_id,
            ]);
        }

        return $documentos->count();
    }

    /**
     * Deshace la marca anterior. Existe porque marcar de más es un error humano
     * frecuente, y dejarlo sin salida obligaría a tocar la base a mano. Queda auditado
     * igual que el marcado, para que se vea quién lo deshizo.
     */
    public function desmarcarDocumentacionFisica(SalidaRutaDocumento $documento, ?User $usuario): void
    {
        $this->exigirSalidaNoCancelada($documento->salida);

        if (! $documento->documentacionFisicaRecibida()) {
            return;
        }

        $documento->update([
            'documentacion_fisica_recibida_at' => null,
            'documentacion_fisica_recibida_por' => null,
        ]);

        $this->auditar($documento->salida, $usuario, 'desmarcó la documentación física recibida', [
            'numero_control' => $documento->numeroLegible(),
            'dte_id' => $documento->dte_id,
        ]);
    }

    // -------------------------------------------------------------- requiere NC

    /**
     * Marca que el documento hay que revisarlo por una posible nota de crédito.
     *
     * ESTO NO CREA NINGUNA NC. Es una bandera operativa puesta por quien vio el
     * problema en la sala. La NC, si se emite, se emite en el módulo fiscal, y el
     * sistema la detecta sola cuando exista.
     */
    public function marcarRequiereNc(SalidaRutaDocumento $documento, ?MotivoRevisionDocumento $motivo, ?string $nota, ?User $usuario): void
    {
        $this->exigirSalidaNoCancelada($documento->salida);

        $documento->update([
            'requiere_nc' => true,
            'motivo_revision' => $motivo,
            'motivo_revision_nota' => filled($nota) ? trim($nota) : null,
        ]);

        $this->auditar($documento->salida, $usuario, 'marcó el documento como «requiere NC»', [
            'numero_control' => $documento->numeroLegible(),
            'dte_id' => $documento->dte_id,
            'motivo' => $motivo?->value,
            'nota' => filled($nota) ? trim($nota) : null,
        ]);
    }

    public function desmarcarRequiereNc(SalidaRutaDocumento $documento, ?User $usuario): void
    {
        $this->exigirSalidaNoCancelada($documento->salida);

        if (! $documento->requiere_nc) {
            return;
        }

        $documento->update([
            'requiere_nc' => false,
            'motivo_revision' => null,
            'motivo_revision_nota' => null,
        ]);

        $this->auditar($documento->salida, $usuario, 'quitó la marca «requiere NC» del documento', [
            'numero_control' => $documento->numeroLegible(),
            'dte_id' => $documento->dte_id,
        ]);
    }

    // ------------------------------------------------------------------ interno

    /**
     * Inserta la fila resolviendo la unicidad. Ver la nota de clase sobre las dos capas.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function crear(SalidaRuta $salida, array $atributos, ?User $usuario, bool $automatica): SalidaRutaDocumento
    {
        $numero = $atributos['numero_control'];

        if ($numero === '') {
            throw new \InvalidArgumentException('Un documento no se puede agregar sin número de control.');
        }

        $documento = DB::transaction(function () use ($salida, $atributos, $usuario, $automatica, $numero) {
            // Capa 1: mirar quién lo tiene, para poder decirlo. `lockForUpdate` evita que
            // dos altas simultáneas lean ambas "libre" antes de que ninguna haya escrito.
            $ocupante = SalidaRutaDocumento::vigentes()
                ->where('numero_control', $numero)
                ->lockForUpdate()
                ->first();

            if ($ocupante !== null) {
                throw new DocumentoYaAsignadoException(
                    $numero,
                    $ocupante->salida_ruta_id,
                    $ocupante->salida?->descripcionCorta(),
                );
            }

            return SalidaRutaDocumento::create($atributos + [
                'salida_ruta_id' => $salida->id,
                'asignado_at' => now(),
                'asignado_por' => $usuario?->id,
                'asignacion_automatica' => $automatica,
                'bloqueo_asignacion' => $this->bloqueoPara($salida),
            ]);
        });

        $this->auditar($salida, $usuario, $automatica
            ? 'asoció automáticamente el documento a la salida'
            : 'agregó el documento a la salida', [
                'numero_control' => $numero,
                'dte_id' => $atributos['dte_id'],
                'origen' => $atributos['origen'],
            ]);

        return $documento;
    }

    /**
     * Traduce la violación del índice único a la excepción de dominio. Se usa desde los
     * controladores, que son los que saben cómo contárselo al usuario.
     *
     * @template T
     *
     * @param  \Closure(): T  $accion
     * @return T
     */
    public function traduciendoChoques(\Closure $accion, string $numeroControl)
    {
        try {
            return $accion();
        } catch (QueryException $e) {
            // Capa 2: el índice `srd_documento_unico_vigente` (o el de "dos veces en la
            // misma salida") ganó la carrera. 23000 = integrity constraint violation.
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw new DocumentoYaAsignadoException($numeroControl);
            }

            throw $e;
        }
    }

    /** 1 mientras la salida está abierta; NULL si ya terminó. Ver la migración. */
    private function bloqueoPara(SalidaRuta $salida): ?int
    {
        return $salida->estado->esTerminal() ? null : 1;
    }

    /**
     * El documento tiene que EXISTIR ante Hacienda para poder viajar en una salida.
     *
     * La regla no se escribe acá: se pregunta a {@see VigenciaFiscalDte}, que es la misma
     * que aplica PPQ para decidir si un documento se puede cobrar. Dos módulos que
     * transportan y cobran el mismo papel no pueden tener dos ideas distintas de qué
     * documento es real.
     *
     * @throws DocumentoNoVigenteException
     */
    private function exigirDocumentoVigente(Dte $dte): void
    {
        $motivo = VigenciaFiscalDte::motivo($dte);

        if ($motivo !== null) {
            throw new DocumentoNoVigenteException($dte->numero_control, $motivo);
        }
    }

    private function exigirSalidaAbierta(SalidaRuta $salida): void
    {
        abort_unless(
            $salida->estado->esEditable(),
            403,
            'La lista de documentos de una salida finalizada o cancelada ya no se modifica.'
        );
    }

    /**
     * El papel puede llegar después de cerrar la salida, así que estas marcas siguen
     * disponibles en una salida finalizada. En una CANCELADA no: esa salida nunca
     * ocurrió y no puede tener hechos operativos nuevos.
     */
    private function exigirSalidaNoCancelada(SalidaRuta $salida): void
    {
        abort_if(
            $salida->estado === EstadoSalidaRuta::Cancelada,
            403,
            'La salida está cancelada: no se registran hechos sobre sus documentos.'
        );
    }

    /** @param array<string, mixed> $propiedades */
    private function auditar(SalidaRuta $salida, ?User $usuario, string $descripcion, array $propiedades): void
    {
        activity('salida_documento')
            ->performedOn($salida)
            ->causedBy($usuario)
            ->withProperties($propiedades)
            ->log($descripcion);
    }
}
