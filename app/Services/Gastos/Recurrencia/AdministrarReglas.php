<?php

namespace App\Services\Gastos\Recurrencia;

use App\Models\Gastos\Gasto;
use App\Models\Gastos\Ocurrencia;
use App\Models\Gastos\Regla;
use App\Models\Gastos\ReglaVersion;
use App\Models\User;
use App\Services\Gastos\Dinero;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Alta, edición y ciclo de vida de las reglas recurrentes.
 *
 * Crear una regla NO crea deuda. Se guarda la plantilla y su calendario; las
 * obligaciones aparecen cuando {@see GenerarObligaciones} las genera, y nacen
 * impagas. Separarlo es deliberado: configurar mal una regla no puede asentar nada
 * al instante, y quien la crea tiene tiempo de revisarla antes del primer período.
 *
 * EDITAR NO REESCRIBE EL PASADO. Cada cambio crea una VERSIÓN nueva con su fecha de
 * efecto y deja la anterior intacta. Las ocurrencias ya generadas siguen apuntando
 * a la versión con la que nacieron, así que subir el alquiler en marzo no cambia lo
 * que se debía en enero ni el informe de ese mes.
 *
 * PAUSAR ≠ CANCELAR ≠ OMITIR, y ninguno borra una deuda:
 *
 *   pausar    frena la generación futura. Lo generado sigue exigible.
 *   reanudar  vuelve a generar; los períodos que pasaron durante la pausa se
 *             revisan con la ventana de recuperación, no se crean a ciegas.
 *   cancelar  cierra la regla para siempre. Las obligaciones que ya produjo
 *             siguen vivas: si alguna no corresponde, se anula una por una y con
 *             su motivo.
 *   omitir    ocupa UN período sin crear obligación, con motivo. Es la única
 *             forma de decir «este mes no» sin que la corrida siguiente lo genere.
 */
final class AdministrarReglas
{
    public function __construct(private CalendarioRecurrencia $calendario) {}

    /** @param  array<string, mixed>  $datos */
    /**
     * Crea la regla. `$datos['incompleta']` la guarda como «Por completar».
     *
     * Guardar a medio llenar existe por una razón concreta: cuando el sistema EXIGE un
     * día de cobro para dejar guardar, quien no lo sabe pone cualquiera. Un día
     * inventado se ve idéntico a uno real y genera deuda en la fecha equivocada. Es
     * mejor una regla que dice en voz alta que le falta algo.
     */
    public function crear(User $usuario, array $datos): Regla
    {
        $this->autorizar($usuario);
        $incompleta = (bool) ($datos['incompleta'] ?? false);
        $datos = $this->validar($usuario, $datos, null, $incompleta);

        return DB::transaction(function () use ($usuario, $datos, $incompleta) {
            User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();

            // Doble clic o reenvío del formulario: la clave es única en base y acá se
            // devuelve la regla que ya se creó en vez de crear una gemela.
            if ($existente = Regla::where('clave', $datos['clave'])->first()) {
                return $existente;
            }

            $regla = Regla::create($this->camposDeLaRegla($datos) + [
                'clave' => $datos['clave'],
                'estado' => $incompleta ? 'borrador' : 'activa',
                'version' => 1,
                'zona_horaria' => config('app.timezone'),
                'registrado_por' => $usuario->id,
            ]);

            $this->guardarVersion($regla, $usuario, 'Alta de la regla.');
            $this->evento($regla, $usuario, 'regla_creada', [
                'nombre' => $regla->nombre,
                'frecuencia' => $regla->frecuencia,
                'cuando' => $this->calendario->enPalabras($regla->calendario()),
                'monto_modo' => $regla->monto_modo,
                'importe' => $regla->importe,
            ]);

            return $regla->fresh();
        });
    }

