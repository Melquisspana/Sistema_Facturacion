# 0004. Zona horaria: guardar en UTC y calcular el día de negocio en hora de El Salvador

## Estado

Propuesta

## Fecha

2026-10-03

## Contexto

`config/app.php` toma `APP_TIMEZONE` con UTC por defecto, y la aplicación corre así. El Salvador está seis horas detrás de UTC todo el año (sin horario de verano). De 18:00 a 23:59 hora local, `now()` y `today()` ya devuelven el día siguiente. Lo reporta el issue #15.

### Inventario (2026-10-03)

- **278 llamadas al reloj sin zona** (`now()`, `today()`, `Carbon::now()`, `Carbon::today()`, `date()`), concentradas en Gastos (≈55 entre controladores, recurrencias y avisos), comandos de consola (27), Planta (≈30), Facturación (≈20, con `DteBorradorService` y vistas), Cobros (≈20), Planilla (≈15), Documentos recibidos y Rutas.
- **Fechas de negocio afectadas después de las 18:00:**
  - las reglas `before_or_equal:today` de Gastos (pagos, compras, cuenta de proveedor) y de la fecha de presentación de NC aceptan el día siguiente;
  - las cuotas de Gastos que vencen hoy aparecen vencidas, y los días de antigüedad de Cobros (`Carbon::today()` en `CobroDocumento`) suman un día de más;
  - `SalidaRuta::finalizar()` y la fecha de inicio por defecto de una salida guardan el día siguiente.
- **Columnas:** 98 tablas con `timestamps()`, 72 columnas `dateTime`/`timestamp` y 59 `date`. Las marcas de tiempo se escriben desde PHP en UTC. Las columnas `date` que se llenan con `now()->toDateString()` guardan el día UTC. Excepción: `dtes.fecha_procesamiento_mh` se toma de `fhProcesamiento` de Hacienda, que viene en hora local, y se guarda como si fuera UTC. Esa columna ya mezcla zonas.
- **Pantallas:** 24 vistas formatean marcas `*_at` directamente, así que muestran la hora UTC.
- **Ya usan hora local:** Asistencia, con `HoraOficial` y `asistencia.zona_horaria` (guarda en UTC y muestra en hora local), y los nombres de los archivos Excel de Cobros, PPQ y NC (`now('America/El_Salvador')`). Las recurrencias de Gastos guardan `zona_horaria = config('app.timezone')`, es decir, «UTC».
- **DTE ante Hacienda:** `DteBorradorService` fija `fecha_emision` y `hora_emision` con `now()` al crear el borrador, y `MapeadorDteSalida` las envía tal cual como `fecEmi`/`horEmi`. Hoy van en UTC: un CCF creado a las 19:00 locales sale con la fecha del día siguiente y `horEmi` 01:00. La invalidación usa la fecha del DTE original y `Carbon::now()` (UTC) para la hora del evento.
- **Tareas programadas:** el planificador de Laravel interpreta las horas en UTC. `backup:clean` 01:00 → 19:00 local, `backup:run` 01:30 → 19:30, `gastos:generar-recurrentes` 05:30 → 23:30 del día anterior y `gastos:avisos` 06:00 → 00:00 local. Las tareas del Programador de tareas de Windows (respaldo diario verificado de las 02:00 y `schedule:run` cada minuto) usan la hora local del servidor y no cambian.

## Opciones

**A. `APP_TIMEZONE=America/El_Salvador`.** Arregla de un solo golpe `today()`, las validaciones y el planificador, pero:
- las filas nuevas guardarían la hora local y las existentes seguirían en UTC; habría que convertir 72 columnas y las de `timestamps()` en una ventana de mantenimiento, sin poder corregir con certeza las columnas `date` ya guardadas;
- cambiaría `fecEmi`/`horEmi` de los DTE desde el primer minuto: sería un cambio de comportamiento ante Hacienda que no se puede ensayar a medias;
- Asistencia y las comparaciones con `fecha_procesamiento_mh` tendrían que revisarse al mismo tiempo.

Es irreversible en la práctica una vez que se mezclen datos.

**B. Mantener UTC para guardar y usar una zona de negocio explícita (recomendada).** El reloj del sistema y los datos no cambian. Lo que significa «hoy» para el negocio pasa a calcularse con una sola fuente: `America/El_Salvador`.

