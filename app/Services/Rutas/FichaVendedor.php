<?php

namespace App\Services\Rutas;

use App\Enums\FuncionPersonalRuta;
use App\Enums\RolSistema;
use App\Models\PersonalRuta;
use App\Models\Planilla\PlanillaEmpleado;
use App\Models\User;
use App\Services\Planilla\NombreUnico;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Un usuario con rol Vendedor o Jefe de vendedores tiene su ficha en Rutas, enlazada, sin
 * cargarlo en otro lado (el usuario pidió registrar al vendedor en UN solo lugar,
 * 29/10/2026). El jefe también sale a ruta a veces (plan del tablero, decisión 1).
 *
 *  - Si ya hay una ficha enlazada a ese usuario, se usa.
 *  - Si la persona está en Personal y ya tiene ficha de Rutas, se usa esa.
 *  - Si no, se engancha una ficha SIN usuario cuyo nombre coincida: el nombre completo, o el
 *    primer nombre cuando la ficha se cargó solo con él («Hernán»), siempre que sea UNA sola.
 *    Por el primer nombre, solo si ningún otro usuario ni otra persona de Personal lo
 *    comparte: si no, podría ser la ficha de otra persona. Con dudas no se adivina: se
 *    crea una ficha nueva.
 *  - Si no hay ninguna, se crea con el nombre de la persona.
 *
 * La persona de Personal queda enlazada a la ficha que se usó: no hay una segunda. Y con un
 * solo nombre: el de su ficha de Personal o, si no está en Personal, el del usuario.
 *
 * La ficha queda activa mientras el usuario esté activo y tenga uno de esos roles; si no, se
 * desactiva y se conserva (el historial de salidas no se pierde). Nunca se borra.
 */
class FichaVendedor
{
    /** Roles que salen a ruta y por eso tienen ficha de campo. */
    public const ROLES = [RolSistema::Vendedor->value, RolSistema::JefeVendedores->value];

    public function sincronizar(User $usuario): ?PersonalRuta
    {
        $esVendedor = $usuario->hasAnyRole(self::ROLES);
        $ficha = PersonalRuta::where('user_id', $usuario->id)->first();

        if (! $esVendedor) {
            if ($ficha?->activo) {
                $ficha->update(['activo' => false]);
            }

            return $ficha;
        }

        $enPersonal = Schema::hasTable('planilla_empleados') ? PlanillaEmpleado::where('user_id', $usuario->id)->first() : null;
        $nombre = $enPersonal->nombre ?? $usuario->name;
        $yaEnlazada = $ficha !== null;
        $ficha ??= $enPersonal?->personalRuta?->user_id === null ? $enPersonal?->personalRuta : null;
        $ficha ??= $this->sinUsuarioConElMismoNombre($nombre, $usuario, $enPersonal)
            ?? new PersonalRuta(['nombre' => Str::limit($nombre, 120, '')]);
        $ficha->fill(['user_id' => $usuario->id, 'activo' => (bool) $usuario->activo])->save();
        $ficha->funciones()->firstOrCreate(['funcion' => FuncionPersonalRuta::Vendedor->value]);
        if ($enPersonal && $enPersonal->personal_ruta_id === null && ! PlanillaEmpleado::where('personal_ruta_id', $ficha->id)->exists()) {
            $enPersonal->update(['personal_ruta_id' => $ficha->id]);
        }

        // Un solo nombre: quien está en Personal lleva el de su ficha en todos lados; la
        // ficha de Rutas que se acaba de enganchar a un usuario sin ficha toma el del usuario.
        if ($enPersonal) {
            app(NombreUnico::class)->repartir($enPersonal->fresh());
        } elseif (! $yaEnlazada) {
            $ficha->update(['nombre' => Str::limit($nombre, 120, '')]);
        }

        return $ficha;
    }

    private function sinUsuarioConElMismoNombre(string $nombre, User $usuario, ?PlanillaEmpleado $enPersonal): ?PersonalRuta
    {
        $norm = fn (?string $s) => Str::lower(Str::ascii(trim(preg_replace('/\s+/', ' ', (string) $s))));
        $completo = $norm($nombre);
        $primero = Str::before($completo.' ', ' ');
        $conPersonal = Schema::hasTable('planilla_empleados');

        // La ficha de otra persona de Personal no se toma por el nombre.
        $candidatas = PersonalRuta::whereNull('user_id')
            ->when($conPersonal, fn ($q) => $q->whereNotIn('id', PlanillaEmpleado::whereNotNull('personal_ruta_id')->select('personal_ruta_id')))
            ->get();

        $porCompleto = $candidatas->filter(fn (PersonalRuta $p) => $norm($p->nombre) === $completo);
        if ($porCompleto->isNotEmpty() || $primero === $completo) {
            return $porCompleto->count() === 1 ? $porCompleto->first() : null;
        }

        // Solo por el primer nombre si nadie más lo comparte: «Hernán» puede ser otro Hernán.
        $otros = User::whereKeyNot($usuario->id)->pluck('name')
            ->merge($conPersonal ? PlanillaEmpleado::when($enPersonal, fn ($q) => $q->whereKeyNot($enPersonal->id))->pluck('nombre') : []);
        if ($otros->contains(fn ($otro) => Str::before($norm($otro).' ', ' ') === $primero)) {
            return null;
        }
        $porPrimero = $candidatas->filter(fn (PersonalRuta $p) => $norm($p->nombre) === $primero);

        return $porPrimero->count() === 1 ? $porPrimero->first() : null;
    }
}
