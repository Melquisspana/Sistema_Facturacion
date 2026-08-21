<?php

namespace Tests\Feature\Configuracion;

use PHPUnit\Framework\TestCase;

/**
 * EL PROCEDIMIENTO DE DESPLIEGUE DEL CENTRO NOMBRA SUS MIGRACIONES, UNA POR UNA.
 *
 * EL FALLO QUE ESTO EVITA
 * ------------------------------------------------------------------
 * `php artisan migrate --force` no sabe de fases: aplica TODO lo que esté pendiente
 * en el árbol desplegado. Y el árbol siempre lleva módulos a medio camino que
 * todavía no se despliegan —cuando se escribió esto, Control de Asistencia con sus
 * cuatro migraciones—. Una corrida a secas los empuja a producción sin que nadie lo
 * haya decidido, y el operador se entera cuando ya están aplicados.
 *
 * Lo mismo, peor, al revertir: `migrate:rollback --step=1` deshace «la última
 * aplicada», sea cual sea. Se reprodujo en el ensayo de la Fase 6: con Asistencia en
 * el árbol, ese comando borró `asistencia_marcaciones` y dejó la configuración
 * exactamente donde estaba. Un rollback que destruye lo que no debía y no revierte
 * lo que debía es peor que no tener rollback.
 *
 * POR QUÉ SE PRUEBA EL DOCUMENTO Y NO EL CÓDIGO
 * ------------------------------------------------------------------
 * Acá no hay código que proteger: el procedimiento ES el documento, y lo ejecuta una
 * persona a mano en el servidor. Lo que puede romperse con el tiempo es que alguien
 * renombre una migración, agregue una cuarta al Centro, o «simplifique» el comando
 * quitándole las rutas. Las tres cosas dejan el documento pareciendo correcto.
 *
 * NO usa RefreshDatabase ni la aplicación: solo lee ficheros. Es una comprobación de
 * consistencia, no una prueba de comportamiento, y hereda del TestCase de PHPUnit
 * para que no arranque Laravel por nada.
 */
class ProcedimientoDespliegueTest extends TestCase
{
    private const RUNBOOK = 'docs/MIGRACION_PRODUCCION_CONFIGURACION.md';

    /**
     * Las migraciones del Centro de Configuración, y NADA MÁS.
     *
     * Agregar una cuarta acá sin agregarla al documento hace fallar este test, que
     * es justo lo que tiene que pasar: el despliegue la dejaría sin aplicar.
     */
    private const MIGRACIONES_DEL_CENTRO = [
        'database/migrations/2026_08_19_090000_create_ajustes_sistema_table.php',
        'database/migrations/2026_08_20_090000_create_verificaciones_configuracion_table.php',
        'database/migrations/2026_08_20_120000_migrar_configuraciones_correo_a_ajustes.php',
    ];

    /**
     * Dónde termina la parte OPERATIVA del documento. Lo que viene después es la
     * receta para repetir el ensayo en una base desechable, y ahí un `migrate` a
     * secas es legítimo: sirve para construir el estado de partida, no para
     * desplegar.
     */
    private const FIN_DE_LA_PARTE_OPERATIVA = '## 10. Cómo repetir el ensayo';

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    private function runbook(): string
    {
        $ruta = $this->raiz().'/'.self::RUNBOOK;

        $this->assertFileExists($ruta, 'El procedimiento de despliegue es parte del entregable.');

        return (string) file_get_contents($ruta);
    }

    /**
     * Comandos de consola del documento, ya unidos por sus continuaciones de línea y
     * limitados a los bloques de código: la prosa habla de `--step=1` para explicar
     * por qué NO se usa, y eso no es un comando.
     *
     * @return array<int, string>
     */
    private function comandos(string $texto): array
    {
        // Solo lo que va dentro de ``` ... ```
        preg_match_all('/```[a-z]*\n(.*?)```/s', $texto, $bloques, PREG_PATTERN_ORDER);
        $codigo = implode("\n", $bloques[1] ?? []);

        // Une "algo \<salto> continuación" en una sola línea.
        $codigo = preg_replace('/\\\\\r?\n\s*/', ' ', $codigo);

        return array_values(array_filter(
            array_map('trim', explode("\n", (string) $codigo)),
            static fn (string $linea) => str_contains($linea, 'artisan migrate'),
        ));
    }