## Decisión (propuesta)

Opción B, por etapas y sin migraciones:

1. **Fuente única.** Agregar `config('app.zona_negocio')` (`APP_ZONA_NEGOCIO`, por defecto `America/El_Salvador`) y una clase `App\Support\HoraNegocio` con `hoy()`, `ahora()`, `aLocal(Carbon)` e `inicioDelDiaUtc(fecha)`. Generaliza `HoraOficial` de Asistencia, que pasa a delegar en ella con su configuración propia como respaldo.
2. **Fechas de negocio.** Cambiar a `HoraNegocio` los «hoy» con efecto de negocio:
   - las validaciones `before_or_equal:today`, con una regla propia o con la fecha local explícita;
   - los vencimientos y avisos de Gastos y la antigüedad de Cobros;
   - las fechas por defecto de Rutas, Planta y Planilla.

   Los filtros por rango de fechas sobre marcas UTC convierten los límites del día local a UTC. Va un PR por módulo, cada uno con pruebas a las 17:59, 18:00 y 23:59 locales (`travelTo`).
3. **Planificador.** Agregar `'schedule_timezone' => env('APP_SCHEDULE_TIMEZONE', 'America/El_Salvador')` y revisar la hora de cada tarea: la mayoría se escribió pensando en hora local. Se decide tarea por tarea si conserva el horario real actual (por ejemplo, `backup:run` a las 19:30 locales) o pasa a la hora escrita. Antes, comprobar que `schedule:list` muestre la zona y la próxima ejecución esperadas.
4. **Pantallas.** Un formateador Blade de fecha y hora que convierte a la zona de negocio, aplicado a las 24 vistas con marcas `*_at`. Solo cambia la presentación.
5. **DTE: sin cambios en esta decisión.** `fecEmi` y `horEmi` siguen saliendo exactamente igual que hoy. Que hoy vayan en UTC queda registrado como hallazgo aparte. Pasarlos a hora local requiere su propia decisión, una prueba en el ambiente de pruebas de Hacienda (borrador creado después de las 18:00 locales, CCF, NC e invalidación) y una pausa de emisión controlada. Una prueba de caracterización fija el comportamiento actual para que ningún PR de las etapas 2 a 4 lo altere sin querer.
6. **`fecha_procesamiento_mh`:** documentarla como hora local de Hacienda, sin convertir datos. Cualquier comparación con `now()` se revisa en la etapa 2.

## Consecuencias

- No hay conversión de datos ni ventana de mantenimiento; cada etapa se puede revertir desplegando el archivo anterior.
- Todo «hoy» de negocio nuevo tiene que usar `HoraNegocio`. Para que no se cuelen `today()` sueltos en módulos de negocio, se agrega una prueba de arquitectura o una regla de análisis estático con una lista de excepciones.
- Riesgo: un filtro que mezcle día local con marca UTC devuelve un día corrido. Lo mitigan las pruebas en los bordes del día y la conversión centralizada en `inicioDelDiaUtc()`.
- Riesgo: al cambiar la zona del planificador, las tareas diarias se mueven seis horas. Mitigación: tabla de horarios antes y después en el PR, revisión de cada una y despliegue en un horario sin corridas en curso.
- Las recurrencias de Gastos siguen guardando «UTC» en `zona_horaria`. Pasan a guardar la zona de negocio solo para reglas nuevas, y el generador interpreta la columna.

### Plan de despliegue

1. Etapas 1 y 5 (clase y prueba de caracterización del DTE): sin efecto visible.
2. Etapa 2, un módulo por vez, empezando por Gastos y Cobros, que son los de efecto económico.
3. Etapa 3 (planificador): en una tarde sin corridas pendientes; verificar `schedule:list` en el servidor y la siguiente ejecución de cada tarea.
4. Etapa 4 (pantallas).
5. Decisión aparte sobre `fecEmi`/`horEmi`, con ensayo en el ambiente de pruebas de Hacienda.

Antes de la etapa 5, una consulta de solo lectura en producción cuantifica cuántos DTE tienen `hora_emision` entre 00:00 y 05:59, es decir, emitidos entre las 18:00 y las 23:59 locales, y si alguno fue observado o rechazado por la fecha.

## Referencias

- Issue #15.
- `app/Services/Asistencia/HoraOficial.php`, el precedente de este diseño.
