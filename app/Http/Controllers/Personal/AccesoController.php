<?php

namespace App\Http\Controllers\Personal;

use App\Enums\RolSistema;
use App\Http\Controllers\Controller;
use App\Http\Requests\Usuarios\CambiarPasswordRequest;
use App\Http\Requests\Usuarios\StoreUserRequest;
use App\Http\Requests\Usuarios\UpdateUserRequest;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Rutas\FichaVendedor;
use App\Services\Usuarios\CrearUsuario;
use App\Services\Usuarios\UltimoAdministrador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Acceso al sistema, dentro de Personal (antes era el módulo «Usuarios»).
 *
 * A una persona del personal se le da acceso en su ficha («Agregar acceso o módulos», con
 * AltaEmpleado), y ahí mismo se le cambia el rol, el correo, la contraseña o se le desactiva:
 * las acciones de este controlador, dibujadas en `personal/accesos/_acciones`. Acá queda la
 * lista de quién entra y lo que no tiene ficha: la cuenta externa (la contadora, que no es
 * empleada) y, sin Planilla instalada, la cuenta suelta de siempre.
 *
 * El permiso es el mismo de antes: `usuarios.gestionar` (middleware de la ruta y UserPolicy).
 */
class AccesoController extends Controller
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
            ->when($this->conPersonal() && $request->boolean('sin_ficha'), fn ($q) => $this->sinFicha($q))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('personal.accesos.index', [
            'usuarios' => $usuarios, 'filtros' => ['q' => $busqueda, 'sin_ficha' => $request->boolean('sin_ficha')],
            'sinFicha' => $this->conPersonal() ? $this->sinFicha(User::query())->count() : 0,
            'conPersonal' => $this->conPersonal(), 'verFichas' => $request->user()->can('planilla.ver'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('personal.accesos.nuevo', ['conPersonal' => $this->conPersonal()]);
    }

    /**
     * Con Personal, acá solo se crea la cuenta externa (la contadora, que no es empleada),
     * solo con rol Contabilidad: el acceso de una persona del personal se da en su ficha.
     * Sin Planilla instalada (por ejemplo, al empezar) queda la cuenta suelta de siempre,
     * para no dejar a nadie sin poder entrar.
     */
    public function store(Request $request, CrearUsuario $crear): RedirectResponse
    {
        $this->authorize('create', User::class);

        if ($this->conPersonal()) {
            // Se pide a propósito: nadie crea una cuenta suelta pensando que es de un empleado.
            if (! $request->boolean('cuenta_externa')) {
                throw ValidationException::withMessages(['cuenta_externa' => 'El acceso de una persona del personal se da en su ficha, en «Agregar acceso o módulos». Acá solo se crea la cuenta externa.']);
            }
            $request->merge(['activo' => true]);
            $reglas = ['rol' => ['required', Rule::in([RolSistema::Contabilidad->value])]] + StoreUserRequest::reglasUsuario();
            $datos = $request->validate($reglas, ['rol.in' => 'Una cuenta externa solo puede tener el rol Contabilidad.']);
            // Quien ya está en Personal no se carga otra vez: su acceso se da en su ficha.
            if (PlanillaEmpleado::whereRaw('lower(nombre) = ?', [mb_strtolower(trim($datos['name']))])->exists()) {
                throw ValidationException::withMessages(['name' => 'Esa persona ya está en Personal: dale el acceso desde su ficha, en «Agregar acceso o módulos».']);
            }
            $usuario = $crear->crear(['cuenta_externa' => true] + $datos);
        } else {
            $request->merge(['activo' => $request->boolean('activo')]);
            $usuario = $crear->crear($request->validate(StoreUserRequest::reglasUsuario()));
        }

        return redirect()->route('personal.accesos.show', $usuario)->with('status', 'Acceso de '.$usuario->name.' creado.');
    }

    /** Quien tiene ficha se ve en su ficha; acá quedan las cuentas sin ficha. */
    public function show(Request $request, User $usuario): View|RedirectResponse
    {
        $this->authorize('view', $usuario);

        $destino = $this->destino($request, $usuario);
        if ($destino !== route('personal.accesos.show', $usuario)) {
            return redirect()->to($destino);
        }

        return view('personal.accesos.show', [
            'usuario' => $usuario->load('roles'), 'ficha' => $this->fichaDe($usuario), 'conPersonal' => $this->conPersonal(),
            'actividades' => $usuario->activities()->with('causer')->latest()->limit(30)->get(),
        ]);
    }

    /** Las direcciones viejas de Usuarios (/usuarios/{id}, /edit, /password) llevan al lugar nuevo. */
    public function redirigir(Request $request, User $usuario): RedirectResponse
    {
        return redirect()->to($this->destino($request, $usuario), 301);
    }

    /** Rol, correo y, si no tiene ficha, nombre. El nombre de quien está en Personal se corrige en su ficha. */
    public function update(UpdateUserRequest $request, User $usuario, FichaVendedor $fichas): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $datos = $request->validated();
        $rolNuevo = $datos['rol'];
        $ficha = $this->fichaDe($usuario);

        // No dejar el sistema sin un administrador activo.
        if ($rolNuevo !== RolSistema::Administrador->value && UltimoAdministrador::es($usuario)) {
            // Va con los errores del acceso, junto a sus botones (en la ficha, `error` es de Asistencia).
            return redirect()->to($this->destino($request, $usuario))
                ->withErrors(['rol' => 'No se puede quitar el rol de administrador al último administrador activo.'], 'acceso');
        }

        if ($usuario->cuenta_externa && $rolNuevo !== RolSistema::Contabilidad->value) {
            throw ValidationException::withMessages(['rol' => 'Una cuenta externa solo puede tener el rol Contabilidad.'])->errorBag('acceso');
        }
        if ($ficha === null && blank($datos['name'] ?? null)) {
            throw ValidationException::withMessages(['name' => 'Escribí el nombre.'])->errorBag('acceso');
        }

        $rolAnterior = $usuario->roles->first()?->name;

        $usuario->update([
            // Un solo nombre: el de quien tiene ficha en Personal se corrige allá.
            'name' => $ficha?->nombre ?? $datos['name'],
            'email' => $datos['email'],
        ]);

        if ($rolNuevo !== $rolAnterior) {
            $usuario->syncRoles([$rolNuevo]);
            activity('usuario')->performedOn($usuario)->log('cambió el rol de '.($rolAnterior ?? '—').' a '.$rolNuevo);
        }
        $fichas->sincronizar($usuario);

        return redirect()->to($this->destino($request, $usuario))->with('status', 'Acceso de '.$usuario->name.' actualizado.');
    }

    public function toggleActivo(Request $request, User $usuario, FichaVendedor $fichas): RedirectResponse
    {
        $this->authorize('update', $usuario);

        // Desactivar al último administrador activo dejaría el sistema sin admin.
        if ($usuario->activo && UltimoAdministrador::es($usuario)) {
            return redirect()->to($this->destino($request, $usuario))
                ->withErrors(['activo' => 'No se puede desactivar al último administrador activo.'], 'acceso');
        }

        $usuario->update(['activo' => ! $usuario->activo]);
        $fichas->sincronizar($usuario);

        return redirect()->to($this->destino($request, $usuario))
            ->with('status', $usuario->activo ? 'Acceso de '.$usuario->name.' activado.' : 'Acceso de '.$usuario->name.' desactivado: ya no puede entrar.');
    }

    public function updatePassword(CambiarPasswordRequest $request, User $usuario): RedirectResponse
    {
        $this->authorize('update', $usuario);

        $usuario->update(['password' => Hash::make($request->validated()['password'])]);
        activity('usuario')->performedOn($usuario)->log('cambió la contraseña');

        return redirect()->to($this->destino($request, $usuario))->with('status', 'Contraseña de '.$usuario->name.' cambiada.');
    }

    public function destroy(User $usuario): RedirectResponse
    {
        $this->authorize('delete', $usuario); // bloquea auto-eliminación

        if (UltimoAdministrador::es($usuario)) {
            return back()->with('error', 'No se puede eliminar al último administrador activo.');
        }

        $usuario->delete();

        return redirect()->route('personal.accesos.index')->with('status', 'Cuenta eliminada.');
    }

    /** Personal enlaza cuentas solo con Planilla encendida e instalada. */
    private function conPersonal(): bool
    {
        return PlanillaEmpleado::enUso();
    }

    /** Cuentas a las que les falta su persona en Personal: no cuentan las externas. */
    private function sinFicha(Builder $consulta): Builder
    {
        return $consulta->whereDoesntHave('planillaEmpleado')->where('cuenta_externa', false);
    }

    /** La persona de esta cuenta. Sin la tabla (Planilla sin instalar) no hay ninguna. */
    private function fichaDe(User $usuario): ?PlanillaEmpleado
    {
        return Schema::hasTable('planilla_empleados') ? $usuario->planillaEmpleado : null;
    }

    /** Dónde se ve esta cuenta: la ficha de su persona o, si no tiene (o no se alcanza), su página de acceso. */
    private function destino(Request $request, User $usuario): string
    {
        $ficha = $this->fichaDe($usuario);

        return $ficha && $this->conPersonal() && $request->user()->can('planilla.ver')
            ? route('personal.empleados.show', 'p'.$ficha->id)
            : route('personal.accesos.show', $usuario);
    }
}
