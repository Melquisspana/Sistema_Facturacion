<?php

namespace App\Services\Planilla;

use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\Dinero;
use App\Support\Dte\NumeroALetras;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * El contrato individual de trabajo de quien va en planilla CON descuentos de ley.
 *
 * Quien va sin descuentos no lleva contrato: eso se rechaza acá, no solo en el botón.
 *
 * NO SE GUARDA NINGUNA COPIA. Cada impresión se arma con los datos de ese momento: la
 * fecha del día y el sueldo vigente ese día. Si sube el salario mínimo, se cambia el
 * sueldo y se vuelve a generar.
 *
 * El texto legal va en la vista, con marcadores. Los datos del patrono no están en el
 * repositorio: razón social y NIT salen de `empresas`, y lo demás del `.env`. Sin
 * valores de ejemplo a propósito: un contrato con un patrono inventado es peor que uno
 * que no sale.
 */
final class ContratoTrabajo
{
    /** Lo que se exige de la ficha para imprimir, con su nombre en pantalla. */
    private const CAMPOS = [
        'sexo' => 'sexo', 'fecha_nacimiento' => 'fecha de nacimiento', 'estado_civil' => 'estado civil',
        'profesion' => 'profesión u oficio', 'domicilio' => 'domicilio', 'residencia' => 'residencia',
        'nacionalidad' => 'nacionalidad', 'dui' => 'número de documento', 'cargo' => 'cargo',
        'funciones' => 'funciones', 'alta_el' => 'fecha de ingreso', 'lugar_trabajo' => 'lugar de trabajo',
        'herramientas' => 'herramientas y materiales',
    ];

    private const PATRONO = [
        'sexo' => 'sexo', 'fecha_nacimiento' => 'fecha de nacimiento', 'estado_civil' => 'estado civil',
        'profesion' => 'profesión u oficio', 'domicilio' => 'domicilio', 'residencia' => 'residencia',
        'nacionalidad' => 'nacionalidad', 'dui' => 'DUI', 'dui_expedido_en' => 'lugar de expedición del DUI',
        'dui_expedido_el' => 'fecha de expedición del DUI', 'actividad' => 'actividad económica',
        'ciudad' => 'ciudad de firma', 'direccion_pago' => 'dirección de pago',
    ];

    public function __construct(
        private SueldoHabitual $sueldos,
        private IdentidadNegocio $identidad,
    ) {}

