<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Documento (CCF/factura) recibido por correo, donde SOMOS EL RECEPTOR. Registro
 * local para no reprocesar duplicados y preparar el envío a contabilidad.
 * Fase 1: solo lectura/listado. No emite, no transmite, no reenvía.
 */
class DocumentoRecibido extends Model
{
    use HasFactory;

    public const ESTADOS = ['pendiente', 'enviado', 'ignorado'];

    /**
     * Por qué el documento tiene (o no) datos de DTE. Independiente de `estado`
     * (que es el triage manual pendiente/enviado/ignorado del usuario).
     *
     * - dte_valido: JSON de un DTE reconocido, con sus campos extraídos.
     * - no_es_dte: hay adjunto(s) pero no corresponden a un DTE (ni el nombre/asunto
     *   lo sugiere), p. ej. un estado de cuenta bancario o una orden de compra.
     * - json_invalido: había un adjunto .json pero no se pudo decodificar.
     * - tipo_no_soportado: el JSON sí es un DTE reconocible (tiene identificación),
     *   pero el tipo no tiene mapeo de total en este módulo todavía.
     * - falta_adjunto: hay PDF y el nombre/asunto sugiere fuertemente un DTE, pero
     *   el proveedor no adjuntó el JSON.
     */
    public const CLASIFICACIONES = ['dte_valido', 'no_es_dte', 'json_invalido', 'tipo_no_soportado', 'falta_adjunto'];

    /** Tipos DTE (CAT-002) con mapeo de total conocido en este módulo. */
    public const TIPOS_SOPORTADOS = ['01', '03', '05', '06', '07', '11', '14'];

    protected $table = 'documentos_recibidos';

    protected $fillable = [
        'gmail_message_id',
        'origen_email',
        'asunto',
        'remitente',
        'fecha_correo',
        'fecha_dte',
        'tipo_documento',
        'numero_control',
        'codigo_generacion',
        'sello_recepcion',
        'emisor_nombre',
        'emisor_nit',
        'emisor_nrc',
        'total',
        'tiene_pdf',
        'tiene_json',
        'estado',
        'clasificacion',
        'clasificacion_diagnostico',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'fecha_correo' => 'datetime',
            'fecha_dte' => 'date',
            'total' => 'decimal:2',
            'tiene_pdf' => 'boolean',
            'tiene_json' => 'boolean',
            'clasificacion_diagnostico' => 'array',
            'metadata_json' => 'array',
        ];
    }

    /** Etiqueta legible del tipo de documento (CAT-002) si se conoce. */
    public function tipoLabel(): string
    {
        return match ($this->tipo_documento) {
            '01' => 'Factura',
            '03' => 'CCF',
            '05' => 'Nota de crédito',
            '06' => 'Nota de débito',
            '07' => 'Comprobante de retención',
            '11' => 'Factura de exportación',
            '14' => 'Sujeto excluido',
            default => $this->tipo_documento ?: '—',
        };
    }

    /**
     * Etiqueta del campo `total` para este documento. El Comprobante de Retención
     * (07) no tiene "total a pagar": lo que se guarda ahí es el monto sujeto a
     * retención (resumen.totalSujetoRetencion del JSON oficial), así que la
     * columna necesita decirlo explícitamente para no leerse como un CCF normal.
     */
    public function totalLabel(): string
    {
        return $this->tipo_documento === '07' ? 'Monto sujeto a retención' : 'Total';
    }

    /** Etiqueta legible de la clasificación (motivo de datos faltantes). */
    public function clasificacionLabel(): string
    {
        return match ($this->clasificacion) {
            'dte_valido' => 'DTE válido',
            'no_es_dte' => 'No es DTE',
            'json_invalido' => 'JSON inválido',
            'tipo_no_soportado' => 'Tipo no soportado',
            'falta_adjunto' => 'Falta JSON',
            default => 'Sin clasificar',
        };
    }
}
