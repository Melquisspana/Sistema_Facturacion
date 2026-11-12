# Despliegue en el servidor Linux (Docker)

> Guía vigente desde el 11/11/2026, cuando producción pasó a Linux con Docker (`docs/MIGRACION_LINUX_DOCKER.md`). Las guías de Windows (`DESPLIEGUE_SERVIDOR.md`, `ARRANQUE_WINDOWS.md`, `WORKER_WINDOWS.md`, `BACKUPS_WINDOWS.md`, `RESTORE_BACKUP_WINDOWS.md`) quedan como historia.
> Repositorio público: aquí no van IP, dominios, usuarios ni claves. Las rutas son las por defecto de `compose.yaml` (`/srv/facturacion/...`).

Todo paso que toca producción, aun leer, necesita el «sí» de Melqui para ese paso.

## Cómo está armado

- `/srv/facturacion/app`: `compose.yaml` del commit desplegado y `.env.compose` (0600). Los comandos van con `docker compose --env-file .env.compose …` desde esa carpeta.
- `/srv/facturacion/env/laravel.env`: el `.env` de Laravel (`root:33`, 0640), montado de solo lectura.
- `/srv/facturacion/fuente`: un clon del repositorio, solo para construir la imagen. No se edita a mano.
- `/srv/facturacion/backups`: respaldos `.sql` (`backup:mysql-diario`, 02:00) y `nube/` (volcados del respaldo a Drive).
- `/srv/facturacion/firmador/jar`, `/srv/facturacion/certificados/produccion`, `/srv/facturacion/tls`: firmador, certificado de firma y certificado del 443 de la oficina.
- Volúmenes de Docker: `facturacion_app-storage` (`storage/`) y `facturacion_mysql-data`.
- Respaldo a Drive: `scripts/linux/respaldo-nube/README.md`.
- Todo arranca solo al encender (`restart: always`); el apagado ordenado es `docker compose stop` y después `systemctl poweroff`.

## Desplegar una versión nueva

Un despliegue cambia la **imagen** completa: ya no se copian archivos sueltos. La etiqueta lleva el commit (`r-<commit>`), y la anterior se conserva para volver.

1. **Construir, sin tocar lo que corre:**
   ```sh
   cd /srv/facturacion/fuente && git fetch -q origin && git checkout -q --detach <commit>
   docker build -t facturacion-app:r-<commit> .
   ```
2. **Revisión previa con la imagen nueva**, en un contenedor aparte (no escribe nada):
   ```sh
   cd /srv/facturacion/app
   N="env APP_IMAGE_TAG=r-<commit> docker compose --env-file .env.compose run --rm --no-deps -T -u www-data -e VIEW_COMPILED_PATH=/tmp/vistas web"
   $N php artisan migrate:status --pending       # las migraciones que se esperan, y nada más
   $N php artisan migrate --pretend --force      # el SQL exacto
   $N sh -c 'mkdir -p /tmp/vistas && php artisan view:cache'   # que compilen todas las vistas
   ```
3. **Respaldo y archivo para volver:** `docker compose … exec -T -u www-data web php artisan backup:mysql-diario --origen=manual`. Copiar `.env.compose` y anotar la etiqueta actual y el `.sql` recién hecho: volver es poner esa etiqueta, importar ese `.sql` y levantar.
4. **Mantenimiento:** `exec … web php artisan down --retry=60` y `docker compose … stop worker scheduler`.
5. **Migraciones, una por una**, con la imagen nueva: `$N php artisan migrate --force --path=database/migrations/<archivo>.php`. Al final, `migrate:status --pending` vacío.
6. **Permisos solo sumando**, nunca `RolesSeeder` (su `syncPermissions` quita lo que se dio a mano):
   ```sh
   env APP_IMAGE_TAG=r-<commit> docker compose --env-file .env.compose run --rm --no-deps -T -u www-data \
       -v /srv/facturacion/fuente/scripts/linux:/kit:ro web php /kit/permisos-sumar.php
   ```
   Lo que un rol tiene de más solo se lista. Quitar un permiso es otro paso, con su propio «sí».
7. **Cambiar la imagen:** `APP_IMAGE_TAG=r-<commit>` en `.env.compose`, `docker compose … up -d --wait web worker scheduler`, `exec … web php artisan permission:cache-reset` y `exec … web php artisan up`.
8. **Comprobar:** `exec -T web curl -s http://localhost/salud -H "Host: facturacion.test"` con `"estado":"ok"`, `/login` en 200, `ps` con los tres contenedores en la imagen nueva y lo que pida el PR en el navegador.
9. El `CHANGELOG.md` y la etiqueta `vAAAA.MM.DD.n` van en su propio PR, después del despliegue.

Los `.env` no se cambian en un despliegue salvo que el PR lo pida. Si cambia el `.env` de Laravel, hay que recrear los contenedores PHP (`up -d --force-recreate web worker scheduler`): el `config:cache` (y el de rutas y eventos) se hace al arrancar.

## Cuidados

- **El 443 de la oficina:** si `web` alguna vez no arrancó por el puerto, se levanta con `up -d --force-recreate web` (ver «Cuidado» en `MIGRACION_LINUX_DOCKER.md` §3.7).
- **Un solo conector del túnel:** `cloudflared` corre solo en este servidor. El destino del túnel se configura en el panel de Cloudflare (`http://web:80`), no en el servidor.
- **Comandos de Laravel:** siempre como `www-data` (`-u www-data`). `tinker` necesita `-e HOME=/tmp`.
- **Correo:** el SMTP real está en el Centro de Configuración (base), no en el `.env`. Un script propio que mande correo tiene que llamar antes a `ConfiguracionCorreoRuntime::aplicar()` (ver `scripts/linux/respaldo-nube/avisar.php`).