    /** Rutas de migración que el documento nombra explícitamente. */
    private function rutasNombradas(string $texto): array
    {
        preg_match_all('/--path=(\S+)/', $texto, $coincidencias);

        return array_values(array_unique($coincidencias[1] ?? []));
    }

    // ------------------------------------------------------------ existencia

    /** Una ruta mal escrita convierte el comando en un no-op silencioso. */
    public function test_las_migraciones_del_centro_existen(): void
    {
        foreach (self::MIGRACIONES_DEL_CENTRO as $ruta) {
            $this->assertFileExists($this->raiz().'/'.$ruta);
        }
    }

    /** Y toda ruta que el documento nombre tiene que existir de verdad. */
    public function test_toda_ruta_nombrada_en_el_runbook_existe(): void
    {
        foreach ($this->rutasNombradas($this->runbook()) as $ruta) {
            $this->assertFileExists(
                $this->raiz().'/'.$ruta,
                "El runbook nombra «{$ruta}», que no existe: el comando no aplicaría nada.",
            );
        }
    }

    // -------------------------------------------------------------- cobertura

    /** Las tres tienen que estar nombradas; una que falte se queda sin aplicar. */
    public function test_el_runbook_nombra_las_tres_migraciones_del_centro(): void
    {
        $nombradas = $this->rutasNombradas($this->runbook());

        foreach (self::MIGRACIONES_DEL_CENTRO as $ruta) {
            $this->assertContains($ruta, $nombradas, "El runbook no nombra «{$ruta}».");
        }
    }

    /**
     * Y NINGUNA otra. Si mañana alguien mete acá la ruta de una migración de otro
     * módulo, ese módulo viajaría a producción dentro del despliegue del Centro.
     */
    public function test_el_runbook_no_nombra_migraciones_ajenas_al_centro(): void
    {
        foreach ($this->rutasNombradas($this->runbook()) as $ruta) {
            $this->assertContains(
                $ruta,
                self::MIGRACIONES_DEL_CENTRO,
                "«{$ruta}» no es del Centro de Configuración y no puede formar parte de su despliegue.",
            );
        }
    }

    // ----------------------------------------------------------- por ruta

    /**
     * EL CANDADO. Todo `migrate` y todo `migrate:rollback` de la parte operativa
     * lleva `--path`. Un comando sin rutas aplica o revierte lo que no toca.
     */
    public function test_ningun_comando_operativo_migra_sin_nombrar_rutas(): void
    {
        $operativo = explode(self::FIN_DE_LA_PARTE_OPERATIVA, $this->runbook())[0];

        $comandos = $this->comandos($operativo);

        $this->assertNotEmpty($comandos, 'El runbook debería contener los comandos del despliegue.');

        foreach ($comandos as $comando) {
            // `migrate:status` solo lee: no aplica ni revierte nada.
            if (str_contains($comando, 'migrate:status')) {
                continue;
            }

            $this->assertStringContainsString(
                '--path=',
                $comando,
                "Sin --path este comando alcanza migraciones de otros módulos: «{$comando}».",
            );
        }
    }

    /**
     * Y en particular, nunca un rollback contando pasos: `--step` sin `--path` es el
     * comando que en el ensayo borró una tabla ajena en vez de revertir la mudanza.
     */
    public function test_ningun_rollback_operativo_cuenta_pasos_a_secas(): void
    {
        $operativo = explode(self::FIN_DE_LA_PARTE_OPERATIVA, $this->runbook())[0];

        foreach ($this->comandos($operativo) as $comando) {
            if (! str_contains($comando, 'migrate:rollback')) {
                continue;
            }

            $this->assertStringContainsString(
                '--path=',
                $comando,
                "Revertir por número de pasos deshace «lo último aplicado», que puede no ser del Centro: «{$comando}».",
            );
        }
    }
}
