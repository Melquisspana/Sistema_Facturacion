<?php

namespace App\Console\Commands;

use App\Ajustes\Rotacion\InformeRotacion;
use App\Ajustes\Rotacion\RotacionAppKey;
use App\Ajustes\Rotacion\RotacionImposibleException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Re-cifra con una APP_KEY nueva todo lo que la aplicación guarda cifrado: los
 * secretos de `ajustes_sistema` y los tokens OAuth de `gmail_cuentas`.
 *
 * MODO POR DEFECTO: SIMULACIÓN. Sin `--ejecutar` no escribe nada; hay que pedir
 * la escritura a propósito y confirmarla con una frase. Al revés —ejecutar por
 * defecto y tener que acordarse de `--dry-run`— el error de teclado destruye
 * secretos.
 *
 * DE DÓNDE SALE LA CLAVE NUEVA. De la variable de entorno `APP_KEY_NUEVA`, o de
 * `--nueva-key=` si se pasa. La variable es la vía recomendada: un argumento de
 * consola queda en el historial del shell y en la lista de procesos de la
 * máquina, que son dos sitios más de los que hacen falta para una clave de
 * cifrado.
 *
 * ESTE COMANDO NO TOCA EL .env, y no es una limitación: escribir el archivo desde
 * aquí significaría que un fallo a mitad deja la aplicación con una clave que ya
 * no corresponde a sus datos. Al terminar imprime los pasos que faltan para que
 * los haga una persona, con el sistema detenido.
 *
 * Nunca imprime claves, valores descifrados ni criptogramas.
 *
 * Ver docs/ROTACION_APP_KEY.md.
 */
class AjustesRotarAppKeyCommand extends Command
{
    /** Frase que hay que escribir para que la rotación escriba de verdad. */
    private const FRASE = 'ROTAR CLAVE DE CIFRADO';

    protected $signature = 'ajustes:rotar-app-key
        {--nueva-key= : Clave nueva (base64:...). Preferí la variable de entorno APP_KEY_NUEVA.}
        {--ejecutar : Escribe los valores re-cifrados. Sin esta opción solo simula.}
        {--force : Omite la frase de confirmación. Solo para entornos automatizados.}';

    protected $description = 'Re-cifra los secretos de configuración con una APP_KEY nueva (simula por defecto).';

    public function handle(RotacionAppKey $rotacion): int
    {
        $afectados = $rotacion->afectados();

        $this->line('Secretos cifrados en la base de datos: '.count($afectados));

        if ($afectados === []) {
            $this->info('No hay ningún secreto cifrado: cambiar APP_KEY no le hace perder nada a la aplicación.');

            return self::SUCCESS;
        }

        foreach ($afectados as $etiqueta) {
            $this->line('  · '.$etiqueta);
        }

        try {
            $claveNueva = $this->claveNueva();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->option('ejecutar')
            ? $this->rotar($rotacion, $claveNueva)
            : $this->simular($rotacion, $claveNueva);
    }

    // --------------------------------------------------------------- modos

    private function simular(RotacionAppKey $rotacion, string $claveNueva): int
    {
        $this->newLine();
        $this->comment('SIMULACIÓN — no se escribió nada.');

        $informe = $rotacion->analizar($claveNueva);
        $this->informar($informe);

        if (! $informe->puedeAplicarse()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('La rotación se puede aplicar. Para hacerla de verdad: --ejecutar');
        $this->pasosManuales();

        return self::SUCCESS;
    }

    private function rotar(RotacionAppKey $rotacion, string $claveNueva): int
    {
        $this->newLine();
        $this->warn('Vas a RE-CIFRAR los secretos de configuración con una clave nueva.');
        $this->warn('Hacé el respaldo ANTES (ver docs/ROTACION_APP_KEY.md) y anotá la APP_KEY actual.');

        if (! $this->confirmado()) {
            $this->error('Confirmación incorrecta: no se escribió nada.');

            return self::FAILURE;
        }

        try {
            $informe = $rotacion->ejecutar($claveNueva);
        } catch (RotacionImposibleException $e) {
            $this->error($e->getMessage());
            $this->informar($e->informe);

            return self::FAILURE;
        }

        $this->informar($informe);
        $this->newLine();
        $this->info('Secretos re-cifrados: '.count($informe->legibles).'.');
        $this->pasosManuales();

        return self::SUCCESS;
    }

    // ------------------------------------------------------------- ayudas

    private function claveNueva(): string
    {
        // El argumento gana sobre la variable para poder usarlo en un entorno
        // controlado, pero la ayuda del comando empuja hacia la variable.
        $clave = (string) ($this->option('nueva-key') ?: env('APP_KEY_NUEVA', ''));

        if (trim($clave) === '') {
            throw new InvalidArgumentException(
                'Falta la clave nueva. Definí APP_KEY_NUEVA en el entorno o pasá --nueva-key. '
                .'Generala con: php artisan key:generate --show'
            );
        }

        // Valida longitud y cifrado antes de tocar una sola fila. El mensaje de
        // error nunca incluye la clave.
        RotacionAppKey::normalizar($clave);

        return $clave;
    }

    private function confirmado(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return $this->ask('Escribí «'.self::FRASE.'» para continuar') === self::FRASE;
    }

    private function informar(InformeRotacion $informe): void
    {
        $this->newLine();
        $this->line('Se descifran con la clave actual : '.count($informe->legibles));
        $this->line('NO se descifran                  : '.count($informe->ilegibles));
        $this->line('No verifican con la clave nueva  : '.count($informe->noVerificados));

        foreach ($informe->ilegibles as $clave) {
            $this->error('  ✗ '.$clave.' — no se puede descifrar con la APP_KEY actual.');
        }

        foreach ($informe->noVerificados as $clave) {
            $this->error('  ✗ '.$clave.' — se re-cifró pero no volvió a leerse igual.');
        }

        if (! $informe->puedeAplicarse()) {
            $this->newLine();
            $this->error('NO se puede rotar sin perder datos. No se escribió nada.');
            $this->line('Revisá si la APP_KEY actual es la que cifró esos valores, o restaurá el respaldo.');
        }
    }

    /** Lo que falta y NO hace este comando. */
    private function pasosManuales(): void
    {
        $this->newLine();
        $this->comment('Pasos que este comando NO hace y tenés que hacer vos:');
        $this->line('  1. Poner APP_KEY=<la clave nueva> en el .env del servidor.');
        $this->line('  2. php artisan config:clear');
        $this->line('  3. Reiniciar el worker de colas y el programador.');
        $this->line('  4. Comprobar que cada secreto sigue funcionando (probar la conexión SMTP, etc.).');
        $this->newLine();
        $this->warn('Entre el re-cifrado y el paso 1, la aplicación NO puede leer sus secretos:');
        $this->warn('hacelo con el sistema detenido, no en caliente.');
    }
}
