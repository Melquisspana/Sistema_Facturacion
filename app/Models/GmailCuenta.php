<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta de Gmail conectada (OAuth2) para PPQ. Los tokens se guardan CIFRADOS
 * (cast 'encrypted'); nunca se exponen en logs ni en respuestas.
 */
class GmailCuenta extends Model
{
    protected $table = 'gmail_cuentas';

    protected $fillable = [
        'email',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'conectado_por',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /** La cuenta conectada (se asume una sola); null si no hay ninguna. */
    public static function actual(): ?self
    {
        return static::query()->latest('id')->first();
    }

    public function conectada(): bool
    {
        return filled($this->refresh_token) || filled($this->access_token);
    }

    /**
     * Google revocó/expiró el token (invalid_grant, 401, 403): limpia el
     * access_token y el refresh_token para que conectada() refleje que hay
     * que reautorizar. Conserva email/conectado_por como referencia histórica.
     */
    public function marcarDesconectada(): void
    {
        $this->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
        ])->save();
    }
}
