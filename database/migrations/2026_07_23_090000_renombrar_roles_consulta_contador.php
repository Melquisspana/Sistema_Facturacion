<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Renombra los roles existentes al nuevo esquema formal:
 *   consulta  -> jefatura
 *   contador  -> contabilidad
 *
 * Es data-safe: `model_has_roles` referencia el rol por `role_id`, no por nombre,
 * así que los usuarios ya asignados conservan su rol tras el renombrado (solo
 * cambia la etiqueta, no la relación). Guardada: solo actúa si el nombre viejo
 * existe y el nuevo aún no, para poder correrse varias veces sin romper nada.
 *
 * No crea permisos ni toca asignaciones: de eso se encarga RolesSeeder (idempotente).
 */
return new class extends Migration
{
    /** @var array<string, string> viejo => nuevo */
    private array $renombres = [
        'consulta' => 'jefatura',
        'contador' => 'contabilidad',
    ];

    public function up(): void
    {
        foreach ($this->renombres as $viejo => $nuevo) {
            $this->renombrar($viejo, $nuevo);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach ($this->renombres as $viejo => $nuevo) {
            $this->renombrar($nuevo, $viejo);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function renombrar(string $desde, string $hacia): void
    {
        $existeDesde = DB::table('roles')->where('name', $desde)->where('guard_name', 'web')->exists();
        $existeHacia = DB::table('roles')->where('name', $hacia)->where('guard_name', 'web')->exists();

        // Solo renombra si el origen existe y el destino aún no: evita chocar con
        // una fila ya creada por el seeder y mantiene la operación idempotente.
        if ($existeDesde && ! $existeHacia) {
            DB::table('roles')
                ->where('name', $desde)
                ->where('guard_name', 'web')
                ->update(['name' => $hacia, 'updated_at' => now()]);
        }
    }
};
