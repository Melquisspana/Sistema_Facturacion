# Plan de ejecución — Fase 1
## Sistema de Facturación Electrónica · Dulces La Negrita

> Base inicial del sistema. **Sin** Hacienda, firma, PDF, NC, invalidación, inventario ni prontos pagos.
> Avanzamos PARTE por PARTE; cada parte termina con un punto de verificación.

### Entorno detectado
- PHP 8.3.30 — `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64`
- Composer — `C:\laragon\bin\composer\composer.bat`
- MySQL 8.4.3 — `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin`
- Node v24.16.0
- Base de datos a crear: **`dulces_negrita`**

PATH de sesión (se ejecuta al inicio de cada bloque):
```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;C:\laragon\bin\git\cmd;$env:Path"
```

### Notas de entorno (resueltas en PARTE 1 — recordar en fases siguientes)
- **Avast intercepta HTTPS.** Se añadió su CA raíz al bundle `C:\laragon\etc\ssl\cacert.pem` (respaldo en `cacert.pem.bak`). Aun así, para `composer` hay que exportar `COMPOSER_CAFILE` y para `npm` exportar `NODE_EXTRA_CA_CERTS`, ambos apuntando a ese bundle:
  ```powershell
  $env:COMPOSER_CAFILE     = "C:\laragon\etc\ssl\cacert.pem"   # antes de cada composer
  $env:NODE_EXTRA_CA_CERTS = "C:\laragon\etc\ssl\cacert.pem"   # antes de cada npm
  ```
- **`php.ini`**: se habilitó `extension=zip` (respaldo en `php.ini.bak`). Necesaria para Composer y para `spatie/laravel-backup`.
- **Composer** se invoca como `php "C:\laragon\bin\composer\composer.phar" ...` (no hay `composer` en el PATH del sistema; el `.bat` falla por SSL, el `.phar` con `COMPOSER_CAFILE` funciona).
- **MySQL**: usuario `root` sin contraseña (default Laragon).
- **PARTE 1 COMPLETADA:** Laravel 12.62, MySQL `dulces_negrita`, paquetes instalados (permission 8, activitylog 4.12 —la 5.0 exige PHP 8.4—, backup 10.3, livewire 4.3, breeze 2.4). Auth Blade sin registro público. `/login` responde 200.

### Decisiones técnicas de la Fase 1
- **Auth:** Laravel Breeze, stack Blade + Alpine (estable y simple). Livewire 3 se añade para las pantallas dinámicas (constructor de líneas del DTE).
- **SIN registro público:** se elimina la ruta `/register`. Los usuarios solo los crea el administrador (panel en PARTE 8).
- **DTE solo estructura (Fase 1):** `dtes`, `dte_items`, estados, historial y totales. Nada de JSON/firma/envío/recepción de Hacienda hasta fases posteriores.
- **Permisos:** `spatie/laravel-permission`.
- **Auditoría:** `spatie/laravel-activitylog` + tablas propias de historial del DTE.
- **Backups:** `spatie/laravel-backup`.
- **Inmutabilidad:** los DTE solo se trabajan en estado `borrador` ahora, pero el modelo y la State Machine ya impiden editar estados emitidos desde el día 1.

---

## PARTE 1 — Instalación del proyecto, paquetes y base de datos

**Comandos**
```powershell
# 1. PATH de sesión (ver arriba)
# 2. Mover el .md temporalmente (create-project exige carpeta vacía)
New-Item -ItemType Directory -Force ..\_docs_tmp | Out-Null
Move-Item .\ARQUITECTURA.md, .\PLAN-FASE1.md ..\_docs_tmp\
# 3. Crear Laravel 12 en la carpeta actual
composer create-project laravel/laravel:^12 .
# 4. Recuperar los .md
Move-Item ..\_docs_tmp\*.md .
Remove-Item ..\_docs_tmp
# 5. Crear la base de datos
mysql -u root -e "CREATE DATABASE IF NOT EXISTS dulces_negrita CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# 6. Paquetes
composer require spatie/laravel-permission spatie/laravel-activitylog spatie/laravel-backup
composer require laravel/breeze --dev
php artisan breeze:install blade
composer require livewire/livewire
npm install
```

