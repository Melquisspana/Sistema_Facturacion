<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $departamentoId = DB::table('departamentos')
                ->where('codigo', '06')
                ->value('id');

            if (! $departamentoId) {
                return;
            }

            $objetivos = [
                'San Marcos' => [
                    'municipio_codigo' => '24',
                    'distrito_codigo' => '12',
                ],
                'Santiago Texacuangos' => [
                    'municipio_codigo' => '24',
                    'distrito_codigo' => '15',
                ],
                'Santo Tomás' => [
                    'municipio_codigo' => '24',
                    'distrito_codigo' => '16',
                ],
            ];

            $municipioIds = [];

            foreach ($objetivos as $nombre => $datos) {
                DB::table('distritos')
                    ->where('departamento_id', $departamentoId)
                    ->where('nombre', $nombre)
                    ->update([
                        'municipio' => 'San Salvador Sur',
                        'municipio_codigo' => $datos['municipio_codigo'],
                        'codigo' => $datos['distrito_codigo'],
                        'updated_at' => now(),
                    ]);

                $municipio = DB::table('municipios')
                    ->where('departamento_id', $departamentoId)
                    ->where('nombre', $nombre)
                    ->first();

                if ($municipio) {
                    DB::table('municipios')
                        ->where('id', $municipio->id)
                        ->update([
                            'codigo' => $datos['municipio_codigo'],
                            'activo' => 1,
                            'updated_at' => now(),
                        ]);

                    $municipioIds[$nombre] = $municipio->id;
                } else {
                    $municipioIds[$nombre] = DB::table('municipios')->insertGetId([
                        'departamento_id' => $departamentoId,
                        'codigo' => $datos['municipio_codigo'],
                        'nombre' => $nombre,
                        'activo' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $distritoSanMarcosId = DB::table('distritos')
                ->where('departamento_id', $departamentoId)
                ->where('nombre', 'San Marcos')
                ->value('id');

            $distritoSantoTomasId = DB::table('distritos')
                ->where('departamento_id', $departamentoId)
                ->where('nombre', 'Santo Tomás')
                ->value('id');

            if (
                isset($municipioIds['San Marcos']) &&
                $distritoSanMarcosId
            ) {
                DB::table('cliente_sucursales')
                    ->where('departamento_id', $departamentoId)
                    ->whereRaw("LOWER(nombre) LIKE '%san marcos%'")
                    ->update([
                        'municipio_id' => $municipioIds['San Marcos'],
                        'distrito_id' => $distritoSanMarcosId,
                        'updated_at' => now(),
                    ]);
            }

            if (
                isset($municipioIds['Santo Tomás']) &&
                $distritoSantoTomasId
            ) {
                DB::table('cliente_sucursales')
                    ->where('departamento_id', $departamentoId)
                    ->whereRaw("LOWER(nombre) LIKE '%santo tom%'")
                    ->update([
                        'municipio_id' => $municipioIds['Santo Tomás'],
                        'distrito_id' => $distritoSantoTomasId,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // No se revierte intencionalmente:
        // esta migración corrige datos fiscales/territoriales incorrectos.
    }
};