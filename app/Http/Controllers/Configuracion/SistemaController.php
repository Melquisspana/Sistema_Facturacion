<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\EstadoAjuste;
use App\Ajustes\Sistema\PanelSistema;
use App\Http\Controllers\Configuracion\Concerns\GuardaConConfirmacionN2;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Throwable;

/**
 * Configuración → Sistema: respaldos, cola, salud y entorno.
 *
 * SOLO DOS COSAS SON EDITABLES aquí —la retención de respaldos y el destinatario
 * de los avisos—; el resto es diagnóstico. Es la separación que hace útil la
 * pantalla: lo que se puede cambiar está arriba, y debajo está la evidencia de si
 * el sistema está haciendo su trabajo.
 *
 * LA SALUD NO SE RECALCULA: se reutiliza `DiagnosticoSistemaService`, el mismo que
 * usan el Dashboard y «Salud del sistema». Dos versiones de "¿la cola está bien?"
 * en el mismo sistema terminan discrepando, y la que se cree es la que uno mire
 * primero.
 *
 * LA ÚNICA ACCIÓN QUE ESCRIBE ALGO FUERA DE LA CONFIGURACIÓN es generar un
 * respaldo, y no es nueva: reutiliza `backup:mysql-diario --origen=manual`, la
 * misma operación que ya existía en «Preparar emisión real», con el mismo permiso
 * (`respaldos.ejecutar`). No se inventó ningún botón que ejecute algo que no
 * estuviera ya probado.
 */
class SistemaController extends Controller
{
    use GuardaConConfirmacionN2;

    /** Campo del formulario ⇒ clave del registry. Mapa FIJO: nada viene del navegador. */
    private const CAMPOS = [
        'retencion' => 'respaldos.dias_retencion',
        'avisos' => 'respaldos.notificaciones.correo',
    ];

    public function index(ServicioAjustes $ajustes, PanelSistema $panel): View
    {
        return view('configuracion.sistema.index', [
            'campos' => $this->estados($ajustes),
            'respaldos' => $panel->respaldos(),
            'cola' => $panel->cola(),
            'salud' => $panel->salud(),
            'entorno' => $panel->entorno(),
            // El botón de respaldo tiene su propio permiso, más estrecho que el de
            // configuración: se oculta a quien no podría usarlo.
            'puedeRespaldar' => (bool) request()->user()?->can('respaldos.ejecutar'),
        ]);
    }

    public function update(Request $request, ConfirmacionN2 $confirmacion): View|RedirectResponse
    {
        $datos = $request->validate([
            'retencion' => ['required', 'integer', 'min:1', 'max:3650'],
            'avisos' => ['nullable', 'email', 'max:255'],
        ], [], [
            'retencion' => 'días de retención',
            'avisos' => 'correo de avisos',
        ]);

        return $this->guardarConConfirmacion(
            $request,
            $confirmacion,
            self::CAMPOS,
            $datos,
            volver: 'configuracion.sistema',
            titulo: 'Vas a cambiar la política de respaldos',
            consecuencia: 'BAJAR los días de retención BORRA respaldos existentes en la siguiente limpieza automática, y eso no se puede deshacer. Si nadie lee el correo de avisos, un respaldo que falle pasa inadvertido hasta que haga falta restaurarlo.',
            exito: 'Política de respaldos guardada.',
            sinCambios: 'No había nada que cambiar en la política de respaldos.',
        );
    }

    /**
     * Genera un respaldo AHORA.
     *
     * Reutiliza el comando existente, que hace dump, verifica el SHA-256 y deja el
     * registro en `respaldo_ejecuciones`. Es una operación de solo lectura sobre
     * los datos: no altera ninguna tabla del negocio.
     */
    public function respaldar(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('respaldos.ejecutar'), 403);

        try {
            $codigo = Artisan::call('backup:mysql-diario', ['--origen' => 'manual']);
        } catch (Throwable $e) {
            return redirect()->route('configuracion.sistema')
                ->with('error', 'No se pudo generar el respaldo: '.$this->corto($e->getMessage()));
        }

        return redirect()->route('configuracion.sistema')->with(
            $codigo === 0 ? 'status' : 'error',
            $codigo === 0
                ? 'Respaldo generado y verificado. Revisá «último respaldo válido» abajo.'
                : 'El respaldo terminó con código '.$codigo.'. Revisá el registro del servidor.',
        );
    }

    // ---------------------------------------------------------------- interno

    /**
     * La salida de un comando puede arrastrar la línea de conexión de mysqldump
     * (usuario, host). Se recorta a la primera línea y se acota antes de mostrarla.
     */
    private function corto(string $mensaje): string
    {
        return mb_substr(trim((string) strtok($mensaje, "\r\n")), 0, 200);
    }

    /** @return array<string, EstadoAjuste> */
    private function estados(ServicioAjustes $ajustes): array
    {
        $estados = [];

        foreach (self::CAMPOS as $campo => $clave) {
            $estados[$campo] = $ajustes->estadoParaPantalla($clave);
        }

        return $estados;
    }
}
