<?php

namespace App\Http\Controllers\Configuracion;

use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Ceremonias\CambioPropuesto;
use App\Ajustes\Ceremonias\ConfirmacionN2;
use App\Ajustes\Excepciones\ValorAjusteInvalidoException;
use App\Facades\Ajustes;
use App\Http\Controllers\Controller;
use App\Support\Dte\CalendarioInhabiles;
use App\Support\HoraNegocio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configuración → Facturación electrónica → Feriados de Hacienda (issue #49).
 *
 * Los días inhábiles del MH, por año, para el plazo de invalidar un CCF, una NC o
 * una ND (decisión 0005). Se agrega un día o un rango «desde–hasta» (que se guarda
 * como días sueltos), se quita un día y se marca si el año está completo.
 *
 * Cada acción trabaja sobre el calendario VIGENTE del año: si el año todavía sale
 * de config/dte.php, el primer cambio lo copia entero a Configuración. Todo pasa
 * por la confirmación N2 y la auditoría central de los ajustes, igual que el
 * servidor de correo o los respaldos. El permiso lo pone el grupo de rutas
 * (`configuracion.gestionar`) y lo vuelve a exigir el registro al guardar.
 *
 * El primer envío no escribe; el segundo, con `confirmacion`, recalcula desde lo
 * que hay en ese momento y guarda. Nada de lo que viaja en campos ocultos decide
 * qué ajuste se escribe.
 */
class FeriadosHaciendaController extends Controller
{
    public function index(Request $request, CalendarioInhabiles $calendario): View
    {
        $anio = $request->query('anio', (string) HoraNegocio::hoy()->year);
        abort_unless(is_string($anio) && $this->anioValido($anio), 404);

        $vigente = $calendario->delAnio($anio);
        $hoy = HoraNegocio::hoy()->year;
        $anios = array_unique(array_merge(
            $calendario->anios(),
            array_map('strval', range($hoy - 1, $hoy + 1)),
            [$anio],
        ));
        sort($anios);

        return view('configuracion.fiscal.feriados', [
            'anio' => $anio,
            'anios' => $anios,
            'fechas' => array_map(fn (string $fecha) => [
                'valor' => $fecha,
                'texto' => Carbon::createFromFormat('!Y-m-d', $fecha)->format('d/m/Y'),
                'dia' => $this->dia($fecha),
            ], $vigente['fechas'] ?? []),
            'completo' => $vigente['completo'] ?? false,
            'enConfiguracion' => $vigente['en_configuracion'] ?? false,
            'tieneCalendario' => $vigente !== null,
            'maximo' => CalendarioInhabiles::MAXIMO_POR_ANIO,
        ]);
    }

    /** Un día suelto, o un rango «desde–hasta» del mismo año que se guarda día por día. */
    public function agregar(Request $request, ConfirmacionN2 $confirmacion, CalendarioInhabiles $calendario): View|RedirectResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'between:2020,2099'],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [
            'hasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a «desde».',
        ], ['anio' => 'año', 'desde' => 'desde', 'hasta' => 'hasta']);

        $anio = (string) $datos['anio'];
        $hasta = $datos['hasta'] ?? null;
        foreach (['desde' => $datos['desde'], 'hasta' => $hasta] as $campo => $fecha) {
            if ($fecha !== null && substr($fecha, 0, 4) !== $anio) {
                throw ValidationException::withMessages([$campo => "La fecha tiene que ser de {$anio}."]);
            }
        }

        $rango = [];
        $dia = Carbon::createFromFormat('!Y-m-d', $datos['desde']);
        $fin = Carbon::createFromFormat('!Y-m-d', $hasta ?? $datos['desde']);
        for (; $dia->lte($fin); $dia->addDay()) {
            $rango[] = $dia->format('Y-m-d');
        }

        $vigente = $calendario->delAnio($anio);
        $actuales = $vigente['fechas'] ?? [];

        if (array_diff($rango, $actuales) === []) {
            throw ValidationException::withMessages([
                'desde' => count($rango) === 1 ? 'Esa fecha ya está en la lista.' : 'Esas fechas ya están en la lista.',
            ]);
        }

        $nuevas = array_values(array_unique(array_merge($actuales, $rango)));
        sort($nuevas);

        if (count($nuevas) > CalendarioInhabiles::MAXIMO_POR_ANIO) {
            throw ValidationException::withMessages([
                $hasta === null ? 'desde' : 'hasta' => 'Un año no puede tener más de '.CalendarioInhabiles::MAXIMO_POR_ANIO.' días inhábiles. Revisá el rango.',
            ]);
        }

        return $this->guardar($request, $confirmacion, $calendario, $anio, $nuevas, $vigente['completo'] ?? false, 'POST', [
            'anio' => $anio, 'desde' => $datos['desde'], 'hasta' => (string) $hasta,
        ], 'desde', 'Días inhábiles de '.$anio.' guardados.');
    }

    public function quitar(Request $request, ConfirmacionN2 $confirmacion, CalendarioInhabiles $calendario): View|RedirectResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'between:2020,2099'],
            'fecha' => ['required', 'date_format:Y-m-d'],
        ], [], ['anio' => 'año', 'fecha' => 'fecha']);

        $anio = (string) $datos['anio'];
        $vigente = $calendario->delAnio($anio);

        if (! in_array($datos['fecha'], $vigente['fechas'] ?? [], true)) {
            throw ValidationException::withMessages(['fecha' => 'Esa fecha no está en la lista de '.$anio.'.']);
        }

        return $this->guardar(
            $request, $confirmacion, $calendario, $anio,
            array_values(array_diff($vigente['fechas'], [$datos['fecha']])),
            $vigente['completo'],
            'DELETE', ['anio' => $anio, 'fecha' => $datos['fecha']],
            'fecha', 'Se quitó el '.$this->legible([$datos['fecha']]).' de los días inhábiles.',
        );
    }

    public function completo(Request $request, ConfirmacionN2 $confirmacion, CalendarioInhabiles $calendario): View|RedirectResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'between:2020,2099'],
            'completo' => ['nullable', 'boolean'],
        ], [], ['anio' => 'año']);

        $anio = (string) $datos['anio'];
        $completo = $request->boolean('completo');

        return $this->guardar(
            $request, $confirmacion, $calendario, $anio,
            $calendario->delAnio($anio)['fechas'] ?? [],
            $completo,
            'PUT', ['anio' => $anio, 'completo' => $completo ? '1' : '0'],
            'completo', $completo ? "Calendario de {$anio} marcado como completo." : "Calendario de {$anio} marcado como incompleto.",
        );
    }

    // ---------------------------------------------------------------- interno

    /**
     * Confirmación N2 y guardado. Se compara el calendario VIGENTE del año (lo que
     * ve el usuario), no las filas: así, marcar «completo» un año del archivo que ya
     * lo es no copia nada.
     *
     * @param  array<int, string>  $fechas  fechas del año como deben quedar
     * @param  array<string, string>  $valores  lo que reenvía la confirmación (ya validado)
     */
    private function guardar(
        Request $request,
        ConfirmacionN2 $confirmacion,
        CalendarioInhabiles $calendario,
        string $anio,
        array $fechas,
        bool $completo,
        string $metodo,
        array $valores,
        string $campo,
        string $exito,
    ): View|RedirectResponse {
        $volver = route('configuracion.fiscal.feriados', ['anio' => $anio]);
        $cambios = $this->cambios($anio, $calendario->delAnio($anio), $fechas, $completo);
        $propuestos = $calendario->propuesta($anio, $fechas, $completo);
        $reales = $confirmacion->calcular($propuestos);

        if ($cambios === [] || $reales === []) {
            return redirect($volver)->with('status', "No había nada que cambiar en el calendario de {$anio}.");
        }

        if (! $request->boolean('confirmacion') && $confirmacion->requiereConfirmacion($reales)) {
            return view('configuracion.confirmar', [
                'titulo' => "Vas a cambiar los días inhábiles de Hacienda de {$anio}",
                'consecuencia' => 'Estos días no cuentan en el plazo para invalidar un crédito fiscal, una nota de crédito o una nota de débito. Si sobra un día, el sistema da más plazo del que da Hacienda y la invalidación puede ser rechazada; si falta uno, da menos.',
                'cambios' => $cambios,
                'accion' => $request->url(),
                'metodo' => $metodo,
                'valores' => $valores,
                'volver' => $volver,
            ]);
        }

        // Solo lo que cambia: guardar una lista igual dejaría una línea vacía en la auditoría.
        $claves = array_map(static fn (CambioPropuesto $c) => $c->clave, $reales);

        try {
            Ajustes::guardarVarios(array_intersect_key($propuestos, array_flip($claves)));
        } catch (ValorAjusteInvalidoException $e) {
            throw ValidationException::withMessages([$campo => $e->getMessage()]);
        }

        return redirect($volver)->with('status', $exito);
    }

    /**
     * Lo que se le muestra a Melqui antes de confirmar, en sus palabras: qué días
     * entran o salen y si cambia la marca de completo. No las listas enteras.
     *
     * @param  array{fechas: array<int, string>, completo: bool, en_configuracion: bool}|null  $antes
     * @param  array<int, string>  $fechas
     * @return array<int, CambioPropuesto>
     */
    private function cambios(string $anio, ?array $antes, array $fechas, bool $completo): array
    {
        $catalogo = app(CatalogoAjustes::class);
        $previas = $antes['fechas'] ?? [];
        $agregadas = array_values(array_diff($fechas, $previas));
        $quitadas = array_values(array_diff($previas, $fechas));
        $cambios = [];

        if ($agregadas !== [] || $quitadas !== []) {
            $detalle = array_filter([
                $agregadas === [] ? null : (count($agregadas) === 1 ? 'se agrega el ' : 'se agregan ').$this->legible($agregadas),
                $quitadas === [] ? null : (count($quitadas) === 1 ? 'se quita el ' : 'se quitan ').$this->legible($quitadas),
            ]);
            $cambios[] = CambioPropuesto::deValor(
                $catalogo->definicion(CalendarioInhabiles::FECHAS),
                "{$anio}: ".$this->cuantos($previas),
                "{$anio}: ".$this->cuantos($fechas).' ('.implode('; ', $detalle).')',
            );
        }

        if (($antes['completo'] ?? false) !== $completo) {
            $cambios[] = CambioPropuesto::deValor(
                $catalogo->definicion(CalendarioInhabiles::COMPLETOS),
                "{$anio}: ".($completo ? 'sin marcar' : 'completo'),
                "{$anio}: ".($completo ? 'completo' : 'sin marcar'),
            );
        }

        return $cambios;
    }

    private function anioValido(string $anio): bool
    {
        return preg_match('/^\d{4}$/D', $anio) === 1 && (int) $anio >= 2020 && (int) $anio <= 2099;
    }

    /** @param  array<int, string>  $fechas */
    private function cuantos(array $fechas): string
    {
        return count($fechas) === 1 ? '1 día' : count($fechas).' días';
    }

    /** «viernes 01/05/2026, sábado 02/05/2026». @param  array<int, string>  $fechas */
    private function legible(array $fechas): string
    {
        return implode(', ', array_map(
            fn (string $fecha) => $this->dia($fecha).' '.Carbon::createFromFormat('!Y-m-d', $fecha)->format('d/m/Y'),
            $fechas,
        ));
    }

    /** Día de la semana en castellano, sin depender del idioma de la aplicación. */
    private function dia(string $fecha): string
    {
        return CatalogoAjustes::DIAS[Carbon::createFromFormat('!Y-m-d', $fecha)->dayOfWeekIso];
    }
}
