<?php

namespace App\Services\Rutas;

use App\Enums\FuncionPersonalRuta;
use App\Enums\RolSistema;
use App\Models\PersonalRuta;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Un usuario con rol Vendedor tiene su ficha en Rutas, enlazada, sin cargarlo en otro lado
 * (el usuario pidió registrar al vendedor en UN solo lugar, 29/10/2026).
 *
 *  - Si ya hay una ficha enlazada a ese usuario, se usa.
 *  - Si no, se engancha una ficha SIN usuario cuyo nombre coincida: el nombre completo, o el
 *    primer nombre cuando la ficha se cargó solo con él («Marcelo»), siempre que sea UNA sola.
 *    Con dos candidatas no se adivina: se crea una ficha nueva.
 *  - Si no hay ninguna, se crea con el nombre del usuario.
 *
 * La ficha queda activa mientras el usuario esté activo y sea Vendedor; si deja de serlo, se
 * desactiva y se conserva (el historial de salidas no se pierde). Nunca se borra.
 */
class FichaVendedor
{
    public function sincronizar(User $usuario): ?PersonalRuta
    {
        $esVendedor = $usuario->hasRole(RolSistema::Vendedor->value);
        $ficha = PersonalRuta::where('user_id', $usuario->id)->first();

        if (! $esVendedor) {
            if ($ficha?->activo) {
                $ficha->update(['activo' => false]);
            }

            return $ficha;
        }

        $ficha ??= $this->sinUsuarioConElMismoNombre($usuario->name)
            ?? new PersonalRuta(['nombre' => Str::limit($usuario->name, 120, '')]);
        $ficha->fill(['user_id' => $usuario->id, 'activo' => (bool) $usuario->activo])->save();
        $ficha->funciones()->firstOrCreate(['funcion' => FuncionPersonalRuta::Vendedor->value]);

        return $ficha;
    }

    private function sinUsuarioConElMismoNombre(string $nombre): ?PersonalRuta
    {
        $norm = fn (?string $s) => Str::lower(Str::ascii(trim(preg_replace('/\s+/', ' ', (string) $s))));
        $completo = $norm($nombre);
        $primero = Str::before($completo.' ', ' ');

        $candidatas = PersonalRuta::whereNull('user_id')->get()
            ->filter(fn (PersonalRuta $p) => in_array($norm($p->nombre), [$completo, $primero], true));

        return $candidatas->count() === 1 ? $candidatas->first() : null;
    }
}