**Archivos que se tocan**
- `.env` — conexión a `dulces_negrita`, `APP_NAME`, `APP_TIMEZONE=America/El_Salvador`, `APP_LOCALE=es`.
- `config/app.php` — timezone y locale (respaldo del .env).
- Se publican migraciones de Breeze (users, password_reset, sessions).

**Verificación:** `php artisan migrate` corre sin error y `npm run dev` compila. Login de Breeze accesible en `/login`.

---

## PARTE 2 — Estructura de carpetas, Enums y configuración base

> **PARTE 2 COMPLETADA Y VERIFICADA.** Carpetas (`app/Enums`, `app/Services/Dte`, `app/Actions`, `app/Support`, `app/DataTransferObjects`). 9 enums: TipoDte, EstadoDte, TipoCliente, TipoProducto, CondicionPago, TipoDocumentoCliente, AmbienteHacienda, TipoMovimientoDte, RolSistema. Configs: `config/dte.php`, `config/company.php`, `config/security.php` (sin credenciales). Middleware `SecurityHeaders` registrado en grupo `web`. Sesión endurecida (lifetime 30, encrypt, httpOnly, sameSite=lax, secure para prod). 4 roles sembrados. Cabeceras verificadas en `/login` (200) y `config:cache` sin errores.

**Archivos a crear**
```
app/Enums/TipoDte.php            # 01 FC, 03 CCF, 05 NC, 11 FEX (con label y versión esquema)
app/Enums/EstadoDte.php          # borrador, generado, firmado, enviado, aceptado, rechazado, invalidado
app/Enums/TipoCliente.php        # nacional, exportacion
app/Enums/TipoImpuesto.php       # gravado, exento, no_sujeto
app/Enums/RolSistema.php         # administrador, facturacion, consulta, contador
config/dte.php                   # ambiente, decimales, plazos (placeholders, sin URLs aún)
```

**Configuración de seguridad**
- `app/Http/Middleware/SecurityHeaders.php` — CSP, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy.
- Registro del middleware en `bootstrap/app.php` (Laravel 12).
- `config/session.php` — `expire_on_close`, lifetime 30 min, `secure`/`http_only`/`same_site`.

**Verificación:** Enums cargables vía `php artisan tinker`; cabeceras presentes en respuesta HTTP.

---

## PARTE 3 — Migraciones de seguridad y catálogos + sus seeders

> **PARTE 3 COMPLETADA Y VERIFICADA.** Tablas: `paises` (CAT-020), `departamentos` (CAT-012), `municipios` (CAT-013, FK depto), `actividades_economicas` (CAT-019), `unidades_medida` (CAT-014). Modelos con relaciones. Seeders idempotentes (updateOrCreate) en `CatalogosMhSeeder`. Sembrado: 8 países (El Salvador 9300, EE.UU. 9320…), 14 departamentos, 52 municipios (incluye **Olocuilta** en La Paz), 5 actividades, 10 unidades. **Decisión:** tipo de documento de identificación, condición de pago, ambiente y tipo de establecimiento se manejan por **Enums** (PARTE 2), no por tablas. Códigos MH de municipios y de varias unidades quedan nullable hasta importar el catálogo oficial vigente antes de la Fase 2.

**Publicar y crear migraciones**
```powershell
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
php artisan make:migration create_paises_table
php artisan make:migration create_departamentos_table
php artisan make:migration create_municipios_table
php artisan make:migration create_actividades_economicas_table
php artisan make:migration create_unidades_medida_table
```

**Migraciones (catálogos oficiales MH, solo lo que la Fase 1 usa)**
- `paises` (CAT-020), `departamentos` (CAT-012), `municipios` (CAT-013, FK a departamento),
  `actividades_economicas` (CAT-019), `unidades_medida` (CAT-014).

**Modelos:** `Pais`, `Departamento`, `Municipio`, `ActividadEconomica`, `UnidadMedida`.

**Seeders**
- `CatalogosMhSeeder.php` — carga un subconjunto inicial real (El Salvador, sus 14 departamentos, municipios principales, unidades de medida más usadas, actividades comunes). Ampliable después con los catálogos completos del MH.

**Verificación:** `php artisan migrate` + `php artisan db:seed --class=CatalogosMhSeeder`; conteos correctos en tinker.

---

