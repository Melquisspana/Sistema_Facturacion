<?php

namespace App\Facades;

use App\Ajustes\Ajustes as ServicioAjustes;
use App\Ajustes\CatalogoAjustes;
use App\Ajustes\Definicion\FuenteAjuste;
use App\Ajustes\Definicion\NivelConfirmacion;
use App\Ajustes\EstadoAjuste;
use App\Ajustes\ValorAjuste;
use Illuminate\Support\Facades\Facade;

/**
 * Fachada de la configuración administrable. Se eligió una fachada y no un
 * helper global (`configuracion()`) por dos motivos concretos:
 *
 *  - el proyecto no tiene ningún archivo de funciones en el autoload de composer,
 *    y agregar uno para esto obligaría a un `composer dump-autoload` en cada
 *    despliegue a cambio de nada;
 *  - `Ajustes::` es rastreable: buscar la clase encuentra todas las llamadas, y
 *    en pruebas se sustituye con `Ajustes::swap()` sin tocar estado global.
 *
 * @method static mixed get(string $clave)
 * @method static string|null texto(string $clave, ?string $porDefecto = null)
 * @method static bool bool(string $clave, bool $porDefecto = false)
 * @method static int|null entero(string $clave, ?int $porDefecto = null)
 * @method static string|null decimal(string $clave, ?string $porDefecto = null)
 * @method static array<int, string> lista(string $clave)
 * @method static string|null secretoParaRuntime(string $clave)
 * @method static ValorAjuste resolver(string $clave)
 * @method static FuenteAjuste fuente(string $clave)
 * @method static bool estaConfigurado(string $clave)
 * @method static EstadoAjuste estadoParaPantalla(string $clave)
 * @method static array<string, EstadoAjuste> estadosDeSeccion(string $seccion)
 * @method static void guardar(string $clave, mixed $valor, ?\Illuminate\Support\Carbon $vistoEn = null)
 * @method static void guardarComoSistema(string $clave, mixed $valor)
 * @method static void guardarVarios(array $valores, array $vistosEn = [])
 * @method static void quitarOverride(string $clave)
 * @method static bool puedeEditar(?\Illuminate\Contracts\Auth\Access\Authorizable $usuario, string $clave)
 * @method static NivelConfirmacion nivel(string $clave)
 * @method static CatalogoAjustes catalogo()
 *
 * @see ServicioAjustes
 */
class Ajustes extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ServicioAjustes::class;
    }
}
