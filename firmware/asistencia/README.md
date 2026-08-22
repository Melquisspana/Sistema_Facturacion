# Firmware del lector de asistencia (ESP32 + AS608 + TFT ST7735)

Sketch del lector de huella de Dulces La Negrita. Hace dos cosas, **nunca a la vez**:

- **Marcación** — identifica la huella y hace `POST /api/asistencia/marcar`.
- **Enrolamiento remoto** — sondea `GET /api/asistencia/enrolamiento/pendiente`
  y, cuando el servidor deja una orden, captura la huella y la graba **en la
  ranura que dice la orden**.

El contrato con Laravel está documentado en `docs/CONTROL_ASISTENCIA.md`.

## Hardware

| Función | GPIO |
|---|---|
| TFT CS | 5 |
| TFT D/C | 27 |
| TFT RST | 33 |
| TFT SCLK | 18 |
| TFT MOSI (DIN) | 23 |
| AS608 RX | 16 |
| AS608 TX | 17 |

AS608 a 57600 bps por `HardwareSerial(2)`. TFT ST7735 1.44" 128×128,
`INITR_144GREENTAB`, SPI por hardware.

> GPIO16/17 son los pines de PSRAM en módulos **WROVER**. Este firmware asume
> **WROOM-32**. Con un WROVER el sensor no respondería y el arranque se quedaría
> en «SENSOR / Revise conexion».

## Dependencias

Verificadas contra estas versiones exactas:

| | Versión |
|---|---|
| Core `esp32:esp32` | 3.3.11 |
| Adafruit Fingerprint Sensor Library | 2.1.4 |
| Adafruit ST7735 and ST7789 Library | 1.11.0 |
| Adafruit GFX Library | 1.12.6 |

## Configuración

Los secretos **no se versionan**. Antes de compilar:

```powershell
copy firmware\asistencia\secretos.h.example firmware\asistencia\secretos.h
```

y rellenar `secretos.h` con el SSID, la password del Wi-Fi y el token del lector.
El token se obtiene una sola vez:

```powershell
php artisan asistencia:dispositivo lector-entrada --nombre="Entrada principal"
```

Para rotarlo (el firmware anterior deja de autenticar):

```powershell
php artisan asistencia:dispositivo lector-entrada --rotar
```

`secretos.h` está en `.gitignore`. **Nunca** debe aparecer en un commit.

## Compilar

Con el `arduino-cli` que trae el Arduino IDE:

```powershell
$acli = "$env:LOCALAPPDATA\Programs\Arduino IDE\resources\app\lib\backend\resources\arduino-cli.exe"
& $acli compile --fqbn esp32:esp32:esp32 firmware\asistencia
```

Cargar (ajustar el puerto):

```powershell
& $acli upload -p COM3 --fqbn esp32:esp32:esp32 firmware\asistencia
```

## Diagnóstico por Serial

115200 bps. El firmware imprime el ping con credenciales, cada marcación con su
código HTTP y respuesta, y todo el enrolamiento paso a paso con los códigos que
devuelve el AS608.

## Reglas de diseño que no se pueden romper

1. **El enrolamiento es bloqueante respecto al `loop()`.** Mientras se captura no
   se sondea otra orden y no se procesa ninguna marcación. No es comodidad: el
   servidor **reemite el token** de la orden en cada sondeo que la encuentre
   viva, así que un sondeo en paralelo invalidaría el token que está en RAM y el
   resultado final se perdería con un 404 después de haber grabado la plantilla.

2. **El sondeo solo ocurre cuando el AS608 devuelve `FINGERPRINT_NOFINGER`.**
   Antes del `getImage()` le robaría la ventana a la marcación; después le
   quitaría a `identificarHuella()` la imagen que ese mismo `getImage()` capturó.

3. **El firmware nunca elige una ranura.** Usa `orden.ranura` y la devuelve tal
   cual en `fingerprint_id`. Si el servidor recibe otra, no asocia nada.

4. **Nunca se sobrescribe una plantilla.** `loadModel(orden.ranura)` antes de
   grabar; si está ocupada se reporta `ranura_ocupada_en_sensor` con el índice
   real del sensor y se aborta.

5. **`loadModel()` escribe en el charBuffer 1**, el mismo donde `createModel()`
   deja la plantilla recién compuesta. Por eso la comprobación posterior solo es
   segura en este orden: si devuelve `OK` se aborta (da igual el buffer) y si
   devuelve error el sensor no transfirió nada y el modelo sigue intacto.

6. **El barrido del índice (`loadModel` de 0 a capacidad-1) tarda 1-3 s** y jamás
   puede correr con un dedo apoyado. `sincronizarIndiceSensor()` lo comprueba por
   su cuenta antes de empezar.

7. **`setTimeout()` y `setConnectionTimeout()` son milisegundos** en el core
   esp32 3.x. En el core 2.x `setTimeout` eran **segundos**: si alguna vez se
   baja de versión, revisar los dos.

## Pendiente de prueba física

Ver la sección correspondiente en `docs/CONTROL_ASISTENCIA.md`.