## PARTE 4 — Empresa emisora, establecimientos, puntos de venta, correlativos

> **PARTE 4 COMPLETADA Y VERIFICADA.** Tablas `empresas`, `establecimientos`, `puntos_venta`, `correlativos` (con softDeletes salvo correlativos; índices únicos: establecimiento por empresa, punto de venta por establecimiento, correlativo por tipo_dte+establecimiento+punto_venta+ambiente). Modelos con relaciones, casts de enums (ambiente, tipo_establecimiento, tipo_dte) y accesor `siguiente_numero`. Enum `TipoEstablecimiento` (CAT-009). Form Requests con validación + unicidad. Controladores `Configuracion\*`. Rutas bajo `configuracion/` con middleware `auth` + `role:administrador` (alias spatie registrado en bootstrap/app.php). Pantallas Blade (empresa singleton + index/form de los otros 3) con enlace "Configuración" solo-admin en la navegación. Empresa NO se elimina; establecimiento/punto de venta usan soft delete y se bloquea borrado si tienen hijos. **29 tests pasan** (acceso admin/no-admin/invitado, guardado, compilación de vistas, y auth de Breeze intacto).
>
> **Auditoría de catálogos (post-PARTE 4):** se confirmó que todos los campos de catálogo MH ya son select controlado (tipo establecimiento, ambiente, tipo DTE, país, departamento, municipio, actividad económica). Endurecimiento añadido: validación de que **municipio pertenece al departamento** (servidor) + **dropdown dependiente** (componente `x-ubicacion-selects`, Alpine) en empresa y establecimientos; `Rule::in` en ambiente/tipo_establecimiento. **34 tests** (5 nuevos de validez de catálogos). Pendiente para fases siguientes (ya planificado como select/enum): unidad de medida (FK), condición de pago (enum), tipo de documento del receptor (enum).

**Migraciones**
```powershell
php artisan make:migration create_empresas_table
php artisan make:migration create_establecimientos_table
php artisan make:migration create_puntos_venta_table
php artisan make:migration create_correlativos_table
```
- `empresas` (NIT, NRC, nombre, actividad económica, dirección, contacto, logo).
- `establecimientos` (codigo_mh, FK empresa).
- `puntos_venta` (codigo_mh, FK establecimiento).
- `correlativos` (tipo_dte, FK establecimiento/punto_venta, ultimo_numero, UNIQUE).

**Modelos + relaciones:** `Empresa`, `Establecimiento`, `PuntoVenta`, `Correlativo` con `$fillable` estricto y casts.

**Controlador + vistas:** `EmpresaController` (configuración única, solo admin) — formulario de datos del emisor con validación por `EmpresaRequest`. Vistas Blade en `resources/views/configuracion/`.

**Seeder:** `EmpresaDemoSeeder.php` con los datos de Dulces La Negrita (placeholder editable).

**Verificación:** pantalla de configuración guarda y muestra los datos; solo accesible para administrador.

---

## PARTE 5 — Clientes

**Migración + modelo**
```powershell
php artisan make:migration create_clientes_table
php artisan make:model Cliente
```
- `clientes`: tipo_cliente (nacional/exportación), tipo y número de documento, NRC, nombre, actividad económica, ubicación nacional o país (exportación), tipo_persona, correo, teléfono, softDeletes.

**Form Request:** `ClienteRequest` — validación condicional según tipo de cliente; reglas de NIT/NRC/DUI (formato + dígito verificador donde aplique). Sanitización de entrada.

**Policy:** `ClientePolicy` — quién ve/crea/edita (admin y facturación gestionan; consulta y contador solo leen).

**Controlador + vistas:** `ClienteController` (resource) + Livewire para la tabla con búsqueda/paginación. Vistas en `resources/views/clientes/`.

**Verificación:** alta/edición/listado de cliente nacional y de exportación con validaciones funcionando; consulta no puede editar.

---

## PARTE 6 — Productos y Unidades de medida

**Migración + modelo**
```powershell
php artisan make:migration create_productos_table
php artisan make:model Producto
```
- `productos`: codigo (UNIQUE), nombre, descripción, FK unidad_medida, precio, tipo_item, tipo_impuesto,
  `maneja_inventario` (bool, default false) y `producto_inventario_ref` (gancho futuro, sin lógica).

