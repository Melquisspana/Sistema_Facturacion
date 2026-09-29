<?php

namespace App\Models\Gastos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un resumen preparado para una persona, una ventana y un canal.
 *
 * Es el registro de qué se armó y qué pasó con el envío. Se escribe ANTES de tocar
 * el transporte —outbox— para que un correo aceptado por el servidor no pueda
 * quedar sin rastro.
 *
 * `estado` distingue cuatro finales, y «simulado» es tan legítimo como «enviado»:
 * fuera de producción el sistema NO manda correo, y decirlo explícitamente evita la
 * lectura peligrosa de «no hay error, entonces llegó».
 *
 * Un resumen SIN pendientes no se guarda ni se manda. No existe el estado «vacío»
 * porque no existe la fila: mandar un correo para decir que no hay nada que pagar
 * es la forma más rápida de que la gente deje de leer estos correos.
 */
class Resumen extends Model
{
    protected $table = 'gastos_resumenes';

    public $timestamps = false;

    protected $guarded = ['id'];

    public const ESTADOS = [
        'preparado' => 'Preparado, sin enviar',
        'simulado' => 'Simulado (no salió del sistema)',
        'enviado' => 'Enviado',
        'fallido' => 'Falló el envío',
    ];

    protected function casts(): array
    {
        return ['contenido' => 'array', 'enviado_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
