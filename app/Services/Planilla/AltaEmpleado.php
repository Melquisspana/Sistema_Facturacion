<?php

namespace App\Services\Planilla;

use App\Enums\RolSistema;
use App\Http\Requests\Usuarios\StoreUserRequest;
use App\Models\Asistencia\AsistenciaEmpleado;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Usuarios\CrearUsuario;
use App\Support\Dui;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AltaEmpleado
{
    public function coincidencias(string $nombres, string $apellidos): array
    {
        $primero = fn (string $s) => Str::before(Str::lower(Str::ascii(trim($s))).' ', ' ');
        $nombre = $primero($nombres);
        $apellido = $primero($apellidos);
        if ($nombre === '' || $apellido === '') {
            return [];
        }
        $coincidencias = [];
        foreach (['asistencia_empleado_id' => AsistenciaEmpleado::class, 'personal_ruta_id' => PersonalRuta::class, 'user_id' => User::class, 'planilla_empleado_id' => PlanillaEmpleado::class] as $tipo => $modelo) {
            foreach ($modelo::all() as $persona) {
                $completo = match ($tipo) {
                    'asistencia_empleado_id' => trim($persona->nombres.' '.$persona->apellidos),
                    'user_id' => $persona->name,
                    default => $persona->nombre,
                };
                $partes = preg_split('/\s+/', trim($completo));
                $mismoApellido = $tipo === 'asistencia_empleado_id'
                    ? $primero((string) $persona->apellidos) === $apellido
                    : collect(array_slice($partes, 1))->contains(fn ($p) => $primero($p) === $apellido);
                if ($primero($completo) === $nombre && ($mismoApellido || ($tipo === 'personal_ruta_id' && count($partes) === 1))) {
                    $coincidencias[] = ['tipo' => $tipo, 'id' => $persona->id, 'nombre' => $completo];
                }
            }
        }

        return $coincidencias;
    }

    public function registrar(User $actor, array $datos): PlanillaEmpleado
    {
        abort_unless($actor->activo && $actor->can('planilla.gestionar'), 403);
        // Autorizar antes de validar o escribir; Vendedor implica operar en Rutas.
        $vendedorNuevo = filter_var($datos['usa_sistema'] ?? false, FILTER_VALIDATE_BOOLEAN) && ($datos['rol'] ?? null) === RolSistema::Vendedor->value;
        $usuarioId = filter_var($datos['user_id'] ?? null, FILTER_VALIDATE_INT);
        $vendedorEnlazado = $usuarioId && User::find($usuarioId)?->hasRole(RolSistema::Vendedor->value);
        if ($vendedorNuevo || $vendedorEnlazado) {
            $datos['sale_rutas'] = true;
        }
        foreach (['usuarios.gestionar' => ['usa_sistema', 'user_id'], 'asistencia.gestionar' => ['marca', 'asistencia_empleado_id'], 'rutas.personal.gestionar' => ['sale_rutas', 'personal_ruta_id']] as $permiso => [$casilla, $enlace]) {
            if (! empty($datos[$casilla]) || filled($datos[$enlace] ?? null)) {
                abort_unless($actor->can($permiso), 403);
            }
        }
        if (filled($datos['sueldo_mensual'] ?? null)) {
            abort_unless($actor->can('planilla.salarios'), 403);
        }
        $reglas = [
            'nombres' => ['required', 'string', 'max:80'], 'apellidos' => ['required', 'string', 'max:80'],
            'documento_tipo' => ['required', 'string', Rule::in(array_keys(PlanillaEmpleado::DOCUMENTO_TIPOS))],
            'dui' => ['bail', 'required', 'string', 'max:20'], 'cargo' => ['nullable', 'string', 'max:120'],
            'alta_el' => ['nullable', 'date_format:Y-m-d'],
            'sueldo_mensual' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'con_descuentos_ley' => ['required', 'boolean'], 'notas' => ['nullable', 'string', 'max:2000'],
            'usa_sistema' => ['sometimes', 'boolean'], 'marca' => ['sometimes', 'boolean'],
            'sale_rutas' => ['sometimes', 'boolean'], 'es_otra_persona' => ['sometimes', 'boolean'],
            'sueldo_desde' => ['nullable', 'date_format:Y-m-d'], 'confirmar_bajo_minimo' => ['sometimes', 'boolean'],
            'confirmar_alta_futura' => ['sometimes', 'boolean'],
        ];
        foreach (['user_id' => 'users', 'asistencia_empleado_id' => 'asistencia_empleados', 'personal_ruta_id' => 'rutas_personal'] as $columna => $tabla) {
            $reglas[$columna] = ['nullable', 'integer', 'exists:'.$tabla.',id', Rule::unique('planilla_empleados', $columna)];
        }
        if (($datos['documento_tipo'] ?? null) === 'dui') {
            $reglas['dui'][] = function ($atributo, $valor, $fallar) {
                if (! Dui::esValido($valor)) {
                    $fallar('El DUI no es válido (formato o dígito verificador).');
                } elseif (PlanillaEmpleado::where('documento_tipo', 'dui')->whereNotNull('dui')->pluck('dui')
                    ->contains(fn ($dui) => Dui::normalizar($dui) === Dui::normalizar($valor))) {
                    // Las fichas anteriores pueden tener espacios: la migración no las rellena.
                    $fallar('Ya existe una persona en planilla con ese tipo y número de documento.');
                }
            };
            if (is_string($datos['dui'] ?? null) && Dui::esValido($datos['dui'])) {
                $datos['dui'] = Dui::normalizar($datos['dui']);
            }
        }
        $reglas['dui'][] = Rule::unique('planilla_empleados', 'dui')->where('documento_tipo', is_string($datos['documento_tipo'] ?? null) ? $datos['documento_tipo'] : 'dui');
        // Un enlace existente prevalece sobre la casilla: nunca crea otra cuenta.
        if (! empty($datos['usa_sistema']) && empty($datos['user_id'])) {
            $datos['name'] = trim((is_string($datos['nombres'] ?? null) ? $datos['nombres'] : '').' '.(is_string($datos['apellidos'] ?? null) ? $datos['apellidos'] : ''));
            $datos['activo'] = true;
            $reglas += StoreUserRequest::reglasUsuario();
        }
        $datos = Validator::make($datos, $reglas, ['dui.unique' => 'Ya existe una persona en planilla con ese tipo y número de documento.'])->validate();

        if (filled($datos['alta_el'] ?? null) && $datos['alta_el'] > now()->toDateString() && empty($datos['confirmar_alta_futura'])) {
            throw ValidationException::withMessages(['alta_el' => 'La fecha de ingreso es futura. Si es correcta, confirmala.']);
        }

        return DB::transaction(function () use ($actor, $datos) {
            if (empty($datos['es_otra_persona'])) {
                foreach ($this->coincidencias($datos['nombres'], $datos['apellidos']) as $coincidencia) {
                    if ((int) ($datos[$coincidencia['tipo']] ?? 0) !== $coincidencia['id']) {
                        throw ValidationException::withMessages(['coincidencias' => 'Hay personas con el mismo primer nombre y apellido. Enlazalas o confirmá que es otra persona.']);
                    }
                }
            }
            $asistencia = filled($datos['asistencia_empleado_id'] ?? null) ? AsistenciaEmpleado::lockForUpdate()->findOrFail($datos['asistencia_empleado_id']) : null;
            $ruta = filled($datos['personal_ruta_id'] ?? null) ? PersonalRuta::lockForUpdate()->findOrFail($datos['personal_ruta_id']) : null;
            $usuario = filled($datos['user_id'] ?? null) ? User::lockForUpdate()->findOrFail($datos['user_id']) : null;
            foreach (['asistencia_empleado_id' => $asistencia, 'personal_ruta_id' => $ruta, 'user_id' => $usuario] as $campo => $enlace) {
                if ($enlace && PlanillaEmpleado::where($campo, $enlace->id)->exists()) {
                    throw ValidationException::withMessages([$campo => 'Esta ficha ya está enlazada a otra persona en planilla.']);
                }
            }
            $empleado = PlanillaEmpleado::create([
                'clave' => (string) Str::uuid(), 'nombre' => trim($datos['nombres'].' '.$datos['apellidos']),
                'documento_tipo' => $datos['documento_tipo'], 'dui' => $datos['dui'],
                'con_descuentos_ley' => $datos['con_descuentos_ley'], 'cargo' => $datos['cargo'] ?? null,
                'alta_el' => $datos['alta_el'] ?? null, 'notas' => $datos['notas'] ?? null,
                'activo' => true, 'registrado_por' => $actor->id,
            ]);
            if (filled($datos['sueldo_mensual'] ?? null)) {
                // La línea de tiempo valida el mínimo. Si no pasa, la transacción deshace
                // todo y no queda una persona a medias.
                app(SueldoHabitual::class)->registrarMensual($actor, $empleado, [
                    'sueldo_mensual' => $datos['sueldo_mensual'],
                    'vigente_desde' => $datos['sueldo_desde'] ?? $datos['alta_el'] ?? now()->toDateString(),
                    'confirmar_bajo_minimo' => $datos['confirmar_bajo_minimo'] ?? false,
                ]);
            }
            if (! $asistencia && ! empty($datos['marca'])) {
                $asistencia = AsistenciaEmpleado::create(['nombres' => $datos['nombres'], 'apellidos' => $datos['apellidos'], 'fecha_ingreso' => $datos['alta_el'] ?? null, 'activo' => true]);
            }
            if (! $ruta && ! empty($datos['sale_rutas'])) {
                $ruta = PersonalRuta::create(['nombre' => Str::limit($empleado->nombre, 120, ''), 'activo' => true]);
                $ruta->funciones()->create(['funcion' => 'vendedor']);
            }
            if (! $usuario && ! empty($datos['usa_sistema'])) {
                // CrearUsuario enlaza antes de sincronizar, por eso comprobar primero.
                $this->compatible($ruta, 'user_id', null);
                $estadoRuta = $ruta?->activo;
                $usuario = app(CrearUsuario::class)->crear($datos, $ruta);
                if (filled($datos['personal_ruta_id'] ?? null)) {
                    $ruta->refresh()->update(['activo' => $estadoRuta]);
                }
            }
            $esVendedor = $usuario?->hasRole(RolSistema::Vendedor->value) ?? false;
            $this->compatible($asistencia, 'user_id', $usuario?->id);
            $this->compatible($ruta, 'asistencia_empleado_id', $asistencia?->id);
            $this->compatible($ruta, 'user_id', $esVendedor ? $usuario?->id : null);
            if ($asistencia && $usuario) {
                if (AsistenciaEmpleado::where('user_id', $usuario->id)->whereKeyNot($asistencia->id)->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'El usuario ya tiene otra ficha de asistencia.']);
                }
                $asistencia->update(['user_id' => $usuario->id]);
            }
            if ($ruta) {
                if ($asistencia && PersonalRuta::where('asistencia_empleado_id', $asistencia->id)->whereKeyNot($ruta->id)->exists()) {
                    throw ValidationException::withMessages(['asistencia_empleado_id' => 'Asistencia ya está enlazada a otra ficha de Rutas.']);
                }
                if ($esVendedor && PersonalRuta::where('user_id', $usuario->id)->whereKeyNot($ruta->id)->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'El usuario ya tiene otra ficha de Rutas.']);
                }
                $punteros = [];
                if ($asistencia) {
                    $punteros['asistencia_empleado_id'] = $asistencia->id;
                }
                if ($esVendedor) {
                    $punteros['user_id'] = $usuario->id;
                    $ruta->funciones()->firstOrCreate(['funcion' => 'vendedor']);
                }
                if ($punteros) {
                    $ruta->update($punteros);
                }
            }
            $empleado->update(['asistencia_empleado_id' => $asistencia?->id, 'personal_ruta_id' => $ruta?->id, 'user_id' => $usuario?->id]);
            activity('empleado')->performedOn($empleado)->causedBy($actor)->withProperties([
                'usa_sistema' => (bool) ($datos['usa_sistema'] ?? false), 'marca' => (bool) ($datos['marca'] ?? false),
                'sale_rutas' => (bool) ($datos['sale_rutas'] ?? false), 'con_descuentos_ley' => (bool) $datos['con_descuentos_ley'],
            ])->log('dio de alta al empleado');

            return $empleado->fresh();
        });
    }

    private function compatible(?Model $modelo, string $campo, ?int $id): void
    {
        if ($modelo?->$campo !== null && (int) $modelo->$campo !== $id) {
            throw ValidationException::withMessages([$campo => 'La ficha seleccionada ya apunta a otra persona. Revisá los enlaces.']);
        }
    }
}
