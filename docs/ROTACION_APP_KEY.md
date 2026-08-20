# Rotación de APP_KEY

> **Estado: implementado, probado y ENSAYADO DE PUNTA A PUNTA EN DEV.
> NO ejecutado en producción.** Ver §7 para la evidencia del ensayo.
> El comando `ajustes:rotar-app-key` simula por defecto; escribir exige pedirlo a
> propósito y confirmarlo con una frase. **Este comando no toca el `.env`**: el
> último paso lo hace una persona, con el sistema detenido.

---

## 1. Por qué importa

`APP_KEY` es la clave con la que Laravel cifra y descifra. Hoy la aplicación
guarda cifrado en **dos sitios**:

| Dónde | Qué |
|---|---|
| `ajustes_sistema.valor` (filas con `cifrado = 1`) | contraseña SMTP, secreto de cliente de Google, contraseña del buzón de compras |
| `gmail_cuentas.access_token` / `refresh_token` | tokens OAuth de la cuenta conectada |

Dos hechos que hay que tener presentes antes de tocar nada:

- **Un valor cifrado solo se recupera con la misma `APP_KEY` con la que se cifró.**
- **Cambiar `APP_KEY` a mano con secretos guardados los deja irrecuperables.** No
  hay recuperación posible: no es una contraseña que se pueda "resetear", es la
  clave de cifrado. El síntoma es un `DecryptException` al leer —o Gmail pidiendo
  reconectar sin motivo— y para entonces el original ya no existe.

Además, cambiar `APP_KEY` invalida sesiones y cookies cifradas: los usuarios
quedan desconectados. Eso es molesto pero reversible; lo de arriba no.

## 2. Regla

**Nunca editar `APP_KEY` en el `.env` sin haber corrido antes el comando.**

Para saber qué hay en juego, sin cambiar nada:

```bash
php artisan ajustes:rotar-app-key
```

Sin argumentos lista los secretos que la rotación tocaría y no escribe nada. Si
la lista sale vacía, cambiar `APP_KEY` no le hace perder nada a la aplicación.

## 3. El comando

```
php artisan ajustes:rotar-app-key
    [--nueva-key=base64:...]   clave nueva; preferí la variable de entorno
    [--ejecutar]               escribe. Sin esto, solo simula
    [--force]                  omite la frase de confirmación (automatización)
```

**De dónde sale la clave nueva.** De la variable de entorno `APP_KEY_NUEVA`, o de
`--nueva-key`. La variable es la vía recomendada: un argumento de consola queda en
el historial del shell y en la lista de procesos de la máquina, que son dos sitios
más de los que hacen falta para una clave de cifrado.

**Qué hace, en este orden:**

1. descifra **todo** con la clave actual — si una sola fila falla, aborta;
2. re-cifra **todo** con la clave nueva, en memoria;
3. comprueba el round-trip: lo re-cifrado se descifra con la clave nueva y tiene
   que dar exactamente lo mismo;
4. recién entonces escribe, y en una transacción.

El paso 3 es el que hace esto utilizable. Sin él, "se re-cifró bien" es una
suposición, y la forma de descubrir que era falsa sería un `DecryptException` en
producción con el original ya sobrescrito.

**Qué no imprime nunca:** claves de cifrado, valores descifrados ni criptogramas.
El informe lleva nombres de ajustes y recuentos, porque es lo que se ve en una
consola que alguien puede estar mirando.

## 4. Procedimiento completo

1. **Poner el sistema en mantenimiento.** Detener el worker de colas y el
   programador: un job en vuelo que lea un secreto durante la rotación fallaría.

2. **Respaldo completo previo**, con la `APP_KEY` **vieja** anotada aparte y
   guardada. Es el único camino de vuelta.
   Ver `docs/BACKUPS_WINDOWS.md` y `docs/RESTORE_BACKUP_WINDOWS.md`.

3. **Generar la clave nueva sin aplicarla**: `php artisan key:generate --show`.
   No usar `key:generate` a secas — sobrescribe el `.env` en el acto.

4. **Simular**: con `APP_KEY_NUEVA` definida, correr el comando sin `--ejecutar`.
   Tiene que decir que la rotación se puede aplicar. Si no, **parar acá**: hay
   filas que la clave actual no descifra y rotarlas las destruiría.

5. **Ejecutar**: mismo comando con `--ejecutar`, y escribir la frase
   `ROTAR CLAVE DE CIFRADO` cuando la pida.

6. **Aplicar la clave nueva** en el `.env` (`APP_KEY=...`) y `php artisan config:clear`.

7. **Reiniciar** worker y programador; salir de mantenimiento.

8. **Validar** cada secreto: probar la conexión SMTP, la del buzón de compras y la
   de Gmail desde el Centro de Configuración.

> **Entre el paso 5 y el paso 6 la aplicación no puede leer sus secretos.** Los
> datos están cifrados con una clave que el `.env` todavía no tiene. Por eso los
> pasos 1 y 7: esto se hace con el sistema detenido, nunca en caliente.