    /** @param  array<string, mixed>  $datos */
    public function guardarDatos(User $usuario, PlanillaEmpleado $empleado, array $datos): void
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);
        abort_unless($usuario->can('planilla.salarios'), 403);
        $this->exigirConDescuentos($empleado);

        $hoy = now()->toDateString();
        $texto = fn (int $largo) => ['nullable', 'string', 'max:'.$largo];

        $limpios = Validator::make($datos, [
            'sexo' => ['nullable', Rule::in(['F', 'M'])],
            'fecha_nacimiento' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->subYears(14)->toDateString(), 'after_or_equal:'.now()->subYears(100)->toDateString()],
            'estado_civil' => $texto(30), 'profesion' => $texto(120), 'domicilio' => $texto(180),
            'residencia' => $texto(255), 'nacionalidad' => $texto(60), 'cargo' => $texto(120),
            'dui_expedido_en' => $texto(120),
            'dui_expedido_el' => array_merge(
                ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
                filled($datos['fecha_nacimiento'] ?? null) ? ['after:fecha_nacimiento'] : [],
            ),
            'alta_el' => ['nullable', 'date_format:Y-m-d'],
            'funciones' => $texto(2000), 'lugar_trabajo' => $texto(255), 'herramientas' => $texto(2000),
            'dependientes' => ['nullable', 'array', 'max:4'],
            'dependientes.*.nombre' => ['nullable', 'string', 'max:80'],
            'dependientes.*.apellido' => ['nullable', 'string', 'max:80'],
            'dependientes.*.edad' => ['nullable', 'integer', 'between:0,120'],
            'dependientes.*.direccion' => ['nullable', 'string', 'max:180'],
        ], [
            'fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento no es coherente: la persona tendría menos de 14 años.',
            'fecha_nacimiento.after_or_equal' => 'La fecha de nacimiento no es coherente: la persona tendría más de 100 años.',
            'dui_expedido_el.before_or_equal' => 'La fecha de expedición no puede ser futura.',
            'dui_expedido_el.after' => 'La fecha de expedición tiene que ser posterior a la de nacimiento.',
        ])->validate();

        // Las filas vacías del formulario no son dependientes.
        $limpios['dependientes'] = array_values(array_filter(
            $limpios['dependientes'] ?? [],
            fn (array $d) => filled($d['nombre'] ?? null) || filled($d['apellido'] ?? null),
        ));

        $empleado->update($limpios);
        activity('empleado')->performedOn($empleado)->causedBy($usuario)->log('actualizó los datos del contrato');
    }

    /**
     * Qué falta para poder imprimir, en palabras. Vacío = se puede.
     *
     * @return array<int, string>
     */
    public function faltantes(PlanillaEmpleado $empleado): array
    {
        $faltan = [];

        foreach (self::CAMPOS as $campo => $nombre) {
            if (blank($empleado->$campo)) {
                $faltan[] = $nombre;
            }
        }

        if ($empleado->documento_tipo === 'dui') {
            foreach (['dui_expedido_en' => 'lugar de expedición del DUI', 'dui_expedido_el' => 'fecha de expedición del DUI'] as $campo => $nombre) {
                if (blank($empleado->$campo)) {
                    $faltan[] = $nombre;
                }
            }
        }

        if ($this->mensualVigente($empleado) === null) {
            $faltan[] = 'sueldo';
        }

        return array_merge($faltan, $this->faltantesDelPatrono());
    }

    /**
     * Lo que conviene mirar, sin frenar.
     *
     * @return array<int, string>
     */
    public function avisos(PlanillaEmpleado $empleado): array
    {
        if ($empleado->fecha_nacimiento !== null && $empleado->fecha_nacimiento->age < 18) {
            return ['La persona es menor de 18 años: revisá las condiciones del contrato con la contadora.'];
        }

        return [];
    }

    /**
     * Todo lo que la vista necesita. Lanza si no se puede imprimir.
     *
     * @return array<string, mixed>
     */
    public function datos(PlanillaEmpleado $empleado): array
    {
        $this->exigirConDescuentos($empleado);

        if (($faltan = $this->faltantes($empleado)) !== []) {
            throw ValidationException::withMessages(['contrato' => 'Faltan datos para el contrato: '.implode(', ', $faltan).'.']);
        }

        $hoy = CarbonImmutable::now()->locale('es');
        $mensual = Dinero::decimal($this->mensualVigente($empleado));
        $patrono = (array) config('planilla.contrato.patrono');
        $nacioPatrono = CarbonImmutable::parse($patrono['fecha_nacimiento']);

        return [
            'empleado' => $empleado,
            'edad' => $empleado->fecha_nacimiento->age,
            'documento' => PlanillaEmpleado::DOCUMENTO_TIPOS[$empleado->documento_tipo],
            'expedido_el' => $this->fechaLarga($empleado->dui_expedido_el),
            'inicio' => $this->fechaLarga($empleado->alta_el),
            'sueldo' => Dinero::mostrar($mensual),
            'sueldo_letras' => NumeroALetras::convertir($mensual),
            'hoy' => $hoy,
            'patrono' => $patrono + [
                'nombre' => $this->identidad->empleadora(),
                'razon_social' => $this->identidad->negocio(),
                'nit' => $this->identidad->nit(),
                'edad' => $nacioPatrono->age,
                'expedido_el' => $this->fechaLarga($patrono['dui_expedido_el']),
            ],
        ];
    }

    /** @return array<int, string> */
    private function faltantesDelPatrono(): array
    {
        $patrono = (array) config('planilla.contrato.patrono');
        $faltan = array_values(array_map(
            fn (string $nombre) => $nombre.' del patrono',
            array_filter(self::PATRONO, fn (string $campo) => blank($patrono[$campo] ?? null), ARRAY_FILTER_USE_KEY),
        ));

        foreach (['nombre del patrono' => $this->identidad->empleadora(), 'NIT del patrono' => $this->identidad->nit()] as $nombre => $valor) {
            if (blank($valor)) {
                $faltan[] = $nombre;
            }
        }

        return $faltan;
    }

    private function mensualVigente(PlanillaEmpleado $empleado): ?int
    {
        return $this->sueldos->mensualesEn(new Collection([$empleado]), now()->toDateString())[$empleado->id] ?? null;
    }

    private function exigirConDescuentos(PlanillaEmpleado $empleado): void
    {
        if (! $empleado->con_descuentos_ley) {
            throw ValidationException::withMessages(['contrato' => 'Quien va sin descuentos de ley no lleva contrato.']);
        }
    }

    private function fechaLarga(mixed $fecha): ?string
    {
        return blank($fecha) ? null : CarbonImmutable::parse($fecha)->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }
}
