# Rotación de APP_KEY

> **Estado: NO implementado y NO ejecutado.** Este documento describe el
> procedimiento y lo que hace falta para poder aplicarlo. No hay ningún comando que
> rote la clave hoy, y no debe crearse uno sin la revisión correspondiente.

---

## 1. Por qué importa

`APP_KEY` es la clave con la que Laravel cifra y descifra. Desde la Fase 2, la tabla
`ajustes_sistema` puede contener valores cifrados con ella (columna `cifrado = 1`).

Dos hechos que hay que tener presentes antes de tocar nada:

- **Un valor cifrado solo se recupera con la misma `APP_KEY` con la que se cifró.**
- **Cambiar `APP_KEY` a mano con secretos guardados los deja irrecuperables.** No hay
  recuperación posible: no es una contraseña que se pueda "resetear", es la clave de
  cifrado. El síntoma es un `DecryptException` al leer, y para entonces el valor
  original ya no existe en ninguna parte.

Además, cambiar `APP_KEY` invalida todas las sesiones y cookies cifradas: los
usuarios quedan desconectados. Eso es molesto pero reversible; lo de arriba no.

## 2. Regla

**Nunca editar `APP_KEY` en el `.env` si existe al menos una fila con `cifrado = 1`.**

Comprobarlo antes:

```sql
SELECT clave FROM ajustes_sistema WHERE cifrado = 1;
```

O desde la aplicación:

```php
app(\App\Ajustes\RepositorioAjustes::class)->clavesCifradas();
```

Si esa lista no está vacía, la única vía válida es el procedimiento de §4.

## 3. Precondición ya cubierta

La columna `ajustes_sistema.cifrado` existe precisamente para esto: permite
localizar **exactamente** qué filas hay que descifrar y volver a cifrar, sin
adivinar por el nombre de la clave ni intentar descifrar todo a ciegas. Tiene índice
propio para que el barrido sea directo.

Es la única parte de la rotación que sí está construida hoy.

## 4. Procedimiento (cuando se implemente)

1. **Poner el sistema en mantenimiento.** Detener el worker de colas y programador:
   un job en vuelo que lea un secreto durante la rotación fallaría.

2. **Respaldo completo previo**, con la `APP_KEY` **vieja** anotada aparte y
   guardada. Es el único camino de vuelta si algo sale mal.
   Ver `docs/BACKUPS_WINDOWS.md` y `docs/RESTORE_BACKUP_WINDOWS.md`.

3. **Generar la clave nueva sin aplicarla todavía**: `php artisan key:generate --show`.
   No usar `key:generate` a secas — sobrescribe el `.env` en el acto.

4. **Descifrar con la clave vieja** todas las filas `cifrado = 1`, en memoria.

5. **Volver a cifrar con la clave nueva** y escribir, en una transacción.

6. **Aplicar la clave nueva** en el `.env` (`APP_KEY=...`) y limpiar cachés de
   configuración (`php artisan config:clear`).

7. **Validar**: leer cada clave rotada con `Ajustes::secretoParaRuntime()` y
   comprobar que devuelve el valor esperado. Comprobar la funcionalidad que depende
   de cada secreto (por ejemplo, que el envío de correo sigue autenticando) **antes**
   de dar por buena la rotación.

8. **Reanudar** worker y programador; salir de mantenimiento.

Los pasos 4–6 son un único acto: si el proceso se interrumpe entre el re-cifrado y
el cambio de `.env`, las filas quedan cifradas con una clave que la aplicación no
tiene. Por eso el respaldo del paso 2 no es opcional.

## 5. Qué falta para poder ejecutarlo

- Un comando `artisan` que haga 4–5 leyendo la clave vieja y la nueva por parámetro,
  con `--dry-run` que informe cuántas filas tocaría sin escribir.
- Un modo de verificación que confirme, antes de escribir, que **todas** las filas
  se descifran correctamente con la clave vieja. Si una sola falla, abortar sin
  tocar nada.
- Pruebas del comando con las dos claves.

## 6. Fuera de alcance

Esta rotación cubre **solo** los valores cifrados por la capa de ajustes. No cubre:

- las contraseñas de usuario (hash `bcrypt`, no cifrado: no se ven afectadas);
- el certificado de firma ni su contraseña, que viven en el `.env` y en disco;
- cualquier otro uso de `Crypt` que se agregue en el futuro sin registrarse en
  `ajustes_sistema`.

Si aparece otro almacén de valores cifrados, este documento debe ampliarse antes de
la primera rotación real.
