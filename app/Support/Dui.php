<?php

namespace App\Support;

class Dui
{
    public static function esValido(string $dui): bool
    {
        $dui = preg_replace('/[^0-9]/', '', $dui);

        if (strlen($dui) !== 9) {
            return false;
        }

        $digitos = str_split($dui);
        $verificador = (int) array_pop($digitos);

        $suma = 0;
        foreach ($digitos as $i => $digito) {
            $suma += ((int) $digito) * (9 - $i);
        }

        $calculado = 10 - ($suma % 10);
        if ($calculado === 10) {
            $calculado = 0;
        }

        return $calculado === $verificador;
    }

    public static function normalizar(string $dui): string
    {
        $digitos = preg_replace('/[^0-9]/', '', $dui);

        return substr($digitos, 0, 8).'-'.substr($digitos, 8, 1);
    }
}
