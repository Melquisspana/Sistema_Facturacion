<?php

namespace App\Services\Planilla;

use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Dar de alta a alguien en planilla REUTILIZANDO las identidades que ya existen.
 *
 * El sistema ya tiene personas en tres sitios —`users`, `asistencia_empleados` y
 * `rutas_personal`— y hoy las mismas se repiten sin enlazar. Esta pantalla no agrega
 * una cuarta lista suelta: ofrece a quien YA está y lo engancha con un puntero, de
 * modo que el Rene de planilla sea demostrablemente el Rene de asistencia.
 *
 * TRES COSAS QUE NO HACE, y son las que pidió el encargo:
 *
 *  1. NO EXIGE BIOMETRÍA. No lee `asistencia_huellas`, no consulta marcaciones y no
 *     le importa si la persona tiene huella registrada. Alguien sin huella entra a
 *     planilla igual.
 *  2. NO EXIGE EL MÓDULO DE ASISTENCIA ENCENDIDO. Solo lee nombres de una tabla. Con
 *     `ASISTENCIA_ENABLED=false` la lista de candidatos sigue saliendo.
 *  3. NO DUPLICA PERSONAS. Los punteros son únicos en la base, así que la misma
 *     identidad no puede entrar dos veces; y quien ya está en planilla deja de
 *     ofrecerse como candidato.
 *
 * También se puede dar de alta a alguien que no está en ningún otro módulo —personal
 * de oficina que nunca marca— escribiendo su nombre. Ese es el único caso en que se
 * escribe una persona nueva.
 */
final class RegistrarEmpleado
{
    /**
     * Personas que ya existen en el sistema y todavía no están en planilla.
     *
     * @return array<int, array{origen: string, tipo: string, id: int, nombre: string, detalle: string}>
     */
    public function candidatos(): array
    {
        $usados = [
            'asistencia' => PlanillaEmpleado::whereNotNull('asistencia_empleado_id')->pluck('asistencia_empleado_id')->all(),
            'ruta' => PlanillaEmpleado::whereNotNull('personal_ruta_id')->pluck('personal_ruta_id')->all(),
            'usuario' => PlanillaEmpleado::whereNotNull('user_id')->pluck('user_id')->all(),
        ];

        $candidatos = [];

        // Asistencia. Solo el nombre: ni huellas, ni marcaciones, ni el interruptor.
        foreach (AsistenciaEmpleado::where('activo', true)->orderBy('nombres')->get() as $e) {
            if (in_array($e->id, $usados['asistencia'], true)) {
                continue;
            }

            $candidatos[] = [
                'origen' => 'Asistencia', 'tipo' => 'asistencia', 'id' => $e->id,
                'nombre' => trim($e->nombres.' '.$e->apellidos),
                'detalle' => $e->codigo ? 'Código '.$e->codigo : 'Marca asistencia',
            ];
        }

        foreach (PersonalRuta::where('activo', true)->orderBy('nombre')->get() as $p) {
            if (in_array($p->id, $usados['ruta'], true)) {
                continue;
            }

            $candidatos[] = [
                'origen' => 'Personal de Rutas', 'tipo' => 'ruta', 'id' => $p->id,
                'nombre' => $p->nombre, 'detalle' => 'Operación de campo',
            ];
        }

        foreach (User::where('activo', true)->orderBy('name')->get() as $u) {
            if (in_array($u->id, $usados['usuario'], true)) {
                continue;
            }

            $candidatos[] = [
                'origen' => 'Usuario del sistema', 'tipo' => 'usuario', 'id' => $u->id,
                'nombre' => $u->name, 'detalle' => $u->email,
            ];
        }

        // Se ordena por nombre para que la misma persona, si aparece desde dos módulos,
        // quede junta y se vea que es la misma.
        usort($candidatos, fn ($a, $b) => strcasecmp($a['nombre'], $b['nombre']));

        return $candidatos;
    }

    /** @param  array<string, mixed>  $datos */
    public function registrar(User $usuario, array $datos): PlanillaEmpleado
    {
        abort_unless($usuario->activo && $usuario->can('planilla.gestionar'), 403);

        $datos = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:180'],
            'codigo' => ['nullable', 'string', 'max:40'],
            // El DUI es TEXTO. Validado como cadena y guardado como cadena: el cero de
            // la izquierda de «0123 4567-8» es parte del documento, y como número se
            // perdería sin que nadie lo note hasta ver un recibo mal impreso.
            'dui' => ['nullable', 'string', 'max:20'],
            'cargo' => ['nullable', 'string', 'max:120'],
            'alta_el' => ['nullable', 'date_format:Y-m-d'],
            'salario_referencia' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'origen_tipo' => ['nullable', Rule::in(['asistencia', 'ruta', 'usuario'])],
            'origen_id' => ['nullable', 'integer'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ], [
            'nombre.required' => 'Escribí el nombre, o elegí a alguien de la lista.',
        ])->validate();

        $punteros = ['asistencia_empleado_id' => null, 'personal_ruta_id' => null, 'user_id' => null];

        if (filled($datos['origen_tipo'] ?? null) && filled($datos['origen_id'] ?? null)) {
            $columna = match ($datos['origen_tipo']) {
                'asistencia' => 'asistencia_empleado_id',
                'ruta' => 'personal_ruta_id',
                default => 'user_id',
            };

            // Si ya está en planilla por ese puntero, se devuelve la ficha existente en
            // vez de fallar: el objetivo es no duplicar, no castigar el doble clic.
            if ($yaEsta = PlanillaEmpleado::where($columna, $datos['origen_id'])->first()) {
                // Reincorporación: vuelve la MISMA persona, con su historial pegado, no
                // una segunda ficha con el mismo nombre. Se le completa lo que falte sin
                // pisar lo que ya tenía.
                $yaEsta->fill(array_filter([
                    'dui' => $datos['dui'] ?? null,
                    'cargo' => $datos['cargo'] ?? null,
                ], fn ($v) => filled($v)) + ['activo' => true, 'baja_el' => null])->save();

                return $yaEsta->fresh();
            }

            $punteros[$columna] = (int) $datos['origen_id'];
        }

        return DB::transaction(fn () => PlanillaEmpleado::create($punteros + [
            'clave' => (string) Str::uuid(),
            'nombre' => trim($datos['nombre']),
            'codigo' => $datos['codigo'] ?? null,
            'dui' => filled($datos['dui'] ?? null) ? trim($datos['dui']) : null,
            'cargo' => $datos['cargo'] ?? null,
            'alta_el' => $datos['alta_el'] ?? null,
            'salario_referencia' => filled($datos['salario_referencia'] ?? null)
                ? Dinero::decimal(Dinero::centavos($datos['salario_referencia']))
                : null,
            'activo' => true,
            'notas' => $datos['notas'] ?? null,
            'registrado_por' => $usuario->id,
        ]));
    }

    /** Nombre propuesto para un candidato, para no volver a escribirlo. */
    public function nombreDe(string $tipo, int $id): ?string
    {
        if ($tipo === 'asistencia') {
            $e = AsistenciaEmpleado::find($id);

            return $e === null ? null : trim($e->nombres.' '.$e->apellidos);
        }

        return match ($tipo) {
            'ruta' => PersonalRuta::find($id)?->nombre,
            'usuario' => User::find($id)?->name,
            default => null,
        };
    }
}
