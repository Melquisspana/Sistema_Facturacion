# Seguridad y entorno — checklist de preproducción (antes del piloto)

Auditoría operativa de seguridad para el piloto del sistema DTE (ver
`docs/PILOTO_PREPRODUCCION.md`). Confirma que **no** haya configuración peligrosa,
que los **secretos** no se expongan y que los **roles** estén bien acotados.

> **Solo procedimiento y valores recomendados.** No cambia lógica fiscal, firma,
> transmisión, correo ni PDF. Los interruptores viven en el `.env` local (nunca en el
> repo). Corré los comandos de la sección 1 para ver el estado real en tu máquina.

---

## 1. Cómo auditar (dos comandos, no muestran secretos)

```cmd
php artisan dte:seguridad-check     # APP_DEBUG, firma/transmisión, .env y cripto ignorados
php artisan dte:modo-operacion      # modo (paralelo/respaldo/principal) + candados
```

- `dte:seguridad-check` imprime **solo booleanos** (`configurada (oculta)` / `no
  configurada`): nunca contraseñas, tokens ni certificados.
- `dte:modo-operacion` da el veredicto **PARALELO SEGURO** / `... BLOQUEADO` / `... LISTO`.
- Lo mismo se ve **en pantalla**: badge del navbar (administrador/facturación) y panel
  **Salud del sistema → "Transmisión DTE"** (solo administrador).

**Regla de oro para el piloto:** el resultado debe ser **PARALELO SEGURO** (verde). Si
alguna vez aparece **… LISTO** en rojo, el sistema podría transmitir real: parar y revisar.

---

## 2. Valores recomendados del `.env` para el piloto

Marcá cada uno con `dte:seguridad-check` / `dte:modo-operacion`. El **modo paralelo** es
el candado maestro: aunque otros flags queden encendidos, en `paralelo` la transmisión
real **siempre** está bloqueada.

| Variable | Valor recomendado (piloto) | Por qué |
|----------|:--------------------------:|---------|
| `APP_ENV` | `production` (o `local` en la PC de pruebas) | Entorno real; evita comportamientos de dev |
| `APP_DEBUG` | **`false`** | No filtrar trazas/errores con datos internos |
| `APP_KEY` | (definida) | Cifrado de sesión/datos; nunca vacía |
| `SESSION_ENCRYPT` | `true` | Sesión cifrada (ya viene así) |
| `DTE_MODO_OPERACION` | **`paralelo`** | Conta Portable es el oficial; el nuevo NO transmite |
| `DTE_SISTEMA_ACTUAL_ACTIVO` | `true` | Refuerza el bloqueo de transmisión real |
| `DTE_TRANSMISION_ENABLED` | `false` | Interruptor maestro de transmisión apagado |
| `DTE_TRANSMISION_DRY_RUN` | `true` | Nunca HTTP real |
| `DTE_TRANSMISION_REAL_CONFIRMATION` | `false` | Falta la confirmación fuerte |
| `DTE_TRANSMISION_ALLOW_PRODUCTION` | `false` | Producción del MH no autorizada |
| `DTE_TRANSMISION_TEST_ENABLED` | `false` | Sin envío directo a apitest |
| `DTE_AUTH_TEST_REAL_ENABLED` / `_PROD_ENABLED` | `false` | Sin login real a Hacienda |
| `DTE_FIRMA_ENABLED` | `false` (o `true` **solo** con `DTE_FIRMADOR_MOCK`) | Firma real apagada en piloto |
| `DTE_FIRMADOR_MOCK` / `MH_MOCK` / `DTE_INVALIDACION_MOCK` | según necesidad de prueba | Simulan sin credenciales; el badge muestra **PRUEBAS / MOCK** |
| `QUEUE_CONNECTION` | `database` | Los correos se encolan; requieren worker |
| `CACHE_STORE` | `database` | Heartbeat del worker + cache compartida |
| `MAIL_MAILER` | SMTP real (no `log`) | Para que los correos salgan de verdad |
| Backups | scripts activos + tarea programada | Ver `docs/BACKUPS_WINDOWS.md` |

> ⚠️ **Secretos:** `DTE_CERT_PASSWORD`, `DTE_TRANSMISION_USER/PASSWORD/TOKEN`,
> `DTE_API_*`, `GMAIL_CLIENT_SECRET`, `MAIL_PASSWORD` van **solo** en el `.env` local,
> **nunca** en el repo, docs, scripts ni capturas. `.env` está en `.gitignore`.

### Combinación segura mínima (lo que importa)

Con **`DTE_MODO_OPERACION=paralelo`** la transmisión real está bloqueada aunque el resto
de flags estén encendidos. Es el candado que no hay que tocar durante el piloto. Los demás
valores de la tabla son defensa en profundidad.

---

## 3. Roles y permisos (quién puede qué)

Cuatro roles: **administrador**, **facturación**, **consulta**, **contador**
(`RolSistema`). Las acciones DTE se gobiernan por `DtePolicy`; las pantallas admin por
middleware `role:administrador`.

| Acción / pantalla | admin | facturación | consulta | contador |
|-------------------|:-----:|:-----------:|:--------:|:--------:|
| Ver / listar documentos (`view`, `viewAny`) | ✅ | ✅ | ✅ | ✅ |
| Crear / editar / **generar** (`create`, `update`, `generarJson`) | ✅ | ✅ | ❌ | ❌ |
| **Firmar / transmitir** (`firmarTransmitir`)¹ | ✅ | ✅ | ❌ | ❌ |
| Estado técnico + dry-run visual (`verEstadoTecnico`) | ✅ | ✅ | ❌ | ❌ |
| Invalidación mock / dry-run (`verInvalidacion`, `invalidarMock`) | ✅ | ✅ | ❌ | ❌ |
| **Salud del sistema** (`/admin/salud-sistema`) | ✅ | ❌ | ❌ | ❌ |
| Badge **jobs fallidos** (navbar) | ✅ | ❌ | ❌ | ❌ |
| Badge **modo DTE** (navbar) | ✅ | ✅ | ❌ | ❌ |
| Auditoría | ✅ | ❌ | ❌ | ✅ |
| Configuración · Usuarios · Importaciones | ✅ | ❌ | ❌ | ❌ |

