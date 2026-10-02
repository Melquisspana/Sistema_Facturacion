# Registro de cambios

Este archivo registra los cambios relevantes del proyecto siguiendo el formato de Keep a Changelog, con categorías en español y las entradas más recientes primero.

Cada despliegue a producción lleva la etiqueta `vAAAA.MM.DD` y su entrada en este archivo, con la fecha y los cambios incluidos. Los cambios pendientes se agrupan en `[Sin publicar]`. No se incluyen datos del servidor ni detalles operativos del despliegue.

El historial anterior a este archivo está en el registro de git.

## [Sin publicar]

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
