# Continuidad — Activación de P002 en producción

> Documento de cierre de ventana. Léase completo antes de continuar. No autoriza por sí solo ninguna emisión ni transmisión real — ver sección 9.

## 1. Estado Git

- Rama: `master`
- `HEAD` y `origin/master` sincronizados en `f19a323`
- Commits de esta fase (en orden):
  - `a242201` — Implementar punto de venta P002 independiente
  - `4bb9065` — Reforzar seguridad y auditoría de invalidaciones
  - `f19a323` — Usar San Bartolo como recinto fiscal FEX predeterminado
- `git status --short --untracked-files=all`: working tree limpio, salvo los `.sql` de `backups/` (no rastreados, no ignorados por `.gitignore` pero deliberadamente nunca agregados con `git add`).
- Ningún commit de esta fase contiene `Co-authored-by`, `Signed-off-by` ni ningún otro trailer.

## 2. Estado P001/P002

- **P001**: intacto, sin cambios en ningún correlativo ni configuración.
- **P002**: `id=6`, establecimiento `M001`, `activo=true`, predeterminado efectivo del sistema (`DTE_PUNTO_VENTA_PREDETERMINADO=P002`), sin fallback silencioso a P001.
- Correlativos P002 **ambiente 00** (APITEST, probado):
  - `01=1`
  - `03=2`
  - `05=1`
  - `11=1`
- Correlativos P002 **ambiente 01** (producción, creados, sin usar):
  - `01=0`
  - `03=0`
  - `05=0`
  - `11=0`
- Los próximos números de control productivos de los cuatro tipos terminarían en `...000000000000001`.

## 3. Estado de los DTE de evidencia

- `#139`, `#140`, `#142`, `#143`: **aceptados**, **protegidos** (`estaProtegidoComoEvidencia()=true`) e **intactos**. No se tocaron en ningún paso de esta fase.
- `#144`: **invalidado oficialmente** en APITEST (evento real aceptado por Hacienda, ciclo de prueba end-to-end completo).
- **Ninguno de estos cinco documentos debe reutilizarse ni modificarse.** Son evidencia del cierre de la fase de pruebas P002/APITEST.

## 4. Invalidaciones

- Módulo probado **end-to-end** en P002/APITEST (creación → generación → firma → transmisión → invalidación real, con `#144`).
- Protección configurable de documentos de evidencia: **activa**, cubre los cuatro números de control y códigos de generación de `#139`/`#140`/`#142`/`#143` (no se listan aquí — ya están en `.env`, no son secretos pero se omiten por brevedad y para no repetir UUID completos).
- Nota de Crédito relacionada: exige **confirmación reforzada** (`--confirmo-nc-relacionada` / checkbox equivalente) antes de invalidar un documento que ya tiene NC asociada.
- Auditoría (`activity` log `dte_invalidacion`) funcionando tanto para mock como para transmisión real, sin registrar contraseñas, tokens ni JWS completos.
- URL productiva efectiva de invalidación:
  ```
  https://api.dtes.mh.gob.sv/fesv/anulardte
  ```
- Variable de entorno correcta (confirmada contra `config/dte.php`, no la que se usó de forma coloquial):
  ```
  DTE_PROD_ANULACION_URL
  ```

## 5. FEX (Factura de Exportación)

- Default de recinto fiscal cambiado de `08` a **`01` (Terrestre San Bartolo)** para toda FEX nueva (manual o desde Lista de Empaque).
- `08` (Terrestre Anguiatú) **sigue siendo un valor válido** del catálogo CAT-027 si se elige explícitamente — no se eliminó ni se bloqueó.
- FEX históricas (incluidas `#130` y `#143`) **intactas**: el serializador transmite siempre el valor guardado en cada documento, nunca fuerza el nuevo default sobre datos existentes.

## 6. Configuración productiva actual (sin secretos)

| Parámetro | Valor |
|---|---|
| Ambiente | `produccion` |
| URL de recepción | `https://api.dtes.mh.gob.sv/fesv/recepciondte` |
| URL de invalidación | `https://api.dtes.mh.gob.sv/fesv/anulardte` |
| Punto de venta predeterminado | `P002` |
| Certificado activo | de producción (verificado por hash contra el respaldo conocido) |
| `DTE_TRANSMISION_DRY_RUN` | **`true`** — ningún POST real sale hacia Hacienda mientras siga así |
| Worker (`queue:work`) | detenido |
| `jobs` / `failed_jobs` | `0` / `0` |
| Correo automático (`correo.auto_envio`) | `false` — desactivado |

No se muestran contraseñas, tokens ni contenido de certificados en este documento.

## 7. Backup fresco de la base de datos

- Ruta completa:
  ```
  C:\laragon\www\Facturacion\backups\backup_dulces_negrita_cierre_p002_produccion_antes_activar_20260721_081429.sql
  ```
- Tamaño: `1,054,044 bytes`
- SHA-256:
  ```
  3CFB7DAFC4DDECC7DDB7E611A550A451C11380998DB57722DC52DCB49C7FEC56
  ```