    /**
     * Cambia la plantilla creando una VERSIÓN nueva. No toca ocurrencias existentes.
     *
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(User $usuario, Regla $regla, array $datos, string $motivo): Regla
    {
        $this->autorizar($usuario);

        if ($regla->estado === 'cancelada') {
            throw ValidationException::withMessages([
                'estado' => 'Esta regla está cancelada. Creá una nueva en vez de reabrir la cerrada: así queda claro desde cuándo rige lo nuevo.',
            ]);
        }

        $datos = $this->validar($usuario, $datos + ['clave' => $regla->clave], $regla);

        Validator::make(['motivo' => trim($motivo)], [
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Explicá qué cambió y por qué: la versión anterior se conserva y hay que poder comparar.',
        ])->validate();

        return DB::transaction(function () use ($usuario, $regla, $datos, $motivo) {
            $regla = Regla::whereKey($regla->id)->lockForUpdate()->firstOrFail();
            $antes = $regla->plantilla() + $regla->calendario();

            $regla->update($this->camposDeLaRegla($datos) + ['version' => $regla->version + 1]);

            $this->guardarVersion($regla->fresh(), $usuario, trim($motivo));
            $this->evento($regla, $usuario, 'regla_actualizada', [
                'version' => $regla->version,
                'motivo' => trim($motivo),
                'antes' => $antes,
                'despues' => $regla->plantilla() + $regla->calendario(),
            ]);

            return $regla->fresh();
        });
    }

    public function pausar(User $usuario, Regla $regla, string $motivo): Regla
    {
        return $this->cambiarEstado($usuario, $regla, 'pausada', $motivo, [
            'activa' => true,
        ], 'Solo se puede pausar una regla activa.');
    }

    public function reanudar(User $usuario, Regla $regla, string $motivo): Regla
    {
        return $this->cambiarEstado($usuario, $regla, 'activa', $motivo, [
            'pausada' => true,
        ], 'Solo se puede reanudar una regla pausada.');
    }

    /**
     * Completa lo que le faltaba a una regla «Por completar» y la activa.
     *
     * Es el único camino de borrador a activa, y exige el CUÁNDO: si faltara, se estaría
     * activando una regla que no sabe cuándo generar, que es justo lo que el borrador
     * venía a evitar.
     *
     * LA VIGENCIA NO SE MUEVE. Antes, activar reescribía `vigente_desde` a HOY para que
     * un borrador olvidado no despertara generando meses de deuda. El efecto real era
     * otro: una regla guardada el día 1 con vigencia desde el día 1 perdía su propio
     * primer período en cuanto se la completaba el día 16, en silencio y sin decirlo en
     * ningún lado. El riesgo que eso intentaba tapar ya está cubierto donde corresponde
     * —{@see GenerarObligaciones} solo recupera dentro de
     * `gastos.recurrencias.ventana_recuperacion_dias` e informa el resto como
     * `fuera_de_ventana`—, y además nada se genera sin que una persona lo pida. Si de
     * verdad hay que empezar más tarde, se edita la vigencia a la vista.
     *
     * `$programacion` también admite `beneficiario`: cuando el dato pendiente no era el
     * día sino a quién se le paga —el nombre de la institución, típicamente—, se corrige
     * acá mismo. Es la MISMA regla con una versión más, nunca una regla nueva.
     *
     * @param  array<string, mixed>  $programacion
     */
    public function completarYActivar(User $usuario, Regla $regla, array $programacion): Regla
    {
        $this->autorizar($usuario);

        if (! $regla->porCompletar()) {
            throw ValidationException::withMessages([
                'estado' => 'Esta regla no está por completar: ya está '
                    .mb_strtolower(Regla::ESTADOS[$regla->estado] ?? $regla->estado).'.',
            ]);
        }

        // Se revalida la regla ENTERA con el «cuándo» nuevo, no solo los campos que
        // llegan: es la misma puerta por la que pasa cualquier regla que se active.
        // Ojo con `only()`: devuelve los atributos YA CASTEADOS, así que `vigente_desde`
        // y `vigente_hasta` llegan como Carbon y no como texto. `validar()` los normaliza
        // antes de la regla `date_format:Y-m-d`, que con un objeto fallaba siempre.
        //
        // `$programacion` pisa lo guardado TAL CUAL, nulos incluidos. Es a propósito: el
        // formulario manda el campo vacío como null, y si acá se descartaran los nulos,
        // borrar el nombre y enviar activaría la regla con el «(nombre por confirmar)»
        // viejo, como si se hubiera confirmado. Que llegue null y choque contra
        // `required` es la respuesta correcta. Los campos que la pantalla no dibuja —el
        // día de la semana en una mensual— no viajan, y esos sí conservan lo guardado.
        $datos = $this->validar($usuario, array_merge($regla->only([
            'clave', 'nombre', 'beneficiario', 'concepto', 'categoria', 'ambito', 'persona',
            'naturaleza', 'moneda', 'documentacion', 'observaciones', 'responsable_id',
            'monto_modo', 'importe', 'frecuencia', 'dias_generar_antes',
            'vigente_desde', 'vigente_hasta',
        ]), $programacion), $regla);

        return DB::transaction(function () use ($usuario, $regla, $datos) {
            $regla = Regla::whereKey($regla->id)->lockForUpdate()->firstOrFail();

            if (! $regla->porCompletar()) {
                return $regla;
            }

            $regla->update($this->camposDeLaRegla($datos) + [
                'estado' => 'activa',
                'version' => $regla->version + 1,
            ]);

            $this->guardarVersion($regla->fresh(), $usuario, 'Programación completada y regla activada.');
            $this->evento($regla, $usuario, 'regla_activada', [
                'cuando' => $this->calendario->enPalabras($regla->fresh()->calendario()),
            ]);

            return $regla->fresh();
        });
    }

