# Correcciones de Cobros Calleja — 20 de septiembre de 2026

Esta actualización parte de la entrega `entrega-calleja-20260919.zip`, SHA-256
`f56b3d8244da0deedffe0e1cadf33c05b6f8877ff398af67cf81a4d2109e3cc1`.
Los nueve archivos de aplicación que reemplaza coincidían byte a byte con ese
paquete antes de corregirlos. No incluye Gastos, Planilla, invalidación de DTE,
firmware, credenciales, bases ni datos de demostración.

## Comportamiento corregido

- Una revisión histórica abierta impide seleccionar y crear la solicitud desde el
  servidor. Tampoco se presenta una factura completa con pago parcial, completo,
  diferencia o pago por resolver. Los documentos siguen visibles y explican el bloqueo.
- La revisión exige conclusión y referencia de evidencia. La opción predeterminada
  mantiene el bloqueo. Solo confirmar que nunca se presentó ni se cobró habilita
  nuevas solicitudes; no escribe pagos. La bitácora conserva motivo anterior,
  conclusión, evidencia, usuario y fecha. Una sincronización posterior no reabre
  esa revisión documentada. Lo ya cobrado en PPQ requiere conciliar su evidencia por
  el circuito correspondiente, no declararlo cobrado con una nota de texto.
- La auditoría busca otros CCF del seguimiento con la misma OC, incluso fuera del
  lote visible. La coincidencia del importe no deshace una colisión. Cualquier
  diferencia de importe o importe ausente pasa a revisión. No se calcula una NC
  restando CCF menos albarán.
- La vía manual requiere motivo, bloquea albaranes ocupados, de otro cliente/DTE o
  de tipo distinto a entrega. Las escrituras usan transacción y bloqueo del albarán
  con relectura de su ocupación. Las diferencias revisadas manualmente quedan
  documentadas; no se alteran datos fiscales ni vínculos del PPQ anterior.
- El correo íntegro se conserva; la interpretación usa la respuesta actual. Se
  apartan las citas habituales de Gmail/Outlook y texto plano. Un asunto de
  observaciones conserva su naturaleza aunque cite un acuse. Un rechazo permanece
  sin clasificar para revisión. No se deducen archivos a partir de números largos.
- Las tablas HTML conservan cada fila aunque sus celdas tengan saltos visuales. Se
  excluyen `cid:` de imágenes. UUID y control se agrupan únicamente cuando la fila
  permite hacerlo sin suponer; si se contradicen no se aplica la observación.
- Una observación parcialmente localizada conserva visibles los documentos
  pendientes. El documento identificado recibe su motivo concreto, sin marcar
  recibida la solicitud ni pagada la factura. Releer no duplica eventos.

## Actualización del servidor

La preparación del ZIP no instala nada. La ejecución corresponde a la sesión del
servidor, durante una pausa acordada para sustituir archivos y renovar la caché.

1. Verificar el SHA-256 del ZIP contra el archivo `.sha256`. Extraer fuera de
   producción y ejecutar `VERIFICAR.ps1 -Destino C:\laragon\www\Facturacion`.
   El script solo lee: comprueba los archivos del paquete y la versión de destino
   contra `MANIFIESTO.json`. Si hay archivos distintos, comparar/fusionar antes de
   continuar. No sobrescribir trabajo ajeno.
2. Confirmar el directorio real servido por el vhost y disponer de un respaldo
   puntual de los archivos que se reemplazarán y la base. Conservar un inventario
   del estado anterior para poder revertir los archivos de esta actualización.
3. Copiar solamente `archivos/` respetando sus rutas. Son nueve reemplazos y dos
   clases nuevas de aplicación. La carpeta `pruebas/` se conserva para verificación
   aislada: no es necesario copiarla para que funcione la aplicación.
4. Ejecutar `composer dump-autoload` y `php artisan view:clear` usando el PHP del
   proyecto. Si hay procesos PHP/OPcache que no detectan archivos nuevos, renovar
   esos procesos por el procedimiento habitual. No reiniciar ni apagar el
   planificador de respaldos. Esta actualización no cambia JS/CSS ni dependencias.
5. No ejecutar migraciones: esta corrección no agrega ni modifica tablas. No
   cambiar `.env`, perfil de Calleja ni automatizaciones existentes. Mantener las
   automatizaciones nuevas de Cobros apagadas.
6. Comprobar con sesión autenticada `/cobros`, una ficha histórica y el formulario
   de revisión; debe pedir evidencia y mantener el bloqueo como primera opción.
   Comprobar también Facturación, PPQ y notas de crédito.
7. Repetir la auditoría de vinculación **en seco**: los dos grupos que compiten por
   albarán y los importes distintos deben quedar en revisión. No reutilizar el
   recuento antiguo de 126 seguros. No aplicar vínculos durante esta verificación.
8. Leer los correos **en seco** con la consulta explícita
   `from:quedan@cliente-ejemplo.test`, sin `--aplicar`. El correo de observaciones
   REF 31001 debe ser observaciones, sin archivo inventado ni fecha heredada del
   acuse antiguo; comprobar las dos identidades y los pendientes no localizados.
9. Informar resultados antes de aplicar vínculos seguros o cargar evidencia real.
   La primera solicitud en el portal y la conciliación de un TXT real siguen
   requiriendo comprobación con el usuario. No generar ni emitir DTE para probar.

No ejecutar PHPUnit sobre la base del servidor. Las pruebas se ejecutan en
desarrollo con SQLite en memoria y el candado de base de `Tests\TestCase`.

## Límites que permanecen

Los PDF de quedan de `documentos@cliente-ejemplo.test` no se interpretan en esta
actualización. La consulta predeterminada de correo no se modifica: el filtro
acotado debe comprobarse en el buzón antes de activarlo. No se ha probado el nuevo
parser contra Gmail desde este desarrollo; se probaron reproducciones de los
casos diagnosticados. Las citas de correo sin separador reconocible y las tablas
sin estructura suficiente requieren revisión humana. No se afirma asociación
infalible ni se activa la vinculación automática.

Las pruebas SQLite verifican las rutas de bloqueo y los intentos sucesivos de
ocupación; no sustituyen una prueba de concurrencia con dos conexiones MySQL.
Los recuentos finales de validación están en `VALIDACION.txt` dentro del paquete.