- Prueba de restauración en base temporal: **exitosa** (base temporal creada, poblada, verificada y **eliminada**; `dulces_negrita` nunca se tocó).
- Verificado en la restauración temporal:
  - Total de DTE: `126` (coincide con el original).
  - P002/ambiente 01 restaurado con los cuatro correlativos en `0`.
  - P001 idéntico al original.
  - `#144` invalidado; `#139`/`#140`/`#142`/`#143` aceptados.

## 8. Pendiente exacto para la próxima ventana

> **ACTUALIZADO 2026-07-21**: el punto 5 (primera emisión productiva) ya se ejecutó — ver sección 10. Los puntos 6 y 7 se cumplieron durante la emisión (P001 no se tocó; el worker solo se encendió puntualmente, con autorización explícita, y quedó detenido). Lo que sigue abajo es lo que falta AHORA.

1. Revisar este documento completo (incluida la sección 10) antes de tocar nada.
2. Definir el flujo de envío de correo/cola **recurrente** para producción (hoy funciona, pero el worker no queda corriendo de forma permanente; falta decidir si se deja como arranque puntual manual o se configura como servicio).
3. Desplegar en la PC servidor.
4. Configurar arranque automático (firmador, worker, scheduler) y backups programados — hoy ninguno arranca solo al reiniciar la PC (verificado: sin tareas programadas, sin entradas de registro `Run`, sin accesos en carpetas de inicio relacionadas al proyecto).
5. Ajuste visual pendiente: cambiar la etiqueta "PV:" a "Punto de venta:" en las plantillas PDF (CCF/Factura/NC/FEX), conservando el valor mostrado (ej. "Punto de venta: Sistema nuevo (P002)"). No debe tocar JSON, número de control, códigos fiscales, correlativos ni datos guardados.

## 9. Frase de seguridad

**No emitir ni transmitir producción sin autorización explícita del usuario.**

## 10. Primera emisión productiva P002 — #145 (2026-07-21, EJECUTADA)

Con autorización explícita del usuario (frase exacta "EMITIR PRODUCCION"), se generó, firmó y transmitió el primer CCF real de producción en P002.

- **DTE interno #145** — CCF (tipo 03).
- Cliente: Calleja, S.A. de C.V. (`id=10`). Sala: Súper Selectos San Benito (`id=191`). OC: `26070053004570`.
- **Número de control: `DTE-03-M001P002-000000000000001`**.
- Código de generación: `5A2A2D6A-6B0C-4B8B-B48E-501BBD7B7852`.
- Totales: bruto `$128.14`, descuento Calleja 5% `$6.41`, gravado neto `$121.73`, IVA `$15.82`, retención IVA 1% `$1.22`, **total a pagar `$136.33`**.
- **Estado: ACEPTADO por Hacienda.** HTTP 200, `codigoMsg=001`, `estado=PROCESADO`, sello `2026386FB99EC82E45A3931C61E4A8EB331A5CIU`, procesado `2026-07-21 08:50:31`.
- Correlativo **03/P002/01 pasó de `0` a `1`**. P002 tipos `01/05/11` (ambiente 01) siguen en `0`. **P001 no se tocó** (`03=1136, 01=0, 11=22, 05=364`, idéntico a antes).
- **`DTE_TRANSMISION_DRY_RUN` se restauró a `true`** inmediatamente después de la transmisión (candado cerrado, `config:clear` corrido, valor efectivo confirmado).
- **Correo enviado**: destinatario `dte@superselectos.com.sv`, BCC `aintegra.fe@hotmail.com` (copia a contabilidad, `contabilidad.enviar_copia=true`). Adjuntos: PDF + JSON oficial (sin JWS). Registro `DteEnvio #29`, estado `enviado` (SMTP real). Exactamente un envío para #145.
- **Desvío no previsto durante la emisión**: el preflight oficial (`PreflightEmisionProduccion`) exige worker/cola activo (heartbeat), lo que inicialmente chocaba con la instrucción de no iniciar el worker. Se consultó al usuario, autorizó iniciar `queue:work` únicamente el tiempo necesario (primero para el preflight+transmisión, luego de nuevo para procesar el único job del correo), deteniéndolo en ambos casos inmediatamente después. `jobs`/`failed_jobs` en `0`/`0` en todo momento fuera de esas ventanas puntuales.
- **Backup post-emisión**:
  ```
  C:\laragon\www\Facturacion\backups\backup_dulces_negrita_post_primera_emision_p002_20260721_090411.sql
  ```
  Tamaño `1,070,536 bytes`. SHA-256:
  ```
  dc2e2f9ccfcaf3ce040abe143824dd53d90b8c30fc0c1c5bc6b92f882b4bb4d6
  ```
  Restauración de prueba en base temporal (`dulces_negrita_verif_temp`): exitosa, verificada (correlativos P002/P001, #145 aceptado, total de DTE `127`) y **eliminada**.
- `#139/#140/#142/#143/#144` (evidencia de la fase de pruebas): **sin tocar**, mismos `updated_at` que antes de esta ventana.
- No se hizo commit ni push durante esta emisión. No se creó ningún otro DTE. No se usó P001 en ningún paso.
