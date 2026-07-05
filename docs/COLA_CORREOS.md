# Cola de correos — operación diaria (Windows / Laragon)

Los correos del sistema (envío del CCF al cliente) **no salen solos**: al pulsar
**"Enviar correo y abrir PDF"** el mensaje se **encola** (tabla `jobs`, driver `database`) y
la pantalla abre el PDF al instante, **sin esperar al SMTP**. El correo se envía de verdad
**solo cuando un worker procesa la cola**. Por eso hay que tener corriendo un *worker*.

> No cambia nada de la lógica de correos, ni el driver de cola, ni el SMTP. Es solo la
> forma práctica de dejar el worker corriendo mientras se factura.

## Iniciar el worker (lo normal cada día)

1. En la carpeta del proyecto, doble clic en **`start-queue.bat`**.
2. Se abre una ventana negra que dice *"WORKER DE CORREOS"*.
3. **Dejá esa ventana ABIERTA** todo el tiempo que estés facturando. Mientras esté
   abierta, los correos encolados salen en segundo plano.

Si preferís terminal: abrí la terminal de Laragon en el proyecto y ejecutá
`php artisan queue:work --tries=1 --sleep=3` (es lo mismo que hace el `.bat`).

## Si el worker se cierra o se cae

- La ventana **no se cierra sola**: si el worker se detiene, muestra
  *"El worker SE DETUVO…"* y espera. **Presioná una tecla para reiniciarlo**, o cerrá la
  ventana.
- Si cerraste la ventana por error, simplemente **volvé a abrir `start-queue.bat`**.
- Mientras el worker esté apagado, los correos **quedan en cola** (no se pierden) y salen
  apenas lo vuelvas a encender.

## Ver / gestionar la cola

Desde la terminal de Laragon en el proyecto:

```
php artisan queue:failed        # lista de envíos que fallaron (p. ej. SMTP caído)
php artisan queue:retry all     # reintenta todos los fallidos
php artisan queue:work --stop-when-empty   # procesa lo pendiente UNA vez y termina
```

- **Pendientes:** filas en la tabla `jobs` (esperando worker).
- **Fallidos:** filas en `failed_jobs`. En la ficha del documento, el envío aparece como
  **Fallido** con el error; se puede **Reenviar** desde ahí.

## Tras actualizar el código

Si se despliega/actualiza el código, reiniciá los workers para que tomen los cambios.
Doble clic en **`restart-queue.bat`** (o desde terminal `php artisan queue:restart`).

El worker actual termina su ciclo y arranca de nuevo tomando el código más reciente:
- con `queue-worker-auto.bat` se reinicia **solo** en unos segundos;
- con `start-queue.bat` se reinicia al **pulsar una tecla** (o volvé a abrir el `.bat`).

## Los tres `.bat` (cuál usar)

Todos viven en la carpeta del proyecto y detectan solos el PHP de Laragon (no hace falta
configurar rutas):

| Script | Para qué | Comportamiento |
|--------|----------|----------------|
| `start-queue.bat` | Uso manual del día a día | Ventana visible; si el worker se cae, **espera una tecla** para reiniciar. |
| `queue-worker-auto.bat` | Desatendido (Programador de tareas) | **Se reinicia solo** (sin teclas); recicla memoria cada hora (`--max-time=3600`). |
| `restart-queue.bat` | Tras actualizar el código | Envía `queue:restart` y termina. |

## Recomendado — arrancarlo automáticamente y desatendido (Windows)

Sin instalar nada extra, con el **Programador de tareas** de Windows y `queue-worker-auto.bat`
(que se reinicia solo, sin depender de que alguien pulse teclas):

1. Abrí *Programador de tareas* → **Crear tarea…** (no "básica", para tener las opciones de abajo).
2. Pestaña **General**:
   - Nombre: `Worker correos Facturacion`.
   - Marcá **Ejecutar tanto si el usuario inició sesión como si no** → así corre **en segundo
     plano, sin ventana** (no molesta a quien factura).
   - Marcá **Ejecutar con los privilegios más altos**.
3. Pestaña **Desencadenadores** → **Nuevo** → *Al iniciar el equipo* (o *Al iniciar sesión*).
4. Pestaña **Acciones** → **Nueva** → *Iniciar un programa*:
   - Programa/script: `queue-worker-auto.bat`
   - **Iniciar en (opcional)**: la carpeta del proyecto (donde está el `.bat`).
5. Pestaña **Configuración**: activá **Si la tarea falla, reiniciarla cada** 1 minuto.
6. Aceptar (pedirá la contraseña de Windows del usuario para poder correr sin sesión abierta).

Así el worker queda corriendo siempre, arranca con la PC y se recupera solo. En **Salud del
sistema** el worker debe aparecer como **activo** (el latido se actualiza cada pocos segundos).

### Detener / reiniciar la tarea

- **Detener ahora:** *Programador de tareas* → clic derecho en la tarea → **Finalizar**.
- **Que no arranque más:** clic derecho → **Deshabilitar** (o **Eliminar** para quitarla).
- **Reiniciar el worker tras actualizar:** doble clic en `restart-queue.bat` (el
  `queue-worker-auto.bat` lo vuelve a levantar solo).
- Si preferís verlo en una ventana mientras probás, usá `start-queue.bat` en vez de la tarea.

> Alternativa más robusta (servicio dedicado con supervisión, tipo NSSM/Supervisor): se puede
> evaluar más adelante; requiere instalar una herramienta externa, así que **no** se hace por
> ahora.
