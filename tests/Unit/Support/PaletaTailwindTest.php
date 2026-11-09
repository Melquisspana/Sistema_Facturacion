<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Las clases `paper-*` e `ink-*` de las vistas y de los enums existen en la paleta (#194).
 *
 * Tailwind no genera una clase cuyo tono no está en `tailwind.config.js`, y en modo
 * oscuro ese texto queda con el color base sin avisar. Esta prueba falla si una vista
 * o un enum (que arma las clases de las etiquetas) usa un tono que la paleta no define.
 */
class PaletaTailwindTest extends TestCase
{
    public function test_las_vistas_solo_usan_tonos_que_existen_en_la_paleta(): void
    {
        $raiz = dirname(__DIR__, 3);
        $paleta = $this->tonosDeLaPaleta(file_get_contents($raiz.'/tailwind.config.js'));

        $this->assertNotEmpty($paleta['ink']);
        $this->assertNotEmpty($paleta['paper']);

        $faltantes = [];

        foreach (['/resources/views' => '.blade.php', '/app/Enums' => '.php'] as $carpeta => $extension) {
            $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz.$carpeta, \FilesystemIterator::SKIP_DOTS));

            foreach ($archivos as $archivo) {
                if (! str_ends_with($archivo->getFilename(), $extension)) {
                    continue;
                }

                foreach (file($archivo->getPathname()) as $numero => $linea) {
                    // Sin letra antes: no confunde `shrink-0` ni `link-0` con `ink-0`; sí toma `text-paper-200`.
                    preg_match_all('/(?<!\w)(ink|paper)-(\d+)/', $linea, $usos, PREG_SET_ORDER);

                    foreach ($usos as [, $color, $tono]) {
                        if (! in_array($tono, $paleta[$color], true)) {
                            $faltantes[] = str_replace($raiz.DIRECTORY_SEPARATOR, '', $archivo->getPathname()).':'.($numero + 1)." usa {$color}-{$tono}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $faltantes, "Tonos que no están en tailwind.config.js:\n".implode("\n", $faltantes));
    }

    /** @return array{ink: list<string>, paper: list<string>} */
    private function tonosDeLaPaleta(string $config): array
    {
        $paleta = ['ink' => [], 'paper' => []];

        foreach (array_keys($paleta) as $color) {
            if (preg_match('/\b'.$color.':\s*\{([^}]*)\}/', $config, $bloque)) {
                preg_match_all('/\b(\d+):/', $bloque[1], $tonos);
                $paleta[$color] = $tonos[1];
            }
        }

        return $paleta;
    }
}