    public function cancelar(User $usuario, Regla $regla, string $motivo): Regla
    {
        return $this->cambiarEstado($usuario, $regla, 'cancelada', $motivo, [
            'activa' => true, 'pausada' => true,
        ], 'Esta regla ya está cancelada.');
    }

    /**
     * Ocupa un período SIN generar obligación. Queda con motivo y autor, y la
     * generación automática ya no vuelve a mirarlo.
     */
    public function omitir(User $usuario, Regla $regla, string $periodo, string $motivo): Ocurrencia
    {
        $this->autorizar($usuario);

        Validator::make(['motivo' => trim($motivo), 'periodo' => $periodo], [
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'periodo' => ['required', 'string', 'max:20'],
        ], [
            'motivo.required' => 'Explicá por qué se omite este período: sin motivo, dentro de un mes nadie sabrá si fue una decisión o un olvido.',
        ])->validate();

        try {
            return DB::transaction(function () use ($usuario, $regla, $periodo, $motivo) {
                $regla = Regla::whereKey($regla->id)->lockForUpdate()->firstOrFail();

                $existente = Ocurrencia::where('regla_id', $regla->id)->where('periodo', $periodo)->first();

                if ($existente !== null) {
                    if ($existente->omitida()) {
                        return $existente; // repetir la omisión no cambia nada
                    }

                    throw ValidationException::withMessages([
                        'periodo' => 'Este período ya generó una obligación. Omitirlo ahora no la borraría: si no corresponde, anulá esa obligación con su motivo.',
                    ]);
                }

                $vence = $this->venceDelPeriodo($regla, $periodo);

                $ocurrencia = Ocurrencia::create([
                    'regla_id' => $regla->id,
                    'periodo' => $periodo,
                    'vence' => $vence?->toDateString(),
                    'estado' => 'omitida',
                    'gasto_id' => null,
                    'version_regla' => $regla->version,
                    'motivo' => trim($motivo),
                    'registrado_por' => $usuario->id,
                    'created_at' => now(),
                ]);

                $this->evento($regla, $usuario, 'periodo_omitido', [
                    'periodo' => $periodo,
                    'motivo' => trim($motivo),
                ]);

                return $ocurrencia;
            });
        } catch (QueryException $e) {
            if (str_contains(mb_strtolower($e->getMessage()), 'unique')) {
                throw ValidationException::withMessages([
                    'periodo' => 'Otro usuario acaba de resolver este período. Recargá la pantalla para ver cómo quedó.',
                ]);
            }

            throw $e;
        }
    }

    /**
     * Períodos que la regla producirá en los próximos días y todavía no están
     * resueltos. Alimenta la pantalla: omitir un período exige poder verlo antes.
     *
     * @return array<int, array{periodo: string, vence: string, resuelto: ?string}>
     */
    public function proximosPeriodos(Regla $regla, CarbonImmutable $hoy, int $cuantos = 6): array
    {
        $ventana = match ($regla->frecuencia) {
            'semanal' => $hoy->addWeeks($cuantos),
            'quincenal' => $hoy->addMonths((int) ceil($cuantos / 2)),
            'anual' => $hoy->addYears($cuantos),
            default => $hoy->addMonths($cuantos),
        };

        $hasta = $regla->vigente_hasta !== null
            ? min($ventana, CarbonImmutable::parse($regla->vigente_hasta->toDateString()))
            : $ventana;

        $resueltos = Ocurrencia::where('regla_id', $regla->id)->pluck('estado', 'periodo');

        return array_map(fn (array $p) => [
            'periodo' => $p['periodo'],
            'vence' => $p['vence']->toDateString(),
            'resuelto' => $resueltos[$p['periodo']] ?? null,
        ], $this->calendario->periodos($regla->calendario(), $hoy, $hasta));
    }

