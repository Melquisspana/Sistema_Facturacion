# 0002. Aplicar migraciones pendientes y migraciones destructivas

## Estado

Propuesta

## Fecha

2026-09-29

## Contexto

Producción no siempre recibe todo `master` de una vez: se despliega lo que cada entrega necesita. Por eso el código y las migraciones de `master` pueden llevar semanas de diferencia con lo que corre en producción. Laravel ejecuta en un solo `php artisan migrate` todas las migraciones pendientes, incluso las que son anteriores a otras ya aplicadas. Un despliegue pensado para un arreglo puntual puede arrastrar migraciones de módulos que no se están desplegando.

La auditoría del 28/09/2026 encontró este caso. En `master` conviven migraciones de módulos que aún no están activos en producción (Gastos, Planilla), la de procedencias de cobro y dos de Rutas. Una de las de Rutas es destructiva: elimina las tablas de la custodia documental retirada sin comprobar si tienen filas, y su `down()` recrea la estructura pero no los datos. Además, el código de producción que todavía use esas tablas deja de funcionar si la migración corre antes de desplegar el código nuevo de Rutas.

Otros dos riesgos acompañan el mismo despliegue:
- Los permisos nuevos se crean con `RolesSeeder`. Si no se corre, hasta el administrador recibe 403 en los módulos nuevos.
- Las pruebas corren en SQLite, que no reproduce todo lo que MySQL rechaza. Que la suite esté en verde no garantiza que una migración funcione en producción.

## Decisión

1. **Una migración viaja con su código.** Una migración que elimina o cambia algo que usa el código anterior se despliega en la misma ventana que el código que deja de usarlo, nunca antes. Si un despliegue no incluye ese código, no se corre `migrate` a ciegas: se aplican solo las migraciones del despliegue con `php artisan migrate --path=<archivo>`, una por una y en orden.
2. **Comprobaciones antes de migrar:**
   - `php artisan migrate:status`, para listar lo que va a correr;
   - un respaldo de la base;
   - para cada migración que elimina tablas o columnas, el conteo de filas afectadas.
   
   Si el conteo no es cero, la migración no corre hasta que alguien decida qué hacer con esos datos (exportarlos o descartarlos) y lo deje registrado.
3. **Guarda en las migraciones destructivas.** Una migración que elimina tablas o columnas con datos posibles aborta con un mensaje claro si encuentra filas, salvo que una variable de entorno explícita autorice descartarlas, una vez hecho el respaldo. La guarda no reemplaza la comprobación del punto 2: la respalda cuando alguien la omite.
4. **Permisos:** cuando un despliegue agrega permisos, se corre `RolesSeeder` inmediatamente después de migrar.
5. **Módulos detrás de bandera:** sus migraciones pueden aplicarse antes de activar la bandera, porque solo crean tablas, pero su código ya debe tolerar que falten (middleware que responde 503). Activar la bandera es un paso aparte.
6. **Registro:** el detalle de cada despliegue (qué migraciones corrieron, conteos y respaldo) queda fuera del repositorio, en el registro privado de despliegues, y el cambio visible en `CHANGELOG.md` con su etiqueta de versión.

## Consecuencias

Un arreglo urgente deja de estar bloqueado por migraciones ajenas, y ninguna migración destructiva corre sin que alguien haya mirado los datos. El costo es un paso más en cada despliegue (`migrate:status`, conteo y, si hace falta, `--path`) y algo de disciplina para agrupar código y migración.

Pendiente de aprobación aparte: agregar la guarda del punto 3 a la migración de Rutas que elimina la custodia, y cubrirla con una prueba que recree las tablas, inserte una fila y compruebe que la migración aborta.

## Relacionadas

- [0001. Flujo con ramas y pull requests](0001-flujo-con-ramas-y-pull-requests.md).
- Relacionado con #5: plan para aplicar en producción las migraciones pendientes.
