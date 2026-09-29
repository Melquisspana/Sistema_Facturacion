<?php

namespace App\Models\Gastos;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Una obligación: qué debemos, a quién y para cuándo.
 *
 * `importe` puede ser NULL —«todavía no conozco el monto»—. Eso NO es cero: un
 * gasto sin importe no suma a pendiente, no suma a vencido y no se puede pagar
 * hasta completarlo.
 */
class Gasto extends Model
{
    protected $guarded = ['id'];

    public const AMBITOS = ['empresarial' => 'De la empresa', 'personal' => 'Personal'];

    public const NATURALEZAS = [
        'operativo' => 'Gasto habitual o extraordinario',
        'compra' => 'Compra de materiales, productos o equipo',
        'tributo' => 'Pago de impuestos',
        'retiro' => 'Retiro de propietario',
    ];

    /**
     * Qué tan firme es el importe. NULL —lo normal— significa «sin marca».
     *
     * Son TRES estados y no dos, porque «lo confirmó una persona» y «lo respalda un
     * papel» no son lo mismo y confundirlos afirma de más:
     *
     *   provisional          acordado de palabra, todavía por cerrar.
     *   confirmado_usuario   una persona dio la cifra por buena. No hay documento, y
     *                        la etiqueta no insinúa que lo haya.
     *   confirmado           hay un documento detrás —un estado de cuenta, una
     *                        factura— que se puede volver a leer.
     *
     * El caso que obligó a separarlos: el saldo de Proveedor A lo confirmó el usuario, pero
     * marcarlo `confirmado` habría hecho decir a la pantalla «Confirmado con
     * documento», que es sencillamente falso.
     *
     * Ninguno cambia un cálculo. Los tres se deben, vencen y se pagan igual; lo único
     * que cambia es lo que la pantalla puede afirmar.
     */
    public const CERTEZAS = [
        'provisional' => 'Saldo provisional',
        'confirmado_usuario' => 'Confirmado por el usuario',
        'confirmado' => 'Confirmado con documento',
    ];

    public const DOCUMENTACION = [
        'pendiente' => 'Falta adjuntar',
        'adjunto' => 'Adjunto',
        'no_entregaron' => 'No entregaron documento',
    ];

    protected function casts(): array
    {
        return ['importe' => 'decimal:2', 'periodo_desde' => 'date', 'periodo_hasta' => 'date'];
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(Cuota::class)->orderBy('numero');
    }

    public function ajustes(): HasManyThrough
    {
        return $this->hasManyThrough(Ajuste::class, Cuota::class, 'gasto_id', 'cuota_id');
    }

    public function fuentes(): HasMany
    {
        return $this->hasMany(Fuente::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function esPersonal(): bool
    {
        return $this->ambito === 'personal';
    }

    public function montoDesconocido(): bool
    {
        return $this->importe === null;
    }

    public function esProvisional(): bool
    {
        return $this->certeza === 'provisional';
    }

    /**
     * ¿Lo confirmó una persona, sin documento detrás?
     *
     * Existe para que la pantalla pueda decir eso y solo eso. Sin este método, la
     * única forma de mostrar la diferencia sería comparar contra la cadena
     * `'confirmado'` en cada vista, y la primera que se olvidara diría «con
     * documento» sobre algo que no lo tiene.
     */
    public function confirmadoPorElUsuario(): bool
    {
        return $this->certeza === 'confirmado_usuario';
    }

    public function etiquetaCerteza(): ?string
    {
        return self::CERTEZAS[$this->certeza] ?? null;
    }

    public function etiquetaAmbito(): string
    {
        return self::AMBITOS[$this->ambito] ?? $this->ambito;
    }
}
