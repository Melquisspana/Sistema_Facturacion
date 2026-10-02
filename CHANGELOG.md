# Registro de cambios

Este archivo registra los cambios relevantes del proyecto siguiendo el formato de Keep a Changelog, con categorías en español y las entradas más recientes primero.

Cada despliegue a producción lleva la etiqueta `vAAAA.MM.DD` y su entrada en este archivo, con la fecha y los cambios incluidos. Los cambios pendientes se agrupan en `[Sin publicar]`. No se incluyen datos del servidor ni detalles operativos del despliegue.

El historial anterior a este archivo está en el registro de git.

## [Sin publicar]

## [2026.10.02] - 2026-10-02

Primer despliegue con etiqueta. Incluye #1, #2, #29 y #30. No trae migraciones.

### Seguridad

- Los datos reales de la empresa salen del código: el código de proveedor de los TXT de pagos, los datos del exportador y el host de Cloudflare Access se leen del entorno (`PPQ_CODIGO_PROVEEDOR`, `EXPORTACIONES_*`, `CLOUDFLARE_ACCESS_ALLOWED_HOST`), sin valores operativos por defecto. Si falta el código de proveedor, el TXT se rechaza con un mensaje que lo dice. El seeder del administrador inicial ya no trae una contraseña fija. #2
- Los datos que llegan a Alpine y a `confirm()` se escapan como literal de JavaScript (`@js`), y las fechas de los filtros de Facturación e Invalidaciones solo aceptan `AAAA-MM-DD` válidas. #29
- Un usuario desactivado pierde sus sesiones abiertas en la petición siguiente. La política de contraseñas de `config/security.php` se aplica también al cambio desde el perfil y al restablecimiento por correo. Los inicios y cierres de sesión, los intentos fallidos, los bloqueos y los restablecimientos quedan en Auditoría (módulo «Acceso») con la IP real del cliente. Se agrega un techo de intentos de inicio de sesión por IP (`LOGIN_MAX_ATTEMPTS_POR_IP`). #30

### Agregado

- Integración continua en GitHub Actions con PHPUnit, un job de PHPUnit con MySQL estricto (en cada push desde #1, todavía no bloqueante) y Pint sobre los archivos cambiados.
- Plantillas de pull requests e issues para documentar cambios, pruebas y criterios de aceptación.
- Política de reporte privado de vulnerabilidades en `SECURITY.md`.
- Registro de decisiones del proyecto y primera decisión sobre ramas y pull requests.
- Decisión 0002, en propuesta: cómo aplicar las migraciones pendientes y las destructivas en producción. Relacionado con #5.
- `README.md` como portada del proyecto: qué hace el sistema explicado sin tecnicismos, capturas tomadas sobre una base desechable con datos inventados (`docs/img/`), tecnología, arquitectura, calidad y seguridad.
- `LICENSE` con todos los derechos reservados: el código es público como portafolio, no de uso libre (`composer.json` pasa de `MIT` a `proprietary`).

### Cambiado

- El candado de base de datos de la suite admite una única excepción: la MySQL desechable del job de la CI, que debe pedirse de forma explícita y solo pasa si la base se llama `testing` y está en el runner.

### Corregido

- Cobros: los textos libres que genera el sistema (motivos, notas, asuntos) se recortan al largo de su columna. Con MySQL estricto, un motivo demasiado largo revertía la aplicación completa de un TXT de pagos. Además, un correo que falla ya no detiene la lectura del buzón: se registra, se cuenta y se reintenta. #1 cierra #4.
- Las pruebas del diagnóstico del sistema (Salud del sistema, Dashboard y `DiagnosticoSistemaService`) ya no dependen de que exista `public/storage` en la máquina: fallaban en todo checkout nuevo y en la CI. Se agregan pruebas del chequeo del enlace, que no tenía ninguna.

- `NavigationTest` actualizado al menú vigente de Cobros con el seguimiento de CCF.