## 5. Qué pasa si algo sale mal

| Situación | Qué hace el comando |
|---|---|
| Una fila no se descifra con la clave actual | Aborta **sin escribir nada** y nombra las filas afectadas |
| Una fila se re-cifra pero no verifica | Aborta **sin escribir nada** |
| La clave nueva tiene longitud inválida | Falla antes de tocar la base |
| La frase de confirmación no coincide | No escribe |
| Un token de Gmail está corrupto | Aborta: se arregla desconectando y reconectando la cuenta |

En todos los casos el estado anterior queda intacto. Si ya se escribió y hay que
volver atrás, el camino es restaurar el respaldo del paso 2 con la `APP_KEY` vieja.

## 6. Fuera de alcance

No cubre:

- las contraseñas de usuario (hash `bcrypt`, no cifrado: no se ven afectadas);
- el certificado de firma ni su contraseña, que viven en el `.env` y en disco;
- cualquier otro almacén de valores cifrados que se agregue en el futuro.

Si aparece uno nuevo, se añade a `RotacionAppKey::ORIGENES` **antes** de la
siguiente rotación. Media rotación es peor que ninguna: da la sensación de haber
terminado.

---

## 7. Ensayo completo en DEV (Fase 5)

El procedimiento de §4 se ejecutó entero contra la base de desarrollo, incluida la
vuelta atrás. Ninguna clave, secreto ni criptograma se imprimió en ningún paso: la
verificación compara en memoria contra valores de control conocidos y publica solo
sí/no.

### Preparación

- Respaldo del `.env` de DEV y `mysqldump` de `ajustes_sistema` y `gmail_cuentas`.
- Secreto de control en `ajustes_sistema` (`mail.smtp.password`).
- Clave nueva generada con `key:generate --show` hacia un archivo temporal, nunca
  a la pantalla.

### Hallazgo: el comando encontró un problema real que nadie sabía que existía

El primer dry-run **abortó** (código de salida 1):

```
Se descifran con la clave actual : 1
NO se descifran                  : 2
  ✗ gmail_cuentas: …(access_token)  — no se puede descifrar con la APP_KEY actual.
  ✗ gmail_cuentas: …(refresh_token) — no se puede descifrar con la APP_KEY actual.

NO se puede rotar sin perder datos. No se escribió nada.
```

Los tokens OAuth de DEV estaban cifrados con una `APP_KEY` **distinta de la
actual**: en algún momento se cambió la clave sin rotar. Es exactamente el
accidente que este procedimiento existe para impedir, y el comando lo detectó sin
tocar nada. **En DEV la integración con Gmail ya estaba rota**; en producción hay
que comprobarlo con `php artisan ajustes:rotar-app-key` ANTES de cualquier otra
cosa.

Para poder seguir el ensayo se escribieron tokens de control cifrados con la clave
vigente (equivalente a reconectar la cuenta).

### Secuencia ejecutada y observada

| Paso | Comando | Resultado observado |
|---|---|---|
| 1 | dry-run | `Se descifran: 3 · No descifran: 0 · No verifican: 0` → «se puede aplicar» |
| 2 | `--ejecutar --force` | `Secretos re-cifrados: 3` |
| 3 | verificar **antes** de tocar el `.env` | `smtp_legible: NO` · `gmail_legible: NO` (DecryptException) |
| 4 | `APP_KEY` = nueva + `config:clear` | — |
| 5 | verificar | `smtp_legible: SÍ` · `gmail_legible: SÍ` · `gmail_intacto: SÍ` |
| 6 | rollback: rotar hacia la clave anterior | `Secretos re-cifrados: 3` |
| 7 | `APP_KEY` = original + `config:clear` | — |
| 8 | verificar | `smtp_legible: SÍ` · `gmail_legible: SÍ` · `gmail_intacto: SÍ` |

**El paso 3 es el más importante del ensayo**: confirma que la ventana entre
re-cifrar y cambiar la clave es REAL y que durante ella la aplicación no puede leer
sus secretos. Por eso los pasos 1 y 7 del procedimiento (mantenimiento y reinicio)
no son burocracia.

### Rollback

Probado y funcionando: **rotar de vuelta con la clave anterior** devuelve el
sistema a su estado exacto. No hace falta restaurar el respaldo salvo que los datos
hayan quedado inconsistentes.

Al terminar, `.env` quedó **byte a byte idéntico** al de antes del ensayo y las dos
tablas se restauraron desde el `mysqldump`. Todo el material sensible del ensayo
(copias del `.env`, dumps y archivos de claves) se borró.

### Lo que el ensayo NO cubrió

- No se rotó en producción.
- No se comprobó el comportamiento con el worker corriendo: el procedimiento exige
  detenerlo, así que el caso «rotar en caliente» sigue siendo, a propósito, un
  camino no soportado.
