<?php

namespace App\Models\Gastos;

use App\Models\User;
use App\Support\Correo\CandadoCorreoReal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué avisos quiere recibir una persona, y por dónde.
 *
 * Todo acá es PREFERENCIA, no regla del sistema: los 7/3/0 días y el resumen
 * semanal son la propuesta inicial y se editan. Lo que no se negocia desde esta
 * tabla es el permiso: marcar «personal» sin `gastos.personales` no agrega ni una
 * fila, porque el alcance se vuelve a recortar al armar los avisos.
 *
 * `correo` apagado por defecto. Encenderlo no manda correo real fuera de
 * producción: sigue mandando el candado de {@see CandadoCorreoReal}.
 */
class PreferenciaAvisos extends Model
{
    protected $table = 'gastos_preferencias_avisos';

    protected $guarded = ['id'];

    public const RESUMENES = [
        'diario' => 'Todos los días',
        'semanal' => 'Una vez por semana',
        'nunca' => 'No enviar resumen',
    ];

    /** Lo que recibe quien nunca tocó esta pantalla. */
    public const PREDETERMINADAS = [
        'activo' => true,
        'dias_anticipacion' => [7, 3, 0],
        'correo' => false,
        'resumen' => 'semanal',
        'resumen_dia_semana' => 1,
        'ambitos' => ['empresarial'],
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'correo' => 'boolean', 'dias_anticipacion' => 'array', 'ambitos' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** Las preferencias de alguien, sin obligarlo a haberlas guardado nunca. */
    public static function de(User $usuario): self
    {
        return static::firstOrNew(
            ['usuario_id' => $usuario->id],
            self::PREDETERMINADAS,
        );
    }
}
