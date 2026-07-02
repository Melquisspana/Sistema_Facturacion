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

Si se despliega/actualiza el código, reiniciá los workers para que tomen los cambios:

```
php artisan queue:restart
```

(El worker actual termina su ciclo y, con `start-queue.bat`, se reinicia solo al pulsar una
tecla; o volvé a abrir el `.bat`.)

## Opcional — arrancarlo automáticamente al iniciar sesión (Windows)

Sin instalar nada extra, con el **Programador de tareas** de Windows:

1. Abrí *Programador de tareas* → **Crear tarea básica**.
2. Nombre: `Worker correos Facturacion`.
3. Desencadenador: **Al iniciar sesión**.
4. Acción: **Iniciar un programa** → Programa/script:
   `C:\laragon\www\Facturacion\start-queue.bat`
5. Finalizar.

Así el worker se abre solo al iniciar sesión en la PC de facturación. Igual conviene ver
que la ventana quede abierta. Para quitarlo, borrá esa tarea del Programador de tareas.

> Alternativa más robusta (servicio en segundo plano con reinicio automático, tipo NSSM):
> se puede evaluar más adelante; requiere instalar una herramienta, así que **no** se hace
> por ahora.
