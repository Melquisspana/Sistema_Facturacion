# Guía de despliegue a servidor

> Esta guía es solo documentación: **no se ejecutó nada de esto contra el servidor
> real** como parte de la tarea que la generó. Es para que el operador la siga a mano
> (o la automatice después) cuando decida desplegar.

Contexto del servidor real (según lo reportado): corre **sin Laragon abierto** —
Apache como servicio de Windows, MySQL como tarea programada, el firmador Java como
tarea programada, y Tailscale Serve para exponerlo. Esta guía asume ese entorno.

---

## 0. Antes de empezar

- [ ] Confirmar que la PC de desarrollo/staging donde se probó esto sigue en
      **APITEST** (`DTE_AMBIENTE=00`) y sin transmisión real habilitada.
- [ ] Avisar a los usuarios de una ventana de mantenimiento si el despliegue no es
      transparente (migraciones largas, etc.).

## 1. Backup previo (BD y `.env`)

```cmd
php artisan backup:mysql-diario --origen=manual
copy .env .env.backup-antes-deploy-%date:~-4%%date:~3,2%%date:~0,2%
```
Verificar en `respaldo_ejecuciones` (o con el comando anterior) que el backup se
registró `exitoso=true` **antes** de seguir. Si falla, no continuar.

## 2. Actualizar código

```cmd
git status
git pull
```
Revisar que no haya cambios locales sin commitear en el servidor antes de hacer pull
(si los hay, decidir si se descartan o se preservan — nunca `git checkout .`/`reset
--hard` sin revisar antes).

## 3. Dependencias

```cmd
composer install --no-dev --optimize-autoloader
```
```cmd
:: Solo si el despliegue incluye cambios de vistas/Tailwind/JS:
npm ci
npm run build
```
(Ver memoria del proyecto: nunca `npm run dev` en producción — rompe los estilos.)

## 4. Base de datos

```cmd
php artisan migrate --force
```
Revisar la salida: si alguna migración falla, **detenerse** y restaurar desde el
backup del paso 1 antes de reintentar.

## 5. Caches

```cmd
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Importante**: el worker de colas (`queue:work`) mantiene el código de la app
cargado en memoria durante toda su corrida (hasta `--max-time=3600`, una hora). Si
el despliegue cambió código que un job usa (jobs, mailables, servicios que estos
invocan), el worker sigue ejecutando la versión VIEJA hasta que se reinicie. Avisale
que hay código nuevo:
```cmd
php artisan queue:restart
```
Esto no mata el proceso ni la tarea programada: el worker termina el job actual y se
relanza solo (el bucle de `queue-worker-auto.bat` lo vuelve a levantar de inmediato
con el código nuevo). Correrlo siempre que el deploy toque algo que un job ejecute.

## 6. Verificar worker

- Confirmar que la tarea programada **"DTE Queue Worker"** existe y está corriendo
  (`Get-ScheduledTask -TaskName "DTE Queue Worker"` o taskschd.msc).
- Si es la primera vez en este servidor: registrar con
  `scripts\windows\registrar-tareas-windows.ps1` (como Administrador) o `schtasks`
  manual (ver `docs\WORKER_WINDOWS.md`).
- Confirmar heartbeat: panel `/admin/salud-sistema` o el bloque "Diagnóstico" del
  Dashboard → "Worker / cola" debe decir `correcto` (o al menos no `crítico`).

## 7. Verificar backup automático

- Confirmar que la tarea programada **"DTE Backup Diario"** existe (2:00 a.m.).
- Si es la primera vez: registrarla igual que el worker (mismo script `.ps1`).
- Confirmar `RespaldoEjecucion::hayValidoHoy()` — visible en el Dashboard
  ("Diagnóstico" → "Backup del día") o corriendo el comando manual una vez:
  ```cmd
  php artisan backup:mysql-diario --origen=manual
  ```

## 8. Verificar puertos

```cmd
netstat -ano | findstr ":80 "
netstat -ano | findstr ":3306 "
netstat -ano | findstr ":8080 "
```
- **80**: Apache (servicio de Windows) debe estar escuchando.
- **3306**: MySQL (tarea programada) debe estar escuchando.
- **8080**: firmador Java local (tarea programada) debe estar escuchando — confirmar
  además con el botón "Probar firmador ahora" en `/facturacion/preparar-produccion`.

## 8.1 Dominio público (Apache + Cloudflare Tunnel + Access)

### VirtualHost de Apache

Copiar la plantilla `docs\apache\facturacion-dulceslanegrita.conf.example` a la
carpeta de vhosts de Apache del servidor, ajustar `DocumentRoot` a la ruta real
de `Facturacion\public`, y validar antes de reiniciar:

```cmd
httpd -t
httpd -S
```

`httpd -t` debe decir `Syntax OK`; `httpd -S` debe listar
`facturacion.dulceslanegrita.com` apuntando al vhost nuevo. Después reiniciar el
servicio de Apache (o desde Laragon si está abierto — el servicio funciona sin
abrir Laragon).

### Cloudflare Tunnel

- El túnel (`cloudflared`) debe apuntar **únicamente** a `http://127.0.0.1:80`.
- **NUNCA** publicar por el túnel los puertos `3306` (MySQL) ni `8080/8113`
  (firmador): no crear rutas adicionales en el túnel para esos servicios.
