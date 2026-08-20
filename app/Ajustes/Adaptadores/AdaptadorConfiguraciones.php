<?php

namespace App\Ajustes\Adaptadores;

use App\Models\Configuracion;

/**
 * Puente hacia la tabla `configuraciones` existente.
 *
 * POR QUÉ EXISTE Y POR QUÉ NO MIGRA NADA
 * ------------------------------------------------------------------
 * Las 8 claves actuales (correo.*, contabilidad.*, produccion.*, ppq.*) siguen
 * exactamente donde están. Moverlas a la tabla nueva significaría, el mismo día,
 * cambiar el sitio del que leen el job de correo, el observer de DTE, el
 * preflight de producción y tres controladores — sin ninguna ganancia inmediata.
 * La ganancia (tipos, validación, niveles, auditoría central) se obtiene igual
 * leyéndolas a través del resolver, y el traslado de datos queda para una fase
 * en la que sea el único cambio sobre la mesa.
 *
 * Este adaptador DELEGA en {@see Configuracion}, no la reimplementa: mientras la
 * clave viva en la tabla anterior hay UNA sola ruta de lectura y escritura, y por
 * tanto una sola caché. Duplicar la lectura para "mejorarla" habría creado dos
 * cachés que se desincronizan en cuanto un comando existente llame a
 * `Configuracion::set()` sin pasar por acá.
 *
 * CONSECUENCIA ACEPTADA: para las claves legacy sigue vigente la caché estática
 * de proceso de `Configuracion`, con su límite conocido en workers de vida larga
 * (ver docs/CENTRO_CONFIGURACION.md). Las claves que persisten en la tabla nueva
 * no lo tienen. La forma de quitarlo es migrar la clave, no envolverla.
 */
class AdaptadorConfiguraciones
{
    /** Valor almacenado, o null si la clave no existe o está vacía. */
    public function valor(string $clave): ?string
    {
        $valor = Configuracion::get($clave);

        // Una fila con valor NULL o cadena vacía se trata como AUSENCIA de
        // override: es lo que ya hacían los consumidores actuales al vaciar el
        // correo de contabilidad desde el formulario.
        return $valor === null || $valor === '' ? null : $valor;
    }

    public function existe(string $clave): bool
    {
        return $this->valor($clave) !== null;
    }

    public function guardar(string $clave, ?string $texto): void
    {
        // `Configuracion::set` mantiene su auditoría propia (lista blanca de
        // claves auditables) y refresca su caché estática. No se duplica acá:
        // la auditoría CENTRAL de la capa nueva se emite aparte, en AuditoriaAjustes.
        Configuracion::set($clave, $texto);
    }

    /** Quitar el override en la tabla anterior = dejar la clave sin valor. */
    public function eliminar(string $clave): void
    {
        Configuracion::set($clave, null);
    }
}