**Form Request + Policy + Controlador + vistas:** análogos a Clientes.
**Unidades de medida:** mantenimiento de solo lectura en Fase 1 (vienen del catálogo MH); se listan para selección.

**Verificación:** CRUD de productos con selección de unidad e impuesto; campos de inventario presentes pero inertes.

---

## PARTE 7 — DTE borrador, detalle, estados e historial

**Migraciones**
```powershell
php artisan make:migration create_dtes_table
php artisan make:migration create_dte_detalles_table
php artisan make:migration create_dte_estado_historial_table
```
- `dtes`: tipo_dte, estado (default borrador), FK cliente/establecimiento/punto_venta, `dte_relacionado_id` (self, nullable), montos, `created_by`, campos de archivos/sello/sello MH **nullable** (reservados, sin uso aún).
- `dte_detalles`: snapshot del producto (código, descripción, precio copiados), cantidades, descuentos, montos por línea.
- `dte_estado_historial`: estado_anterior, estado_nuevo, user_id, comentario, created_at.

**Modelos:** `Dte`, `DteDetalle`, `DteEstadoHistorial` con relaciones y casts de Enum.

**State Machine:** `app/Services/Dte/DteStateMachine.php` — define transiciones válidas; en Fase 1 solo se permite crear/editar `borrador`. Bloquea cualquier cambio sobre estados emitidos (inmutabilidad desde el día 1) y registra cada transición en el historial.

**Controlador + Livewire:** `DteBorradorController` + componente Livewire para agregar/quitar líneas y calcular totales en vivo (gravado, IVA 13%, totales). Sin emitir nada — solo guardar borrador.

**Policy:** `DtePolicy` — crear/editar borrador (admin, facturación); consulta/contador solo leen.

**Verificación:** crear un borrador con varias líneas, totales correctos, intentar “editar” un estado distinto de borrador falla controladamente.

---

## PARTE 8 — Roles, permisos, asignación y auditoría

**Seeder de seguridad:** `RolesPermisosSeeder.php`
- Permisos granulares: `clientes.*`, `productos.*`, `dte.crear`, `dte.editar`, `dte.consultar`, `config.gestionar`, `auditoria.ver`, `usuarios.gestionar`.
- Roles: administrador (todos), facturación (clientes/productos/dte crear-editar), consulta (solo .consultar/.ver), contador (lectura + auditoría).
- Usuario administrador inicial (credenciales temporales que se obligan a cambiar).

**Middleware de roles:** rutas agrupadas por permiso (`can:` y middleware `role`).

**Auditoría**
- `LogsActivity` (spatie) en `Cliente`, `Producto`, `Empresa`, `User` con atributos antes/después e IP.
- Vista de auditoría (`AuditoriaController`) filtrable por usuario/modelo/fecha — solo admin y contador.
- Historial del DTE ya cubierto por `dte_estado_historial`.

**Verificación:** cada rol ve solo lo que le corresponde; las acciones quedan registradas con usuario e IP.

---

## PARTE 9 — Backups y endurecimiento final

**Configuración**
- `config/backup.php` — incluir BD `dulces_negrita` + `storage/app` (carpetas dte/pdf cuando existan); destino local en disco aparte; retención 7/4/12; notificación por correo en fallo.
- Programación en `routes/console.php` (Laravel 12): `backup:clean` y `backup:run` diarios.
- Comando manual de prueba: `php artisan backup:run`.

**Endurecimiento**
- Revisión de `$fillable` en todos los modelos.
- Rate limiting en login (5 intentos, bloqueo incremental).
- Confirmación de cabeceras de seguridad y CSRF en todos los formularios.
- `php artisan route:list` para revisar que toda ruta sensible tiene middleware de permiso.

**Verificación:** `php artisan backup:run` genera un zip; `route:list` sin rutas sensibles desprotegidas.

---

## Cierre de Fase 1
Sistema con: login + 2FA opcional para admin, 4 roles operativos, auditoría, configuración del emisor, catálogos MH base, clientes (nacional/exportación), productos, y DTE en borrador con detalle, estados e historial — todo con seguridad (validación, policies, CSRF, cabeceras, rate limiting) y backups automáticos. Listo para la Fase 2 (motor DTE contra el ambiente de pruebas del MH).