¹ **`firmarTransmitir` habilita la acción para gestores, pero los candados de transmisión
mandan:** en modo `paralelo` la transmisión real está bloqueada para todos. Un gestor que
pulse "Firmar y transmitir" obtiene firma (mock si aplica) y la transmisión queda
**bloqueada con mensaje claro**; no se envía nada a Hacienda.

**Usuarios (recordatorio operativo, ya con protecciones en el sistema):**
- Debe existir el **administrador real** (correo real + contraseña fuerte) y **darse de
  baja el admin temporal** (`admin@dulceslanegrita.test`). Salud del sistema lo marca
  **Crítico** mientras el temporal siga activo.
- El sistema impide quedarse sin administrador (no inactivar/eliminar/quitar rol al
  último; no eliminarte a vos mismo). Conviene tener **≥2 administradores**.

---

## 4. Manejo de secretos (verificado)

| Punto | Estado |
|-------|--------|
| `.env`, `.env.production`, `.env.backup` en `.gitignore` | ✅ |
| Material cripto ignorado (`/resources/firmador/`, `*.crt`, `*.p12`, `*.key`, `*.pem`) | ✅ (8 archivos, todos cubiertos) |
| Contraseña del certificado (`passwordPri`) al firmar | Se envía al firmador; **no se loguea** (redactada como `***`) |
| Token/usuario/contraseña de transmisión | Solo desde `.env`; **nunca** en logs, vistas ni respuestas |
| Comandos de diagnóstico (`dte:*`) | Imprimen **solo** `configurada (oculta)` / `no configurada` |
| Vistas (navbar, Salud del sistema, ficha DTE) | **No** renderizan ningún secreto |
| `.env.example`, docs y scripts `.bat` | Sin valores reales (solo placeholders y nombres de campo) |

Auditá en cualquier momento con `php artisan dte:seguridad-check`.

---

## 5. Hallazgos de esta auditoría

- ✅ **`.env.example` limpio**: todos los flags DTE en valores seguros; sin secretos.
- ✅ **Secretos protegidos**: no se imprimen ni versionan (secciones 4).
- ✅ **Roles correctos**: pantallas admin solo para administrador; generar/firmar/
  transmitir solo gestores; ver/listar para los cuatro roles.
- ⚠️ **Nota sobre el `.env` local de desarrollo**: puede tener `APP_DEBUG=true`,
  `DTE_FIRMA_ENABLED=true`, `DTE_TRANSMISION_ENABLED=true` y `DTE_TRANSMISION_DRY_RUN=false`
  (estado de prueba del desarrollador). **Aun así**, con `DTE_MODO_OPERACION=paralelo` el
  veredicto es **PARALELO SEGURO** y la transmisión real está **bloqueada**. Antes del
  piloto en la PC real, aplicá los valores de la sección 2 (sobre todo `APP_DEBUG=false`).

> No se detectó configuración que permita transmitir a Hacienda por accidente mientras el
> modo sea `paralelo`. El candado maestro es `DTE_MODO_OPERACION`.

---

## 6. Checklist Go / No-Go del piloto

Antes de arrancar el piloto, confirmá (todo debe estar en ✅):

- [ ] `php artisan dte:modo-operacion` → **PARALELO SEGURO** (o el badge del navbar en verde).
- [ ] `php artisan dte:seguridad-check` → sin `!!` inesperados; **APP_DEBUG=false** en la PC real.
- [ ] `DTE_MODO_OPERACION=paralelo` y `DTE_SISTEMA_ACTUAL_ACTIVO=true`.
- [ ] Transmisión real **bloqueada** (`ENABLED=false`, `DRY_RUN=true`, `REAL_CONFIRMATION=false`, `ALLOW_PRODUCTION=false`).
- [ ] **Backups activos** (script + tarea) y un `.zip` reciente (Salud del sistema → Backups).
- [ ] **Worker activo** (Salud del sistema → Cola de correos en verde; ver `docs/COLA_CORREOS.md`).
- [ ] **Roles revisados**: existe admin real, admin temporal dado de baja, ≥2 administradores;
      consulta/contador **no** ven Salud del sistema ni acciones de gestión.
- [ ] **Sin secretos expuestos**: `dte:seguridad-check` sin alertas de `.gitignore`/cripto.
- [ ] **Conta Portable sigue siendo el sistema oficial** durante todo el piloto.

**No-Go (parar y revisar) si:** el badge/`dte:modo-operacion` aparece en rojo (transmisión
real posible), `APP_DEBUG=true` en producción, falta backup reciente, el worker está caído,
o consulta/contador acceden a pantallas de administrador.

---

## 7. Alcance y referencias

- Este documento **no** cambia código: es una guía de auditoría y valores recomendados.
- Los candados y su lógica: `docs/TRANSMISION_DTE.md` (§7.b).
- Invalidación (solo mock/dry-run en UI; real por consola): `docs/INVALIDACION_DTE.md`.
- Piloto y comparación con Conta Portable: `docs/PILOTO_PREPRODUCCION.md`.
- Backups y restauración: `docs/BACKUPS_WINDOWS.md`, `docs/RESTORE_BACKUP_WINDOWS.md`.
- Worker de correos: `docs/COLA_CORREOS.md`.
