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