    /** @param  array<string, bool>  $desde */
    private function cambiarEstado(User $usuario, Regla $regla, string $nuevo, string $motivo, array $desde, string $error): Regla
    {
        $this->autorizar($usuario);

        Validator::make(['motivo' => trim($motivo)], [
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Escribí el motivo: este cambio se audita.',
        ])->validate();

        return DB::transaction(function () use ($usuario, $regla, $nuevo, $motivo, $desde, $error) {
            $regla = Regla::whereKey($regla->id)->lockForUpdate()->firstOrFail();

            if (! ($desde[$regla->estado] ?? false)) {
                throw ValidationException::withMessages(['estado' => $error]);
            }

            $campos = ['estado' => $nuevo];

            if ($nuevo === 'pausada') {
                $campos += ['pausada_at' => now(), 'pausada_por' => $usuario->id, 'motivo_pausa' => trim($motivo)];
            }

            if ($nuevo === 'cancelada') {
                $campos += ['cancelada_at' => now(), 'cancelada_por' => $usuario->id, 'motivo_cancelacion' => trim($motivo)];
            }

            if ($nuevo === 'activa') {
                // Reanudar limpia la marca de pausa pero NO recupera nada por su cuenta:
                // la generación siguiente decide, con su ventana, qué períodos faltan.
                $campos += ['pausada_at' => null, 'pausada_por' => null, 'motivo_pausa' => null];
            }

            $regla->update($campos);

            $this->evento($regla, $usuario, 'regla_'.match ($nuevo) {
                'pausada' => 'pausada',
                'cancelada' => 'cancelada',
                default => 'reanudada',
            }, ['motivo' => trim($motivo)]);

            return $regla->fresh();
        });
    }

