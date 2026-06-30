<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Checkpoint de seguridad de secretos del DTE. SOLO LECTURA y SOLO BOOLEANOS:
 * nunca imprime contraseñas, tokens, certificados ni ningún valor secreto. No
 * transmite, no firma, no toca BD.
 */
class DteSeguridadCheckCommand extends Command
{
    protected $signature = 'dte:seguridad-check';

    protected $description = 'Diagnóstico de seguridad de secretos del DTE (no imprime ningún secreto)';

    public function handle(): int
    {
        $this->line('Checkpoint de seguridad DTE — NO se imprime ningún secreto.');
        $this->newLine();

        $recomendaciones = [];

        // --- Entorno ---
        $debug = (bool) config('app.debug');
        $this->estado('APP_DEBUG', $debug ? 'true' : 'false', ! $debug);
        if ($debug) {
            $recomendaciones[] = 'Poné APP_DEBUG=false en producción.';
        }

        // --- Firma (sin mostrar la contraseña) ---
        $this->estado('DTE_FIRMA_ENABLED', config('dte.firma.enabled') ? 'true' : 'false', true);
        $this->estado('DTE_CERT_PASSWORD',
            filled(config('dte.firma.cert_password')) ? 'configurada (oculta)' : 'no configurada', true);

        // --- Transmisión (sin mostrar token/contraseña) ---
        $transOn = (bool) config('dte.transmision.enabled');
        $this->estado('DTE_TRANSMISION_ENABLED', $transOn ? 'true' : 'false', ! $transOn);
        if ($transOn) {
            $recomendaciones[] = 'DTE_TRANSMISION_ENABLED está en true: la transmisión a Hacienda está habilitada.';
        }
        $this->estado('DTE_TRANSMISION_PASSWORD',
            filled(config('dte.transmision.password')) ? 'configurada (oculta)' : 'no configurada', true);
        $this->estado('DTE_TRANSMISION_TOKEN',
            filled(config('dte.transmision.token')) ? 'configurado (oculto)' : 'no configurado', true);

        $this->newLine();

        // --- .env bajo control de versiones ---
        $hayGit = is_dir(base_path('.git'));
        $gitignore = is_file(base_path('.gitignore')) ? (string) file_get_contents(base_path('.gitignore')) : '';
        $envIgnorado = $this->lineaPresente($gitignore, '.env');
        if (! $hayGit) {
            $this->estado('Repositorio Git', 'no inicializado (sin .git)', true);
            $this->line('       → No hay riesgo de secretos versionados todavía.');
        } else {
            $this->estado('.env ignorado por Git', $envIgnorado ? 'sí (regla en .gitignore)' : 'NO', $envIgnorado);
            if (! $envIgnorado) {
                $recomendaciones[] = 'Agregá .env a .gitignore antes de hacer commit.';
            }
        }

        // --- .gitignore protege firmador y material cripto ---
        $reglas = ['/resources/firmador/', '*.crt', '*.p12', '*.key', '*.pem'];
        $faltantes = [];
        foreach ($reglas as $regla) {
            if (! $this->lineaPresente($gitignore, $regla)) {
                $faltantes[] = $regla;
            }
        }
        $this->estado('Reglas .gitignore (firmador/cert/clave)',
            $faltantes === [] ? 'todas presentes' : 'faltan: '.implode(', ', $faltantes), $faltantes === []);
        if ($faltantes !== []) {
            $recomendaciones[] = 'Agregá a .gitignore: '.implode(', ', $faltantes);
        }

        // --- Conteo de certificados/llaves (sin mostrar rutas ni contenido) ---
        [$total, $protegidos] = $this->contarCertificados($gitignore);
        $todosProtegidos = $total === $protegidos;
        $this->estado('Certificados/llaves en el árbol',
            $total === 0 ? 'ninguno' : ($total.' archivo(s), '.($todosProtegidos ? 'todos cubiertos por .gitignore' : ($total - $protegidos).' SIN cubrir')),
            $todosProtegidos);
        if (! $todosProtegidos) {
            $recomendaciones[] = 'Hay material cripto fuera de las reglas de .gitignore: revisá las reglas *.crt/*.p12/*.key/*.pem.';
        }

        // --- Recomendaciones ---
        $this->newLine();
        if ($recomendaciones === []) {
            $this->info('Sin alertas de seguridad de secretos.');
        } else {
            $this->warn('Recomendaciones:');
            foreach ($recomendaciones as $r) {
                $this->line('  - '.$r);
            }
        }

        $this->newLine();
        $this->warn('*** DIAGNÓSTICO — NO SE IMPRIMIÓ NINGÚN SECRETO / NO SE FIRMÓ / NO SE TRANSMITIÓ ***');

        return self::SUCCESS;
    }

    private function estado(string $etiqueta, string $valor, bool $ok): void
    {
        $icono = $ok ? '<info>OK </info>' : '<error>!! </error>';
        $this->line('  '.$icono.str_pad($etiqueta, 34).' : '.$valor);
    }

    /** ¿Hay una línea (trim) que coincida con la regla en el .gitignore? */
    private function lineaPresente(string $contenido, string $regla): bool
    {
        foreach (preg_split('/\R/', $contenido) ?: [] as $linea) {
            if (trim($linea) === $regla) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cuenta archivos de certificado/llave y cuántos quedan cubiertos por una regla
     * del .gitignore. NO devuelve ni imprime rutas ni contenido.
     *
     * @return array{0: int, 1: int}  [total, protegidos]
     */
    private function contarCertificados(string $gitignore): array
    {
        $total = 0;
        $protegidos = 0;

        try {
            $finder = (new Finder())->files()->in(base_path())
                ->exclude(['vendor', 'node_modules', '.git'])
                ->name('/\.(crt|p12|key|pem)$/i');

            foreach ($finder as $file) {
                $total++;
                $rel = str_replace('\\', '/', mb_substr($file->getRealPath(), mb_strlen(base_path()) + 1));
                $ext = '*.'.strtolower($file->getExtension());
                if ($this->lineaPresente($gitignore, $ext) || $this->cubreCarpeta($gitignore, $rel)) {
                    $protegidos++;
                }
            }
        } catch (\Throwable $e) {
            // Si no se puede escanear, no exponemos detalles; queda en 0/0.
        }

        return [$total, $protegidos];
    }

    /** ¿Alguna regla de carpeta del .gitignore (p. ej. /resources/firmador/) cubre la ruta? */
    private function cubreCarpeta(string $gitignore, string $rel): bool
    {
        foreach (preg_split('/\R/', $gitignore) ?: [] as $linea) {
            $regla = trim($linea, " \t/");
            if ($regla !== '' && ! str_starts_with($regla, '*') && str_starts_with($rel, $regla.'/')) {
                return true;
            }
        }

        return false;
    }
}
