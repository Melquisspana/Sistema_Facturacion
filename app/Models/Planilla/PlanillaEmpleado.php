<?php

namespace App\Models\Planilla;

use App\Enums\ModuloSistema;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * Una persona en planilla.
 *
 * Tabla propia con TRES punteros opcionales de identidad —Asistencia, Personal de
 * Rutas y usuario del sistema— para no volver a escribir a nadie y para que se sepa
 * que el Hugo de acá es el de allá. Ninguno es una dependencia: la planilla funciona
 * con los tres en NULL, nunca lee marcaciones ni huellas, y **no exige biometría**.
 *
 * Ver la migración para el porqué de no colgarse de `asistencia_empleados`.
 */
class PlanillaEmpleado extends Model
{
    public const DOCUMENTO_TIPOS = ['dui' => 'DUI', 'pasaporte' => 'Pasaporte', 'carne_residente' => 'Carné de residente'];

    protected $table = 'planilla_empleados';

    protected $guarded = ['id'];

    /**
     * ¿Las personas se cargan en Personal? Siempre que la tabla esté instalada, encendida
     * o no Planilla (08/11/2026: «Personal siempre tiene que funcionar»): cada persona se
     * da de alta una sola vez ahí, y Usuarios, Asistencia y Rutas la eligen o llevan a su
     * ficha en vez de volver a escribirla.
     *
     * Lo que es de planilla de verdad (generar y pagar, sueldos, vacaciones, indemnización)
     * sigue con su interruptor: {@see ModuloSistema::Planilla}.
     *
     * Una sola consulta por petición: la piden el menú y varias pantallas a la vez.
     */
    public static function enUso(): bool
    {
        return once(fn () => Schema::hasTable('planilla_empleados'));
    }

    protected function casts(): array
    {
        return [
            'activo' => 'boolean', 'salario_referencia' => 'decimal:2', 'con_descuentos_ley' => 'boolean',
            'alta_el' => 'date', 'vacaciones_pagadas_hasta' => 'integer', 'fecha_nacimiento' => 'date', 'dui_expedido_el' => 'date', 'dependientes' => 'array',
        ];
    }

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(AsistenciaEmpleado::class, 'asistencia_empleado_id');
    }

    public function personalRuta(): BelongsTo
    {
        return $this->belongsTo(PersonalRuta::class, 'personal_ruta_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PlanillaDetalle::class, 'planilla_empleado_id');
    }

    /** ¿De dónde salió esta identidad? Solo para mostrarlo; no cambia ningún permiso. */
    public function origenIdentidad(): string
    {
        return match (true) {
            $this->asistencia_empleado_id !== null => 'Asistencia',
            $this->personal_ruta_id !== null => 'Personal de Rutas',
            $this->user_id !== null => 'Usuario del sistema',
            default => 'Solo planilla',
        };
    }
}