    private function venceDelPeriodo(Regla $regla, string $periodo): ?CarbonImmutable
    {
        $hoy = CarbonImmutable::now();
        $rango = $this->calendario->periodos($regla->calendario(), $hoy->subYear(), $hoy->addYears(2));

        foreach ($rango as $candidato) {
            if ($candidato['periodo'] === $periodo) {
                return $candidato['vence'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function camposDeLaRegla(array $datos): array
    {
        return Arr::only($datos, [
            'nombre', 'beneficiario', 'concepto', 'categoria', 'ambito', 'persona', 'naturaleza',
            'moneda', 'documentacion', 'observaciones', 'responsable_id', 'monto_modo',
            'frecuencia', 'dia_semana', 'dia_mes', 'dia_mes_2', 'mes',
            'dias_generar_antes', 'vigente_desde', 'vigente_hasta',
        ]) + [
            // Monto variable NO guarda importe. Dejar un número «de referencia» acá
            // terminaría, tarde o temprano, generando deuda con esa cifra.
            'importe' => $datos['monto_modo'] === 'fijo'
                ? Dinero::decimal(Dinero::centavos((string) $datos['importe']))
                : null,
        ];
    }

    private function guardarVersion(Regla $regla, User $usuario, string $motivo): void
    {
        ReglaVersion::create([
            'regla_id' => $regla->id,
            'version' => $regla->version,
            'datos' => $regla->plantilla() + $regla->calendario() + [
                'dias_generar_antes' => $regla->dias_generar_antes,
                'vigente_desde' => $regla->vigente_desde?->toDateString(),
                'vigente_hasta' => $regla->vigente_hasta?->toDateString(),
            ],
            'vigente_desde' => now()->toDateString(),
            'motivo' => $motivo,
            'registrado_por' => $usuario->id,
            'created_at' => now(),
        ]);
    }

    private function evento(Regla $regla, User $usuario, string $accion, array $datos): void
    {
        DB::table('gastos_eventos')->insert([
            'regla_id' => $regla->id,
            'usuario_id' => $usuario->id,
            'accion' => $accion,
            'datos' => json_encode($datos, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function autorizar(User $usuario): void
    {
        abort_unless($usuario->activo && $usuario->can('gastos.recurrencias'), 403);
    }

    /** @return array<string, mixed> */
    /**
     * @param  bool  $incompleta  Si es true, el CUÁNDO deja de ser obligatorio. Todo lo
     *                            demás se sigue exigiendo igual: una regla a medio
     *                            llenar no es una regla a medio validar.
     */
    private function validar(User $usuario, array $datos, ?Regla $regla = null, bool $incompleta = false): array
    {
        $datos = $this->normalizarFechas($datos);
        $frecuencia = $datos['frecuencia'] ?? null;
        $exige = fn (bool $cuando) => $incompleta ? false : $cuando;

        $validados = Validator::make($datos, [
            'clave' => ['required', 'uuid'],
            'nombre' => ['required', 'string', 'max:200'],
            'beneficiario' => ['required', 'string', 'max:180'],
            'concepto' => ['required', 'string', 'max:200'],
            'categoria' => ['required', 'string', 'max:100'],
            'ambito' => ['required', Rule::in(array_keys(Gasto::AMBITOS))],
            'persona' => ['nullable', 'string', 'max:180'],
            'naturaleza' => ['required', Rule::in(array_keys(Gasto::NATURALEZAS))],
            'moneda' => ['required', Rule::in(config('gastos.monedas'))],
            'documentacion' => ['required', Rule::in(array_keys(Gasto::DOCUMENTACION))],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'responsable_id' => ['required', 'integer', Rule::exists('users', 'id')],

            'monto_modo' => ['required', Rule::in(array_keys(Regla::MONTO_MODOS))],
            'importe' => [
                Rule::requiredIf(fn () => ($datos['monto_modo'] ?? null) === 'fijo'),
                'exclude_unless:monto_modo,fijo',
                'regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'gt:0',
            ],

            'frecuencia' => ['required', Rule::in(array_keys(CalendarioRecurrencia::FRECUENCIAS))],
            'dia_semana' => [Rule::requiredIf($exige($frecuencia === 'semanal')), 'nullable', 'integer', 'between:1,7'],
            'dia_mes' => [Rule::requiredIf($exige(in_array($frecuencia, ['quincenal', 'mensual', 'anual'], true))), 'nullable', 'integer', 'between:1,31'],
            'dia_mes_2' => [Rule::requiredIf($exige($frecuencia === 'quincenal')), 'nullable', 'integer', 'between:1,31', 'gt:dia_mes'],
            'mes' => [Rule::requiredIf($exige($frecuencia === 'anual')), 'nullable', 'integer', 'between:1,12'],

            'dias_generar_antes' => ['required', 'integer', 'between:0,60'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigente_desde'],
        ], [
            'dia_mes_2.gt' => 'La segunda fecha del mes tiene que ser posterior a la primera.',
            'importe.required' => 'Un monto fijo necesita el importe. Si cambia cada período, elegí «variable».',
            'vigente_hasta.after_or_equal' => 'La regla no puede terminar antes de empezar.',
        ])->validate();

        // Ámbito personal: mismo candado que en el alta manual. No alcanza con
        // `gastos.recurrencias`, porque una regla personal produce gastos personales.
        if ($validados['ambito'] === 'personal' && ! $usuario->can('gastos.personales')) {
            throw ValidationException::withMessages([
                'ambito' => 'No tenés alcance sobre gastos personales.',
            ]);
        }

        // Cambiar la frecuencia de una regla que ya generó exige fecha de corte: los
        // períodos viejos usan otra clave lógica y quedarían mezclados con los nuevos.
        if ($regla !== null && $validados['frecuencia'] !== $regla->frecuencia
            && Ocurrencia::where('regla_id', $regla->id)->exists()) {
            $ultima = Ocurrencia::where('regla_id', $regla->id)->max('vence');

            if ($ultima !== null && $validados['vigente_desde'] <= $ultima) {
                throw ValidationException::withMessages([
                    'frecuencia' => 'Esta regla ya generó obligaciones hasta el '.$ultima
                        .'. Para cambiar la frecuencia, poné «vigente desde» después de esa fecha: '
                        .'así los períodos viejos y los nuevos no se solapan.',
                ]);
            }
        }

        $validados['persona'] = $validados['ambito'] === 'personal' ? ($validados['persona'] ?? null) : null;

        return $validados;
    }

    /**
     * Las fechas siempre llegan al validador como texto `Y-m-d`.
     *
     * NO ES COSMÉTICA, Y LA VALIDACIÓN SIGUE INTACTA. `date_format:Y-m-d` rechaza
     * cualquier cosa que no sea string o número, así que un Carbon —que es exactamente
     * lo que devuelve `Regla::only()` por el cast `date` del modelo— fallaba con «The
     * vigente hasta field must match the format Y-m-d» aunque la fecha guardada fuera
     * perfecta. Se veía solo al activar una regla CON fecha de fin: sin fecha de fin el
     * valor era null y la regla `nullable` lo dejaba pasar.
     *
     * Se normaliza acá, en la única puerta por la que entran todos los caminos, y no en
     * el que reportó el error: el formulario manda `<input type="date">` (ya `Y-m-d`) y
     * el servicio manda atributos del modelo (Carbon). El validador no tiene por qué
     * saber de cuál de los dos viene.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizarFechas(array $datos): array
    {
        foreach (['vigente_desde', 'vigente_hasta'] as $campo) {
            if (($datos[$campo] ?? null) instanceof \DateTimeInterface) {
                $datos[$campo] = $datos[$campo]->format('Y-m-d');
            }
        }

        return $datos;
    }
}