- El token del túnel vive solo en la configuración de cloudflared del servidor;
  no se guarda en el repo, en `.env` de Laravel ni en la documentación.

### SSO con Cloudflare Access (login único)

Con Access delante del dominio ya no hay doble login: el middleware valida el
JWT firmado que Cloudflare envía (`Cf-Access-Jwt-Assertion`) y abre la sesión
local del usuario cuyo email coincida (debe existir en Usuarios y estar activo;
nunca se crean usuarios ni se asignan roles automáticamente).

Variables a definir en el `.env` **del servidor** (ninguna es un secreto, pero
`.env` no se versiona igualmente):

```env
CLOUDFLARE_ACCESS_ENABLED=true
CLOUDFLARE_ACCESS_TEAM_DOMAIN=<equipo>.cloudflareaccess.com
CLOUDFLARE_ACCESS_AUD=<aud-tag-de-la-aplicacion-access>
CLOUDFLARE_ACCESS_AUTO_LOGIN=true
CLOUDFLARE_ACCESS_ALLOWED_HOST=facturacion.dulceslanegrita.com
```

Dónde obtenerlas (sin copiar tokens ni secretos):
- **TEAM_DOMAIN**: Zero Trust → Settings → Custom Pages ("team domain"), p. ej.
  `miequipo.cloudflareaccess.com` (sin `https://`).
- **AUD**: Zero Trust → Access → Applications → (la aplicación que protege
  `facturacion.dulceslanegrita.com`) → Overview → campo **Application Audience
  (AUD) Tag**. Es un identificador público de la app, no una credencial.

En el mismo `.env` del servidor conviene además marcar la cookie de sesión como
segura (el dominio público siempre es HTTPS):

```env
SESSION_SECURE_COOKIE=true
```

Después de editar `.env`: `php artisan config:clear` (o `config:cache`).

> **Revocación**: quitarle el acceso a alguien en Cloudflare Access NO cierra una
> sesión de Laravel que ya estaba abierta (sigue viva hasta expirar o hasta un
> logout). Para un corte inmediato, desactivar también el usuario en el módulo
> Usuarios (`activo = no`): las pantallas protegidas dejan de servirle en el
> siguiente request de login y el SSO ya no le abrirá sesión nueva.

Comportamiento resultante:
- `https://facturacion.dulceslanegrita.com` → pasa por Access → entra directo al
  dashboard (sin login de Laravel) si el email del usuario existe y está activo.
- Email sin usuario local o usuario inactivo → 403 neutro (se registra en el log
  con el email enmascarado).
- `facturacion.test`, `localhost`, IP local y Tailscale → login local normal.
- Menú de usuario en el dominio público: opción extra "Cerrar sesión (también
  Cloudflare)" que cierra la sesión local y la cookie de Access.

**Deshabilitar / rollback al login local**: poner
`CLOUDFLARE_ACCESS_ENABLED=false` (o eliminar la variable) + `php artisan
config:clear`. El dominio público vuelve a mostrar el login local de Laravel
detrás de Access (doble login), sin ningún otro efecto. Las claves públicas del
team se cachean 1 hora; el rollback no depende de esa caché.

## 9. Preflight de invalidación (solo lectura, sin transmitir)

Antes de dar por cerrado el despliegue, correr el preflight de invalidación contra
cualquier DTE de referencia (NUNCA con `--transmitir-real`):
```cmd
php artisan dte:invalidacion-preflight <id> --tipo=<1|2|3> --motivo="..."
```
Confirmar que los candados que aparecen bloqueados son los esperados (p. ej.
`DTE_INVALIDACION_PRODUCCION_ENABLED=false` si producción real de invalidación sigue
apagada a propósito) y que ninguno falla por un error de configuración inesperado.

## 10. Verificación final

- [ ] `/dashboard` carga y el bloque "Diagnóstico" no muestra ningún `crítico`
      inesperado (revisar cada fila si lo hay).
- [ ] `/facturacion/preparar-produccion` carga y muestra los próximos correlativos
      de P002 esperados (no depende de Conta/P001).
- [ ] `/admin/salud-sistema` sin alertas rojas nuevas.

## 11. Rollback

Si algo falla después del paso 4 (migraciones ya corridas):
1. Restaurar la BD desde el dump del paso 1:
   ```cmd
   mysql -u root dulces_negrita < ruta\al\dump-de-antes.sql
   ```
2. Restaurar `.env` desde `.env.backup-antes-deploy-*`.
3. Volver el código a la versión anterior:
   ```cmd
   git log --oneline -5
   git checkout <commit-o-tag-anterior>
   composer install --no-dev --optimize-autoloader
   php artisan optimize:clear
   ```
4. Reiniciar el servicio de Apache y confirmar que el sitio responde con la versión
   anterior antes de investigar la causa del fallo con calma.

Si falla **antes** del paso 4 (todavía no se migró nada), alcanza con revertir el
`git pull` (`git checkout <commit-anterior>`) y reinstalar dependencias — no hace
falta tocar la BD.

Si el rollback implica también desinstalar las tareas programadas (worker/backup)
registradas en el paso 6/7, ver `docs\WORKER_WINDOWS.md` sección 5
("Desinstalar / revertir las tareas") — `Unregister-ScheduledTask` / `schtasks /Delete`.
