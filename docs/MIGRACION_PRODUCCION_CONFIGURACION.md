# Migración a producción del Centro de Configuración

Procedimiento para llevar a producción las **tres migraciones** de las Fases 2–4.

> **Estado: diseñado y ensayado en DEV. NO ejecutado en producción.**

**Nada de esto toca DTE, correlativos, firma, transmisión ni invalidación.** Las
tres migraciones solo crean tablas nuevas y mueven cinco filas de configuración de
correo. El ambiente fiscal, los correlativos y los interruptores de firma y
transmisión siguen viviendo en el `.env` y no se leen ni se escriben aquí.

---

## 1. Qué se va a aplicar

| Migración | Qué hace | Reversible |
|---|---|---|
| `2026_08_19_090000_create_ajustes_sistema_table` | Crea `ajustes_sistema` (vacía) | sí (`drop`) |
| `2026_08_20_090000_create_verificaciones_configuracion_table` | Crea `verificaciones_configuracion` (vacía) | sí (`drop`) |
| `2026_08_20_120000_migrar_configuraciones_correo_a_ajustes` | **Mueve 5 filas** de `configuraciones` a `ajustes_sistema` | sí (las devuelve) |

Solo la tercera toca datos. Mueve exactamente estas cinco claves:

```
contabilidad.correo        contabilidad.enviar_copia
correo.auto_envio          correo.adjuntar_jws          correo.plantilla
```

**No toca** las demás filas de `configuraciones`, que no son configuración sino
estado de proceso y deben quedarse donde están:

```
produccion.auth_prod_validada / _en / _fuente
produccion.ultimo_ccf_externo
ppq.albaranes.ultimo_dia_completo
```

---

## 2. La ventana entre desplegar y migrar

Un despliegue es `git pull` y después `php artisan migrate`. Entre las dos cosas
pasan segundos o minutos en los que **el código nuevo corre contra el esquema
viejo**, y con Apache siempre encendido cada petición de esa ventana entra por ahí.

Los dos órdenes están cubiertos y **probados** (`VentanaDespliegueTest`):

| Momento | Qué pasa | Resultado |
|---|---|---|
| Código nuevo, tablas nuevas ausentes | `RepositorioAjustes` y `RegistroVerificaciones` comprueban `Schema::hasTable` y devuelven vacío | Todo se resuelve por la **tabla anterior**, `config/.env` o el valor por defecto. Nada se cae. |
| Tablas creadas, datos sin mover | Las cinco claves siguen en `configuraciones` | Se leen por la **lectura de transición** (`claveLegacy`) |
| Datos ya movidos | Las cinco claves están en `ajustes_sistema` | Se leen de ahí; la tabla anterior ya no las tiene |

> Sin la comprobación de existencia de tabla, la ventana del primer caso devolvía
> **500 en toda la aplicación** —no solo en Configuración: también en el observer de
> DTE y en el job de correo, que resuelven ajustes—. Se detectó y se cerró en la
> Fase 5; el test lo fija.

**Conclusión: no hay ventana de pérdida de configuración.** En los tres momentos
cada clave resuelve al mismo valor. Lo único que cambia es la FUENTE, y el
comando de diagnóstico la muestra.

---

## 3. Checklist previo

- [ ] Ventana de baja actividad acordada (idealmente fuera de horario de emisión).
- [ ] Nadie emitiendo DTE en ese momento.
- [ ] Acceso al servidor y a la base confirmado.
- [ ] `git status` en el servidor **limpio** (sin cambios locales que el pull pise).
- [ ] Espacio en disco suficiente para el respaldo.
- [ ] Worker de colas y programador localizados (hay que reiniciarlos al final).
- [ ] Este documento leído entero antes de empezar.

---

## 4. Procedimiento

### 4.1 Respaldo (obligatorio)

```bash
php artisan backup:mysql-diario --origen=manual
```

Deja el dump verificado y su registro en `respaldo_ejecuciones`. Anotar la ruta del
archivo: es el punto de retorno.

Respaldo adicional acotado, por si hay que revertir solo la configuración:

```bash
mysqldump -u USUARIO -p BASE configuraciones ajustes_sistema > pre-migracion-config.sql
```

### 4.2 Fotografía ANTES

```bash
php artisan ajustes:estado > antes.txt
php artisan migrate:status | tail -20
```

`antes.txt` es la referencia con la que se compara después. Guardarlo.

