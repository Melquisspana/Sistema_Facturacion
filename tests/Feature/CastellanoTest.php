<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * El sistema habla castellano también en lo que escribe Laravel por su cuenta
 * (docs/GUIA_PANTALLAS.md, «Idioma y trato»).
 *
 * Falla si vuelve el inglés: si el idioma por defecto deja de ser `es`, si una
 * versión nueva de Laravel trae un mensaje de validación que `lang/es` no tiene, o
 * si una vista usa `__('...')` con un texto que no está traducido.
 */
class CastellanoTest extends TestCase
{
    public function test_el_idioma_de_la_aplicacion_es_castellano(): void
    {
        $this->assertSame('es', config('app.locale'));
        $this->assertSame('es', app()->getLocale());
    }

    public function test_los_mensajes_de_laravel_tienen_su_version_en_castellano(): void
    {
        $origen = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');
        $faltantes = [];

        foreach (['auth', 'pagination', 'passwords', 'validation'] as $archivo) {
            foreach ($this->claves(require "{$origen}/{$archivo}.php") as $clave) {
                if (! Lang::has("{$archivo}.{$clave}", 'es', false)) {
                    $faltantes[] = "{$archivo}.{$clave}";
                }
            }
        }

        $this->assertSame([], $faltantes, "Faltan en lang/es:\n".implode("\n", $faltantes));
    }

    public function test_los_textos_traducibles_de_las_vistas_estan_en_castellano(): void
    {
        $vistas = [
            resource_path('views'),
            base_path('vendor/laravel/framework/src/Illuminate/Pagination/resources/views'),
        ];
        $faltantes = [];

        foreach ($vistas as $carpeta) {
            $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($carpeta, \FilesystemIterator::SKIP_DOTS));

            foreach ($archivos as $archivo) {
                if (! str_ends_with($archivo->getFilename(), '.blade.php')) {
                    continue;
                }

                preg_match_all('/__\(\s*([\'"])(.+?)(?<!\\\\)\1/', file_get_contents($archivo->getPathname()), $usos);

                foreach ($usos[2] as $texto) {
                    if (! Lang::has(stripslashes($texto), 'es', false)) {
                        $faltantes[] = $archivo->getFilename().': '.$texto;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($faltantes)), "Textos sin traducir en lang/es:\n".implode("\n", array_unique($faltantes)));
    }

    public function test_la_paginacion_sale_en_castellano(): void
    {
        $html = (new LengthAwarePaginator(range(1, 15), 40, 15, 1, ['path' => '/lista']))->links()->toHtml();

        $this->assertStringContainsString('Mostrando', $html);
        $this->assertStringContainsString('resultados', $html);
        $this->assertStringContainsString('Siguiente', $html);
        $this->assertStringNotContainsString('Showing', $html);
        $this->assertStringNotContainsString('Next', $html);
    }

    /** @return list<string> claves con punto para los arreglos anidados */
    private function claves(array $mensajes, string $prefijo = ''): array
    {
        $claves = [];

        foreach ($mensajes as $clave => $valor) {
            if ($clave === 'custom' || $clave === 'attributes') {
                continue;
            }

            if (is_array($valor)) {
                array_push($claves, ...$this->claves($valor, "{$prefijo}{$clave}."));
            } else {
                $claves[] = $prefijo.$clave;
            }
        }

        return $claves;
    }
}
