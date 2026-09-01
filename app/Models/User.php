<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'activo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    /**
     * Auditoría: registra name/email/activo. NUNCA el password.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('usuario')
            ->setDescriptionForEvent(fn (string $evento) => match ($evento) {
                'created' => 'creó el usuario',
                'updated' => 'actualizó el usuario',
                'deleted' => 'eliminó el usuario',
                default => $evento,
            });
    }

    /**
     * La persona de campo que corresponde a este usuario, si alguien las enlazó.
     *
     * Reemplaza a `salidasRuta()`, que asumía que «quien sale a ruta ya es usuario del
     * sistema». Resultó falso: los vendedores no tienen login ni deben tenerlo, así que el
     * catálogo de quienes salen pasó a ser {@see PersonalRuta} y las salidas de un usuario
     * se consultan a través de él —cuando existe—.
     */
    public function personalRuta(): HasOne
    {
        return $this->hasOne(PersonalRuta::class, 'user_id');
    }
}
