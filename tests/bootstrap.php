<?php

/*
|--------------------------------------------------------------------------
| Arranque de la SUITE
|--------------------------------------------------------------------------
|
| Existe por una sola razón: fijar el límite de memoria DONDE PHPUnit lo lee de
| verdad.
|
| El problema real que resuelve. La suite completa —5.013 pruebas en UN proceso— llega
| a 754 MB de pico (medido, no estimado; por trozos se queda en ~330 MB, que es lo que
| engañaba). El `php.ini` de esta máquina da 512 MB: sin fijar nada, correr la suite
| entera de una vez moría a media corrida, y en un servidor con los 128 MB habituales
| por defecto muere mucho antes, con un fatal de memoria que no señala ninguna
| regresión. La forma de evitarlo era acordarse de escribir
| `php -d memory_limit=... vendor/bin/phpunit` a mano, y `php artisan test` ni
| siquiera admite ese `-d` porque lanza su propio proceso.
|
| PHPUnit no tiene una opción de memoria en `phpunit.xml`, pero sí carga este archivo
| (`bootstrap="tests/bootstrap.php"`) antes que nada y dentro del MISMO proceso donde
| corren las pruebas. Ponerlo acá hace que valga igual para `vendor/bin/phpunit`, para
| `php artisan test` y para cualquier IDE que use la configuración del proyecto, sin
| depender de que quien lo ejecute recuerde una bandera.
|
| 1 GB y no `-1`: un límite ilimitado convierte una fuga de memoria en una máquina que
| se arrastra en vez de en una prueba que falla. Sobre los 754 MB medidos deja un ~35%
| de margen: suficiente hoy y verificado, pero NO holgado. Si la suite sigue creciendo,
| este número hay que revisarlo con una medición nueva —el `Memory:` que imprime PHPUnit
| al final— y no a ojo.
|
| No hace nada más. Toda la configuración del entorno de pruebas sigue en
| `phpunit.xml` y en `Tests\TestCase`.
*/

ini_set('memory_limit', '1G');

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Que `force="true"` de phpunit.xml signifique lo que dice
|--------------------------------------------------------------------------
|
| PHPUnit aplica `force` escribiendo `putenv()` y `$_ENV`. Laravel, en cambio, resuelve
| `env()` con los adaptadores de phpdotenv EN ESTE ORDEN: `$_SERVER`, `$_ENV` y por
| último `putenv`. Y una variable exportada en el entorno del proceso —`$env:X` en
| PowerShell, `export X` en un shell, una variable de entorno del servidor de CI— llega
| a `$_SERVER`, que es el primero. Resultado: PHPUnit escribía el valor forzado en dos
| sitios que nadie llegaba a leer, y el del entorno seguía ganando.
|
| No es teórico: con las direcciones del MH exportadas a mano, `tests/Feature/Dte` y
| `tests/Feature/Configuracion` daban 64 errores y 40 fallos que no señalaban ninguna
| regresión. Contra el `.env` el fijado sí funcionaba —dotenv es inmutable y respeta lo
| que PHPUnit ya puso—, pero contra el entorno del proceso no.
|
| Se arregla quitando de `$_SERVER` exactamente las claves que `phpunit.xml` declara
| como forzadas, ni una más: las que el archivo marca así son justamente las que la
| suite necesita controlar por completo. Las demás variables del entorno —PATH,
| SystemRoot, lo que haga falta para lanzar procesos— no se tocan.
|
| Se lee del propio `phpunit.xml` para que la lista viva en UN solo sitio: agregar un
| `force="true"` allá basta, sin acordarse de este archivo.
*/
$configuracion = __DIR__.'/../phpunit.xml';

if (is_file($configuracion)) {
    $xml = @simplexml_load_file($configuracion);

    foreach ($xml?->php?->env ?? [] as $variable) {
        if ((string) $variable['force'] === 'true') {
            unset($_SERVER[(string) $variable['name']]);
        }
    }
}
