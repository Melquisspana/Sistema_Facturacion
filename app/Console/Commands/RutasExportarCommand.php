<?php

namespace App\Console\Commands;

use App\Models\PersonalRuta;
use App\Models\Ruta;
use App\Models\RutaCobertura;
use Illuminate\Console\Command;

/**
 * Guarda en un JSON las rutas tal como están armadas: frecuencia, lugares que cubren,
 * salas y vendedores. Es la mitad de un traslado: se arman en una copia, se exportan y
 * se cargan en producción con `rutas:importar` sin volver a armarlas a mano.
 *
 * Solo lee. Los lugares van por NOMBRE (departamento y distrito) y las salas por id y
 * nombre, para que la importación pueda comprobar que habla de lo mismo.
 */
class RutasExportarCommand extends Command
{
    protected $signature = 'rutas:exportar {archivo=storage/app/rutas-exportadas.json : Dónde guardar el JSON}';

    protected $description = 'Exporta las rutas (frecuencia, lugares, salas y vendedores) a un JSON para importarlas en otro servidor';

    public function handle(): int
    {
        $rutas = Ruta::query()
            ->with(['coberturas.departamento:id,nombre', 'coberturas.distrito.departamento:id,nombre', 'sucursales:id,ruta_id,nombre,codigo'])
            ->orderBy('nombre')
            ->get();

        $datos = [
            'version' => 1,
            'exportado' => now()->toIso8601String(),
            'rutas' => $rutas->map(fn (Ruta $r) => [
                'nombre' => $r->nombre,
                'activa' => (bool) $r->activa,
                'frecuencia_objetivo_dias' => $r->frecuencia_objetivo_dias,
                'coberturas' => $r->coberturas->map(fn (RutaCobertura $c) => $c->esDepartamento()
                    ? ['departamento' => $c->departamento?->nombre]
                    : ['distrito' => $c->distrito?->nombre, 'departamento' => $c->distrito?->departamento?->nombre])->values()->all(),
                'salas' => $r->sucursales->sortBy('nombre')->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre, 'codigo' => $s->codigo])->values()->all(),
            ])->values()->all(),
            'vendedores' => PersonalRuta::with('funciones')->orderBy('nombre')->get()->map(fn (PersonalRuta $p) => [
                'nombre' => $p->nombre,
                'telefono' => $p->telefono,
                'activo' => (bool) $p->activo,
                'funciones' => $p->funciones->pluck('funcion')->map(fn ($f) => $f->value)->all(),
            ])->values()->all(),
        ];

        $ruta = $this->argument('archivo');
        $destino = str_starts_with($ruta, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $ruta) ? $ruta : base_path($ruta);
        file_put_contents($destino, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $salas = collect($datos['rutas'])->sum(fn ($r) => count($r['salas']));
        $this->info(count($datos['rutas']).' rutas, '.$salas.' salas y '.count($datos['vendedores']).' vendedores exportados a '.$destino);

        return self::SUCCESS;
    }
}
