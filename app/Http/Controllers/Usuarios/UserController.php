<?php

namespace App\Http\Controllers\Usuarios;

use App\Enums\RolSistema;
use App\Http\Controllers\Controller;
use App\Http\Requests\Usuarios\CambiarPasswordRequest;
use App\Http\Requests\Usuarios\StoreUserRequest;
use App\Http\Requests\Usuarios\UpdateUserRequest;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\AltaEmpleado;
use App\Services\Rutas\FichaVendedor;
use App\Services\Usuarios\CrearUsuario;
use App\Services\Usuarios\UltimoAdministrador;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $busqueda = trim((string) $request->input('q', ''));

        $usuarios = User::query()
            ->with($this->conPersonal() ? ['roles', 'planillaEmpleado'] : ['roles'])
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$busqueda}%")
                ->orWhere('email', 'like', "%{$busqueda}%")))
            ->when($this->conPersonal() && $request->boolean('sin_ficha'), fn ($q) => $q->whereDoesntHave('planillaEmpleado'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('usuarios.index', [
            'usuarios' => $usuarios, 'filtros' => ['q' => $busqueda, 'sin_ficha' => $request->boolean('sin_ficha')],
            'sinFicha' => $this->conPersonal() ? User::whereDoesntHave('planillaEmpleado')->count() : 0,
            'conPersonal' => $this->conPersonal(), 'verFichas' => $request->user()->can('planilla.ver'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('usuarios.form', [
            'usuario' => new User(['activo' => true]), 'roles' => RolSistema::opciones(), 'rolActual' => null,
            'ficha' => null, 'conPersonal' => $this->conPersonal(),
            // Todo usuario es de una persona de Personal: se elige, no se escribe el nombre.
            'personasSinUsuario' => $this->conPersonal()
                ? PlanillaEmpleado::whereNull('user_id')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
                : collect(),
            'personaElegida' => $request->integer('persona') ?: null,
        ]);
    }

    /**
     * Con Personal, el usuario sale de la ficha de una persona: es lo mismo que «Agregar
     * acceso» en la ficha, con el mismo servicio. Sin Planilla instalada (por ejemplo, al
     * empezar) queda la cuenta suelta de siempre, para no dejar a nadie sin poder entrar.
     */
    public function store(Request $request, CrearUsuario $crear, AltaEmpleado $alta): RedirectResponse
    {
        $this->authorize('create', User::class);

        if ($this->conPersonal()) {
            $id = $request->validate([
                'planilla_empleado_id' => ['required', 'integer', 'exists:planilla_empleados,id'],
            ], ['planilla_empleado_id.required' => 'Elegí la persona.'])['planilla_empleado_id'];
            $empleado = PlanillaEmpleado::findOrFail($id);
            if ($empleado->user_id !== null) {
                throw ValidationException::withMessages(['planilla_empleado_id' => 'Esa persona ya tiene usuario.']);
            }
            $usuario = $alta->completar($request->user(), $empleado, ['usa_sistema' => true] + $request->only([
                'rol', 'email', 'password', 'password_confirmation',
            ]))->usuario;
        } else {
            $request->merge(['activo' => $request->boolean('activo')]);
            $usuario = $crear->crear($request->validate(StoreUserRequest::reglasUsuario()));
        }

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuario creado correctamente.');
    }

    public function show(Request $request, User $usuario): View
    {
        $this->authorize('view', $usuario);

        $actividades = $usuario->activities()->with('causer')->latest()->limit(30)->get();
        $ficha = $this->fichaDe($usuario);
        $puedeEnlazar = $this->conPersonal() && $ficha === null && $request->user()->can('planilla.gestionar');

        return view('usuarios.show', [
            'usuario' => $usuario->load('roles'), 'actividades' => $actividades,
            'conPersonal' => $this->conPersonal(), 'ficha' => $ficha,
            'verFicha' => $request->user()->can('planilla.ver'),
            // Quien está en Personal y todavía no tiene usuario.
            'fichasSinUsuario' => $puedeEnlazar
                ? PlanillaEmpleado::whereNull('user_id')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
                : null,
        ]);
    }

    /**
     * Enlaza esta cuenta con una persona de Personal que no tiene usuario. Es lo mismo
     * que elegirla en la ficha de la persona: mismo servicio, permisos y bitácora.
     */
    public function enlazarPersonal(Request $request, User $usuario, AltaEmpleado $alta): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $id = $request->validate([
            'planilla_empleado_id' => ['required', 'integer', 'exists:planilla_empleados,id'],
        ], ['planilla_empleado_id.required' => 'Elegí la persona.'])['planilla_empleado_id'];
        $empleado = PlanillaEmpleado::findOrFail($id);
        if ($empleado->user_id !== null) {
            throw ValidationException::withMessages(['planilla_empleado_id' => 'Esa persona ya tiene otro usuario.']);
        }

        $empleado = $alta->completar($request->user(), $empleado, ['usa_sistema' => true, 'user_id' => $usuario->id]);

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuario enlazado a '.$empleado->nombre.' en Personal.');
    }

    public function edit(User $usuario): View
    {
        $this->authorize('update', $usuario);

        return view('usuarios.form', [
            'usuario' => $usuario,
            'roles' => RolSistema::opciones(),
            'rolActual' => $usuario->roles->first()?->name,
            'ficha' => $this->fichaDe($usuario),
            'conPersonal' => $this->conPersonal(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $usuario, FichaVendedor $fichas): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $datos = $request->validated();
        $rolNuevo = $datos['rol'];
        $activoNuevo = (bool) $datos['activo'];

        // No dejar el sistema sin un administrador activo.
        $quitaAdmin = $rolNuevo !== 'administrador' || ! $activoNuevo;
        if ($quitaAdmin && UltimoAdministrador::es($usuario)) {
            return back()->withInput()->with('error', 'No se puede quitar el rol de administrador ni inactivar al último administrador activo.');
        }

        $rolAnterior = $usuario->roles->first()?->name;

        $usuario->update([
            // Un solo nombre: el de quien tiene ficha en Personal se corrige allá.
            'name' => $this->fichaDe($usuario)?->nombre ?? $datos['name'],
            'email' => $datos['email'],
            'activo' => $activoNuevo,
        ]);

        if ($rolNuevo !== $rolAnterior) {
            $usuario->syncRoles([$rolNuevo]);
            activity('usuario')->performedOn($usuario)->log('cambió el rol de '.($rolAnterior ?? '—').' a '.$rolNuevo);
        }
        $fichas->sincronizar($usuario);

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuario actualizado correctamente.');
    }

    public function toggleActivo(User $usuario, FichaVendedor $fichas): RedirectResponse
    {
        $this->authorize('update', $usuario);

        // Inactivar al último administrador activo dejaría el sistema sin admin.
        if ($usuario->activo && UltimoAdministrador::es($usuario)) {
            return back()->with('error', 'No se puede inactivar al último administrador activo.');
        }

        $usuario->update(['activo' => ! $usuario->activo]);
        $fichas->sincronizar($usuario);

        return back()->with('status', $usuario->activo ? 'Usuario activado.' : 'Usuario inactivado.');
    }

    public function editPassword(User $usuario): View
    {
        $this->authorize('update', $usuario);

        return view('usuarios.password', ['usuario' => $usuario]);
    }

    public function updatePassword(CambiarPasswordRequest $request, User $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $usuario->update(['password' => Hash::make($request->validated()['password'])]);
        activity('usuario')->performedOn($usuario)->log('cambió la contraseña');

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Contraseña actualizada correctamente.');
    }

    public function destroy(User $usuario): RedirectResponse
    {
        $this->authorize('delete', $usuario); // bloquea auto-eliminación

        if (UltimoAdministrador::es($usuario)) {
            return back()->with('error', 'No se puede eliminar al último administrador activo.');
        }

        $usuario->delete();

        return redirect()->route('usuarios.index')->with('status', 'Usuario eliminado.');
    }

    /** Personal enlaza cuentas solo con Planilla encendida e instalada. */
    private function conPersonal(): bool
    {
        return (bool) config('planilla.enabled') && Schema::hasTable('planilla_empleados');
    }

    /** La persona de esta cuenta. Sin la tabla (Planilla sin instalar) no hay ninguna. */
    private function fichaDe(User $usuario): ?PlanillaEmpleado
    {
        return Schema::hasTable('planilla_empleados') ? $usuario->planillaEmpleado : null;
    }
}