### 4.3 Desplegar el código

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan config:clear
```

> Desde este momento y hasta el paso 4.4 corre la ventana descrita en §2. Es
> segura: la configuración se sigue leyendo de la tabla anterior.

### 4.4 Migrar

```bash
php artisan migrate --force
```

`--force` es obligatorio en producción (Laravel pide confirmación interactiva si
no). Las tres migraciones se aplican en orden por su timestamp.

### 4.5 Limpiar cachés y reiniciar procesos

```bash
php artisan config:clear
php artisan cache:clear
```

Después **reiniciar el worker de colas y el programador**. El worker vive horas y
mantiene su propia conexión; `cache:clear` invalida la huella de los ajustes, pero
un reinicio limpio evita cualquier duda en la primera ejecución.

### 4.6 Fotografía DESPUÉS y comparación

```bash
php artisan ajustes:estado > despues.txt
diff antes.txt despues.txt
```

**Qué es correcto ver en el diff:**

- las cinco claves migradas cambian de fuente `TABLA ANTERIOR` → `base de datos`;
- desaparece el aviso «Todavía en la tabla anterior…»;
- aparece «Mudanza de datos COMPLETA».

**Qué NO puede aparecer:**

- ninguna clave con el **valor** cambiado;
- ninguna clave que pase de `configurado: sí` a `configurado: NO`;
- ninguna tabla marcada como AUSENTE.

Si aparece cualquiera de esas tres cosas, ir a §5.

### 4.7 Verificación funcional

- [ ] Entrar a **Configuración → Resumen**: sin avisos nuevos.
- [ ] **Configuración → Correo**: el correo de contabilidad y la copia oculta
      muestran lo mismo que antes.
- [ ] **Probar conexión** SMTP: correcto.
- [ ] Emitir **nada**. Solo mirar. Si el auto-envío estaba activo, sigue activo.
- [ ] `php artisan ajustes:estado --pendientes`: nada inesperado sin configurar.

---

## 5. Rollback

Elegir el nivel más bajo que resuelva el problema.

### 5.1 Solo revertir la mudanza de datos

Devuelve las cinco claves a `configuraciones` y las quita de `ajustes_sistema`:

```bash
php artisan migrate:rollback --step=1 --force
php artisan config:clear && php artisan cache:clear
php artisan ajustes:estado > rollback.txt && diff antes.txt rollback.txt
```

El código nuevo sigue funcionando: las lee por la vía de transición. **Esta es la
reversión esperada** si algo del diff de §4.6 no cuadra.

### 5.2 Revertir también las tablas

```bash
php artisan migrate:rollback --step=3 --force
```

Borra las dos tablas nuevas. Se pierden los overrides que solo vivían ahí (SMTP,
Gmail, IMAP) y el historial de verificaciones. **Solo si además se vuelve al código
anterior**; con el código nuevo, esto deja al sistema en la ventana de §2, que es
segura pero no es un estado en el que convenga quedarse.

### 5.3 Volver al código anterior

```bash
git reset --hard d8f14a4e   # o el commit que estuviera desplegado
composer install --no-dev --optimize-autoloader
php artisan config:clear
```

Hacerlo **después** de 5.1 o 5.2: el código anterior no conoce `ajustes_sistema` y
leería las cinco claves de `configuraciones`, que la mudanza dejó vacía.

### 5.4 Restaurar el respaldo

Último recurso, si los datos quedaron inconsistentes:

```bash
mysql -u USUARIO -p BASE < pre-migracion-config.sql   # solo las dos tablas
```

o el dump completo del paso 4.1 siguiendo `docs/RESTORE_BACKUP_WINDOWS.md`.

---

## 6. Lo que este procedimiento NO hace

- No cambia el ambiente fiscal, ni los correlativos, ni la firma, ni la transmisión.
- No emite, no transmite y no invalida ningún documento.
- No toca `APP_KEY` (eso es `docs/ROTACION_APP_KEY.md`, y es otro procedimiento).
- No mueve secretos del `.env` a la base: los secretos siguen donde estén hasta que
  alguien los reemplace a mano desde la pantalla.
- No borra la tabla `configuraciones` ni ninguna clave ajena a las cinco listadas.

---

## 7. Después de migrar: qué se puede retirar

Cuando **producción** confirme «Mudanza de datos COMPLETA» y pase un ciclo de uso
sin incidencias, se puede quitar:

1. `claveLegacy` de las cinco definiciones en `CatalogoAjustes`;
2. la lectura de transición en `Ajustes::overrideAlmacenado()`;
3. `AdaptadorConfiguraciones`, que se queda sin usuarios.

**No antes.** Mientras exista un despliegue sin migrar, esa lectura es lo único que
evita la pérdida de configuración descrita en §2.
