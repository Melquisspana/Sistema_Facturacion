<?php

namespace App\Console\Commands;

use App\Ajustes\Ajustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\DefinicionAjuste;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\EstadoAjuste;
use App\Ajustes\Rotacion\RotacionAppKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Fotografía de SOLO LECTURA del Centro de Configuración: qué está configurado,
 * de dónde sale cada valor y qué falta por migrar.
 *
 * PARA QUÉ SIRVE
 * ------------------------------------------------------------------
 * 1. Antes y después de migrar en producción: se corre, se guarda la salida, se
 *    migra, se vuelve a correr y se comparan. Si una clave cambió de valor —y no
 *    solo de FUENTE— la migración hizo algo que no debía.
 * 2. Antes de rotar APP_KEY: dice cuántos secretos hay en juego.
 * 3. En una instalación nueva: responde "¿qué me falta configurar?" sin tener que
 *    abrir seis pantallas.
 *
 * NUNCA IMPRIME VALORES DE SECRETOS. De ellos dice si están y de dónde salen, que
 * es exactamente lo que publica {@see EstadoAjuste}. Los valores no
 * secretos SÍ se imprimen: son configuración de la empresa y el objetivo del
 * comando es poder compararlos antes y después de una migración.
 *
 * NO ESCRIBE NADA. Ni en la base, ni en la caché, ni en el .env.
 */
class AjustesEstadoCommand extends Command
{
    protected $signature = 'ajustes:estado
        {--seccion= : Muestra solo una sección del catálogo.}
        {--pendientes : Muestra solo lo que falta configurar.}';

    protected $description = 'Muestra qué está configurado y de dónde sale cada valor (solo lectura).';

    public function handle(CatalogoAjustes $catalogo, Ajustes $ajustes, RotacionAppKey $rotacion): int
    {
        $this->migracionesPendientes();
        $this->transicionLegacy($catalogo);

        $seccion = (string) $this->option('seccion');
        $soloPendientes = (bool) $this->option('pendientes');

        $filas = [];
        $porSeccion = [];

        foreach ($catalogo->todos() as $clave => $definicion) {
            if ($seccion !== '' && $definicion->seccion !== $seccion) {
                continue;
            }

            $estado = $ajustes->estadoParaPantalla($clave);

            if ($soloPendientes && $estado->configurado) {
                continue;
            }

            $porSeccion[$definicion->seccion][] = [
                $clave,
                $estado->configurado ? 'sí' : 'NO',
                $this->fuente($estado->fuente),
                $this->valor($definicion, $estado->valor),
            ];
        }

        foreach ($porSeccion as $nombre => $filasSeccion) {
            $this->newLine();
            $this->comment(strtoupper($nombre));
            $this->table(['Clave', 'Configurado', 'Fuente', 'Valor'], $filasSeccion);
            $filas = array_merge($filas, $filasSeccion);
        }

        $this->newLine();
        $this->line('Ajustes mostrados: '.count($filas));
        $this->line('Secretos cifrados en la base: '.count($rotacion->afectados()));

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- bloques

    /**
     * Migraciones sin aplicar. Es lo primero que hay que mirar en un despliegue:
     * el Centro de Configuración depende de dos tablas, y si faltan, media
     * pantalla resuelve por fallback sin decirlo.
     */
    private function migracionesPendientes(): void
    {
        foreach (['ajustes_sistema' => 'ajustes', 'verificaciones_configuracion' => 'verificaciones'] as $tabla => $que) {
            $existe = $this->intentar(fn () => Schema::hasTable($tabla), false);

            $existe
                ? $this->line('Tabla '.$tabla.': presente')
                : $this->error('Tabla '.$tabla.' AUSENTE — falta correr las migraciones ('.$que.').');
        }
    }

    /**
     * Claves que todavía viven en la tabla anterior.
     *
     * Mientras esta lista no esté vacía, la mudanza de datos no se corrió (o se
     * revirtió) y las lecturas de transición siguen haciendo falta.
     */
    private function transicionLegacy(CatalogoAjustes $catalogo): void
    {
        if (! $this->intentar(fn () => Schema::hasTable('configuraciones'), false)) {
            return;
        }

        $enTransicion = [];

        foreach ($catalogo->todos() as $definicion) {
            if ($definicion->claveLegacy === null) {
                continue;
            }

            $tiene = $this->intentar(
                fn () => DB::table('configuraciones')->where('clave', $definicion->claveLegacy)->exists(),
                false,
            );

            if ($tiene) {
                $enTransicion[] = $definicion->claveLegacy;
            }
        }

        $this->newLine();

        if ($enTransicion === []) {
            $this->info('Mudanza de datos COMPLETA: ninguna clave del catálogo queda en la tabla anterior.');

            return;
        }

        $this->warn('Todavía en la tabla anterior ('.count($enTransicion).'): '.implode(', ', $enTransicion));
        $this->line('Se leen por la vía de transición. Corré la migración de datos para terminar la mudanza.');
    }

    // ---------------------------------------------------------------- formato

    private function fuente(FuenteAjuste $fuente): string
    {
        return match ($fuente) {
            FuenteAjuste::BaseDeDatos => 'base de datos',
            FuenteAjuste::BaseDeDatosLegacy => 'TABLA ANTERIOR',
            FuenteAjuste::Configuracion => '.env / config',
            FuenteAjuste::Defecto => 'defecto',
            FuenteAjuste::NoConfigurado => '—',
        };
    }

    /**
     * Valor imprimible.
     *
     * De un secreto NUNCA sale el contenido: el DTO de pantalla ya lo entrega en
     * null y acá se rotula. Los textos largos (la plantilla del correo) se
     * recortan: el comando es para comparar de un vistazo, no para volcar datos.
     */
    private function valor(DefinicionAjuste $definicion, mixed $valor): string
    {
        if ($definicion->tipo->esSecreto()) {
            return '(secreto)';
        }

        $texto = match (true) {
            $valor === null => '—',
            is_bool($valor) => $valor ? 'sí' : 'no',
            is_array($valor) => implode(', ', $valor),
            default => (string) $valor,
        };

        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        return mb_strlen($texto) > 60 ? mb_substr($texto, 0, 57).'...' : $texto;
    }

    /**
     * Consulta tolerante al fallo: este comando es de diagnóstico y se corre
     * justamente cuando algo puede estar a medias. Caerse por una tabla que no
     * existe dejaría al operador sin ver el resto del informe.
     */
    private function intentar(callable $consulta, mixed $porDefecto): mixed
    {
        try {
            return $consulta();
        } catch (Throwable) {
            return $porDefecto;
        }
    }
}
