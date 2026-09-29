<?php

namespace App\Models\Gastos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un aviso de la bandeja interna: «esto vence pronto», «esto ya venció», «esto
 * sigue sin monto».
 *
 * Se DERIVA del saldo real cuando se arman los avisos; nadie lo mantiene a mano. Un
 * aviso no es una deuda ni cambia una: borrarlo o marcarlo leído no altera ningún
 * saldo, y una obligación que se paga simplemente deja de generar avisos nuevos —el
 * viejo se queda como historia, que es lo que se acordó.
 *
 * `clave` es usuario + tipo + cuota + ventana. Es lo que hace que correr el proceso
 * cinco veces el mismo día deje un aviso y no cinco.
 */
class Aviso extends Model
{
    protected $table = 'gastos_avisos';

    public $timestamps = false;

    protected $guarded = ['id'];

    public const TIPOS = [
        'vence' => 'Vence pronto',
        'vencido' => 'Vencido',
        'falta_monto' => 'Falta el monto',
    ];

    protected function casts(): array
    {
        return ['vence' => 'date', 'importe' => 'decimal:2', 'leido_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function gasto(): BelongsTo
    {
        return $this->belongsTo(Gasto::class, 'gasto_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * Cuántos avisos SIN LEER tiene esta persona, por tipo.
     *
     * Alimenta la franja de «Por pagar». Devuelve solo cifras: quién puede ver qué ya
     * se decidió al ARMAR los avisos, cruzando la preferencia de cada quien con su
     * permiso, así que acá no hay nada más que recortar.
     *
     * @return array<string, int>
     */
    public static function resumenSinLeer(User $usuario): array
    {
        return static::query()
            ->where('usuario_id', $usuario->id)
            ->whereNull('leido_at')
            ->selectRaw('tipo, COUNT(*) as cuantos')
            ->groupBy('tipo')
            ->pluck('cuantos', 'tipo')
            ->all();
    }

    public function leido(): bool
    {
        return $this->leido_at !== null;
    }
}
