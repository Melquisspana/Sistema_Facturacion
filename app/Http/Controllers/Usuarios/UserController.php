<?php

namespace App\Http\Controllers\Usuarios;

use App\Enums\RolSistema;
use App\Http\Controllers\Controller;
use App\Http\Requests\Usuarios\CambiarPasswordRequest;
use App\Http\Requests\Usuarios\StoreUserRequest;
use App\Http\Requests\Usuarios\UpdateUserRequest;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $busqueda = trim((string) $request->input('q', ''));

        $usuarios = User::query()
            ->with('roles')
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$busqueda}%")
                ->orWhere('email', 'like', "%{$busqueda}%")))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('usuarios.index', ['usuarios' => $usuarios, 'filtros' => ['q' => $busqueda]]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('usuarios.form', ['usuario' => new User(['activo' => true]), 'roles' => RolSistema::opciones(), 'rolActual' => null]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $datos = $request->validated();
        $usuario = User::create([
            'name' => $datos['name'],
            'email' => $datos['email'],
            'password' => Hash::make($datos['password']),
            'activo' => $datos['activo'],
            'email_verified_at' => now(),
        ]);

        $usuario->syncRoles([$datos['rol']]);
        activity('usuario')->performedOn($usuario)->log('asignó el rol '.$datos['rol']);

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuario creado correctamente.');
    }

    public function show(User $usuario): View
    {
        $this->authorize('view', $usuario);

        $actividades = $usuario->activities()->with('causer')->latest()->limit(30)->get();

        return view('usuarios.show', ['usuario' => $usuario->load('roles'), 'actividades' => $actividades]);
    }

    public function edit(User $usuario): View
    {
        $this->authorize('update', $usuario);

        return view('usuarios.form', [
            'usuario' => $usuario,
            'roles' => RolSistema::opciones(),
            'rolActual' => $usuario->roles->first()?->name,
        ]);
    }

    public function update(UpdateUserRequest $request, User $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $datos = $request->validated();
        $rolNuevo = $datos['rol'];
        $activoNuevo = (bool) $datos['activo'];

        // No dejar el sistema sin un administrador activo.
        $quitaAdmin = $rolNuevo !== 'administrador' || ! $activoNuevo;
        if ($quitaAdmin && $this->esUltimoAdminActivo($usuario)) {
            return back()->withInput()->with('error', 'No se puede quitar el rol de administrador ni inactivar al último administrador activo.');
        }

        $rolAnterior = $usuario->roles->first()?->name;

        $usuario->update([
            'name' => $datos['name'],
            'email' => $datos['email'],
            'activo' => $activoNuevo,
        ]);

        if ($rolNuevo !== $rolAnterior) {
            $usuario->syncRoles([$rolNuevo]);
            activity('usuario')->performedOn($usuario)->log('cambió el rol de '.($rolAnterior ?? '—').' a '.$rolNuevo);
        }

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuario actualizado correctamente.');
    }

    public function toggleActivo(User $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        // Inactivar al último administrador activo dejaría el sistema sin admin.
        if ($usuario->activo && $this->esUltimoAdminActivo($usuario)) {
            return back()->with('error', 'No se puede inactivar al último administrador activo.');
        }

        $usuario->update(['activo' => ! $usuario->activo]);

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

        if ($this->esUltimoAdminActivo($usuario)) {
            return back()->with('error', 'No se puede eliminar al último administrador activo.');
        }

        $usuario->delete();

        return redirect()->route('usuarios.index')->with('status', 'Usuario eliminado.');
    }

    /** ¿Es este el único administrador activo del sistema? */
    private function esUltimoAdminActivo(User $usuario): bool
    {
        if (! $usuario->activo || ! $usuario->hasRole('administrador')) {
            return false;
        }

        return ! User::role('administrador')
            ->where('activo', true)
            ->where('id', '!=', $usuario->id)
            ->exists();
    }
}
