<?php

use App\Http\Controllers\Planta\PlantaDashboardController;
use Illuminate\Support\Facades\Route;

/*
| Producción / Planta — área operativa AISLADA (nombre técnico «planta»,
| etiqueta visible «Producción»; ver config/planta.php).
|
| Fase 1: SOLO un dashboard informativo. NO emite DTE, no genera JSON, no firma,
| no transmite, no toca correlativos, no envía correo y no lee ni escribe una
| sola tabla: el controlador no consulta la base de datos. Nada de inventarios,
| órdenes de producción, recetas, lotes ni mermas todavía.
|
| Tres candados en orden, todos de BACKEND:
|   1. auth               -> invitado va al login.
|   2. modulo.planta      -> 404 si PLANTA_ENABLED=false (para TODOS los roles,
|                            incluido administrador).
|   3. permission:planta.ver -> 403 para jefatura, facturación y contabilidad,
|                            también escribiendo la URL a mano.
|
| El selector superior de áreas es solo presentación y NUNCA autoriza.
*/
Route::middleware(['auth', 'modulo.planta', 'permission:planta.ver'])
    ->prefix('planta')
    ->name('planta.')
    ->group(function () {
        Route::get('/', [PlantaDashboardController::class, 'index'])->name('dashboard');
    });
