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
            '11' => 'Factura de exportación',
            '14' => 'Sujeto excluido',
            default => $this->tipo_documento ?: '—',
        };
    }
}
