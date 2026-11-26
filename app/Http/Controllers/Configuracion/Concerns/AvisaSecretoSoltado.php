<?php

namespace App\Http\Controllers\Configuracion\Concerns;

use App\Ajustes\Ajustes;
use App\Ajustes\Definicion\DefinicionAjuste;

/**
 * Aviso de que un secreto dejó de usarse porque cambió la dirección adonde viaja.
 *
 * La regla vive en {@see Ajustes}: al cambiar la URL de la tienda, el servidor de correo
 * o el de un buzón, la clave guardada se borra y la del .env no sale hacia la dirección
 * nueva. Acá solo se le dice a quien guardó qué dejó de funcionar y dónde se carga de
 * nuevo. Se mira antes y después de guardar: si la clave sigue sirviendo (por ejemplo,
 * se volvió a la dirección del .env y la del .env vale para ella), no hay nada que decir.
 */
trait AvisaSecretoSoltado
{
    /**
     * Para la confirmación, ANTES de guardar: una frase por cada secreto que dejaría de
     * usarse con estos valores, o nada. Así se sabe antes de confirmar que habrá que
     * cargar otra vez una contraseña de aplicación que quizás se vio una sola vez.
     *
     * @param  array<string, mixed>  $valores  clave de la dirección ⇒ valor propuesto (vacío: vuelve al .env)
     */
    protected function avisoAntesDeConfirmar(array $valores): string
    {
        $ajustes = app(Ajustes::class);
        $aviso = '';

        foreach ($ajustes->secretosQueSeSueltan($valores) as $clave => $secreto) {
            $direccion = $ajustes->catalogo()->definicion($clave)->etiqueta;
            $aviso .= " Al cambiar «{$direccion}», «{$secreto->etiqueta}» deja de usarse: se cargó para el servidor anterior. "
                .'Después de guardar, cargala de nuevo con el botón «Configurar».';
        }

        return $aviso;
    }

    /**
     * Secretos que hoy se usan y viajan a alguna de estas direcciones. Se pide ANTES de
     * guardar.
     *
     * @param  list<string>  $claves  claves del registry que se van a escribir o quitar
     * @return array<string, DefinicionAjuste> clave de la dirección ⇒ su secreto
     */
    protected function secretosEnUso(array $claves): array
    {
        $ajustes = app(Ajustes::class);
        $enUso = [];

        foreach ($claves as $clave) {
            $secreto = $ajustes->catalogo()->secretoDeServidor($clave);

            if ($secreto !== null && $ajustes->estaConfigurado($secreto->clave)) {
                $enUso[$clave] = $secreto;
            }
        }

        return $enUso;
    }

    /**
     * DESPUÉS de guardar: una frase por cada secreto que dejó de usarse, o nada.
     *
     * @param  array<string, DefinicionAjuste>  $enUso  lo que devolvió {@see secretosEnUso()}
     */
    protected function avisoSecretosSoltados(array $enUso): string
    {
        $ajustes = app(Ajustes::class);
        $aviso = '';

        foreach ($enUso as $clave => $secreto) {
            if (! $ajustes->estaConfigurado($secreto->clave)) {
                $direccion = $ajustes->catalogo()->definicion($clave)->etiqueta;
                $aviso .= " «{$secreto->etiqueta}» dejó de usarse porque cambió «{$direccion}». "
                    .'Cargá el valor de nuevo con el botón «Configurar», más abajo en esta pantalla.';
            }
        }

        return $aviso;
    }
}
