#include <WiFi.h>
#include <SPI.h>
#include <Wire.h>
#include <Preferences.h>
#include <ESP_I2S.h>
#include <Adafruit_GFX.h>
#include <Adafruit_ILI9341.h>
#include <Adafruit_Fingerprint.h>
#include <Fonts/FreeSans9pt7b.h>
#include <Fonts/FreeSansBold9pt7b.h>
#include <Fonts/FreeSansBold12pt7b.h>
#include <Fonts/FreeSansBold18pt7b.h>
#include <Fonts/FreeSansBold24pt7b.h>
#include <time.h>
#include <sys/time.h>

#include "logo.h"

#include "voces.h"

#include "secretos.h"

// =====================================================
// DULCES LA NEGRITA
// CONTROL DE ASISTENCIA
// LCDwiki ES3C28P: ESP32-S3 + ILI9341V 2.8" 240x320 + ES8311/bocina + AS608
// =====================================================
//
// Port del sketch firmware/asistencia (ESP32 WROOM + ST7735 1.44"). La logica
// de marcacion y de enrolamiento remoto es LA MISMA, copiada sin cambios: solo
// cambian la pantalla, los pines del sensor y el sonido (bocina por el codec
// ES8311 en vez del buzzer activo). Las reglas de diseno del README del sketch
// original siguen valiendo al pie de la letra.
//
// Dos modos, MUTUAMENTE EXCLUYENTES:
//
//   MARCACION   el modo normal. El loop sondea el AS608 cada 100 ms y, cuando
//               hay dedo, identifica y hace POST /api/asistencia/marcar.
//
//   ENROLAMIENTO  el servidor deja una orden en un buzon; el lector la recoge
//               sondeando GET /api/asistencia/enrolamiento/pendiente cada 3 s
//               MIENTRAS NO HAY DEDO, y ejecuta la captura de dos huellas.
//
// El enrolamiento es BLOQUEANTE respecto al loop: mientras corre no se sondea
// otra orden y no se procesa ninguna marcacion. No es una optimizacion, es una
// condicion de correccion: el servidor REEMITE el token de la orden en cada
// sondeo que la encuentre viva, asi que un sondeo en paralelo invalidaria el
// token que este firmware tiene en RAM y el resultado final se perderia con un
// 404 despues de haber grabado la plantilla en el sensor.
//
// Las credenciales (SSID, password, token del lector) viven en secretos.h, que
// NO se versiona. La plantilla versionada es secretos.h.example.
// =====================================================


// =====================================================
// VELOCIDAD DE CONSULTA DEL SENSOR
// =====================================================

const uint16_t POLLING_MS =
  100;


// =====================================================
// ENROLAMIENTO REMOTO
// =====================================================

// Cada cuanto se pregunta si hay orden. 3 s = 20 peticiones/min, muy por
// debajo del presupuesto del limitador de Laravel (120/min por lector).
const unsigned long ENROL_SONDEO_MS =
  3000;

// Cuanto se espera a que la persona coloque el dedo, por captura.
const unsigned long ENROL_TIMEOUT_DEDO_MS =
  20000;

// Cuanto se espera a que lo retire entre la primera y la segunda captura.
const unsigned long ENROL_TIMEOUT_RETIRO_MS =
  10000;

// Reintentos del POST de resultado. Es idempotente en el servidor: reintentar
// devuelve el mismo desenlace, nunca una segunda asignacion.
const uint8_t ENROL_MAX_REINTENTOS_RESULTADO =
  3;

// Techo de seguridad del barrido del indice, por si getParameters() devolviera
// una capacidad absurda: 1000 ranuras a ~6 ms son ya 6 s de UART.
const uint16_t ENROL_MAX_RANURAS_BARRIDO =
  1000;


// =====================================================
// HTTP
//
// Los dos son MILISEGUNDOS en el core esp32 3.x:
//
//   setTimeout()            -> Stream::_timeout, el de readStringUntil().
//   setConnectionTimeout()  -> NetworkClient::_timeout, el del connect().
// =====================================================

const unsigned long HTTP_STREAM_TIMEOUT_MS =
  2000;

const unsigned long HTTP_CONNECT_TIMEOUT_MS =
  3000;

// Techo absoluto de una peticion entera. Ninguna espera de red puede pasar de
// aca aunque el servidor mande datos a cuentagotas.
const unsigned long HTTP_TOTAL_TIMEOUT_MS =
  6000;


// =====================================================
// PANTALLA ILI9341V 240x320 (cableada en la placa)
//
// Pines fijos de la ES3C28P segun el wiki del fabricante. El RST del panel va
// al EN del ESP32-S3, por eso no hay pin de reset.
// =====================================================

#define TFT_CS    10
#define TFT_DC    46
#define TFT_SCLK  12
#define TFT_MOSI  11
#define TFT_MISO  13
#define TFT_BL    45

// 0 = vertical con el USB-C abajo; 2 = vertical girada 180 grados. Cambiarlo
// si en la carcasa la pantalla queda de cabeza.
#define PANTALLA_ROTACION 0

// Los paneles IPS de esta placa necesitan la inversion de color encendida.
#define PANTALLA_INVERTIDA true

#define ANCHO_TFT 240
#define ALTO_TFT  320

Adafruit_ILI9341 tft(
  &SPI,
  TFT_DC,
  TFT_CS,
  -1
);


// =====================================================
// SENSOR AS608
//
// Va a los conectores de la placa, no a pines fijos. En vez de adivinar en que
// conector quedo, el arranque prueba los pines libres (y los dos sentidos de
// RX/TX) hasta que el sensor contesta verifyPassword(), y recuerda la pareja
// en NVS para no volver a barrer. Si el cable cambia, el barrido se repite solo.
//
//   UART0 (conector "Serial Port")   IO43 / IO44
//   "Expand Pin"                     IO2 / IO3 / IO14 / IO21
//   I2C (conector y bus del codec)   IO15 / IO16  -> solo como ultimo recurso:
//                                    si el sensor esta ahi, no hay audio.
//
// El USB-C es USB-Serial/JTAG nativo, asi que Serial NO usa el UART0 y IO43/44
// quedan libres para el sensor.
// =====================================================

HardwareSerial FingerSerial(1);

Adafruit_Fingerprint finger(
  &FingerSerial
);

const int8_t FP_PINES_CANDIDATOS[] = {
  44, 43, 2, 3, 14, 21, 15, 16
};

// 57600 es el de fabrica del AS608; los demas por si alguien lo cambio.
const uint32_t FP_BAUDIOS[] = {
  57600, 115200, 9600, 19200, 38400
};

uint32_t fpBaudios =
  57600;

int8_t fpRx =
  -1;

int8_t fpTx =
  -1;

Preferences preferencias;


// =====================================================
// AUDIO: ES8311 + amplificador FM8002E + bocina
//
// El codec se configura por I2C (IO16 SDA / IO15 SCL, direccion 0x18) y recibe
// el audio por I2S. El amplificador se habilita con IO1 en BAJO.
//
// Los tonos se generan en una tarea aparte: beepX() solo encola el patron y
// vuelve al instante, igual que el buzzer del sketch original, para que un
// doble beep no le robe tiempo de sensor al lector.
// =====================================================

#define I2C_SDA     16
#define I2C_SCL     15
#define ES8311_ADDR 0x18

#define AUD_MCLK    4
#define AUD_BCLK    5
#define AUD_DOUT    8
#define AUD_LRCK    7
#define AUD_DIN     6
#define AUD_PA_EN   1

#define LED_RGB     42

const uint32_t AUDIO_HZ =
  16000;

// 0-255. 0xBF = 0 dB en el DAC. Subir o bajar aca el volumen de la bocina.
const uint8_t AUDIO_VOLUMEN =
  0xBF;

I2SClass i2s;

bool audioDisponible =
  false;

QueueHandle_t colaSonidos =
  nullptr;

enum Sonido : uint8_t {
  SONIDO_EXITO,
  SONIDO_ERROR,
  SONIDO_ADVERTENCIA,
  SONIDO_COOLDOWN,
  SONIDO_ARRANQUE,

  // Tono + frase hablada (voces.h).
  SONIDO_VOZ_ENTRADA,
  SONIDO_VOZ_SALIDA,
  SONIDO_VOZ_YA_MARCASTE,
  SONIDO_VOZ_NO_REGISTRADA
};

// false = solo tonos, como antes de las voces.
#define LECTOR_HABLA true


// =====================================================
// ESTADOS DE PANTALLA
// =====================================================

enum EstadoPantalla {
  PANTALLA_NINGUNA,
  PANTALLA_LISTO,
  PANTALLA_LEYENDO,
  PANTALLA_REGISTRANDO,
  PANTALLA_EXITO,
  PANTALLA_DESCONOCIDA,
  PANTALLA_AJUSTAR,
  PANTALLA_COOLDOWN,
  PANTALLA_ERROR,

  // Enrolamiento
  PANTALLA_ENROL_INICIO,
  PANTALLA_ENROL_COLOQUE,
  PANTALLA_ENROL_RETIRE,
  PANTALLA_ENROL_REPITA,
  PANTALLA_ENROL_GUARDANDO,
  PANTALLA_ENROL_OK,
  PANTALLA_ENROL_FALLO,
  PANTALLA_ENROL_INDICE
};

EstadoPantalla pantallaActual =
  PANTALLA_NINGUNA;

// Contenido variable de la pantalla actual (el nombre de la persona, la ranura,
// el motivo). Sin esto, el guard antiparpadeo se comeria el repintado cuando
// cambia el DATO pero no el ESTADO: dos ordenes seguidas de personas distintas
// dejarian el nombre de la primera en pantalla.
String pantallaDetalle =
  "";


// =====================================================
// RESULTADOS HUELLA
// =====================================================

enum ResultadoHuella {
  RH_RECONOCIDA,
  RH_DESCONOCIDA,
  RH_INDETERMINADA,
  RH_SIN_LECTURA,
  RH_RETIRADA
};


// =====================================================
// RESPUESTA API
// =====================================================

struct RespuestaAPI {
  int httpCode;
  bool ok;
  String estado;
  String mensaje;
  String nombre;
  String tipo;
  String hora;
  int esperaSegundos;
};


// =====================================================
// ORDEN DE ENROLAMIENTO
//
// Lo que entrega GET /enrolamiento/pendiente. El token es la UNICA vez que ese
// valor sale del servidor: no se puede volver a pedir, solo re-sondear.
// =====================================================

struct OrdenEnrolamiento {
  bool valida;
  int id;
  int ranura;
  int capacidad;
  int intento;
  int expiraEn;
  String nombreCorto;
  String token;
};


// =====================================================
// ESTADO
// =====================================================

bool servidorDisponible =
  false;

bool dispositivoAutorizado =
  false;

bool wifiAnterior =
  false;

unsigned long ultimoChequeoWifi =
  0;

// ------------------------------------- enrolamiento

// Verdadero mientras se ejecuta una captura. El loop no llega a evaluarlo
// porque ejecutarEnrolamiento() bloquea, pero existe como cinturon: cualquier
// camino que quisiera sondear o barrer el indice tiene que consultarlo.
bool enrolando =
  false;

unsigned long ultimoSondeoEnrolamiento =
  0;

// Capacidad real del sensor, tal como la reporta getParameters(). Cero
// significa que todavia no se pudo leer y que no hay que mandar indice.
uint16_t capacidadSensor =
  0;

// Resultado final que no se pudo entregar por un corte de red. Se reintenta
// desde el loop en reposo. El endpoint es idempotente, asi que reenviarlo no
// duplica nada.
bool resultadoPendiente =
  false;

int resultadoPendienteOrden =
  0;

String resultadoPendienteCuerpo =
  "";

unsigned long proximoReintentoPendiente =
  0;


// =====================================================
// PROTOTIPOS
// =====================================================

String normalizarTextoTFT(
  String texto
);

void dibujarCabecera();
void dibujarFooter();
void dibujarTarjetaLista();

void mostrarInicio();
void mostrarListo();
void mostrarLeyendo();
void mostrarRegistrando();

void mostrarExito(
  String nombre,
  String tipo,
  String hora
);

void mostrarDesconocida();
void mostrarAjustarDedo();

void mostrarCooldown(
  int segundos
);

void mostrarError(
  String titulo,
  String detalle
);

void conectarWiFi();
void mantenerWiFi();

bool probarServidor();

ResultadoHuella identificarHuella(
  uint16_t &id,
  uint16_t &confianza
);

void esperarRetiroConMensaje(
  unsigned long minimoMs,
  unsigned long maximoMs
);

RespuestaAPI enviarMarcacion(
  uint16_t fingerprintID
);

String obtenerValorJson(
  const String& json,
  const String& clave
);

int obtenerIntJson(
  const String& json,
  const String& clave
);

bool obtenerBoolJson(
  const String& json,
  const String& clave
);

String extraerObjetoJson(
  const String& json,
  const String& clave
);

// ------------------------------------- enrolamiento

void mostrarEnrolInicio(
  String nombre,
  int intento
);

void mostrarEnrolColoque(
  String nombre
);

void mostrarEnrolRetire();

void mostrarEnrolRepita(
  String nombre
);

void mostrarEnrolGuardando(
  int ranura
);

void mostrarEnrolOk(
  String nombre,
  int ranura
);

void mostrarEnrolFallo(
  String titulo,
  String detalle
);

void mostrarEnrolIndice();

int peticionEnrolamiento(
  const char* metodo,
  const String& ruta,
  const String& cuerpo,
  String& respuesta
);

bool sincronizarIndiceSensor();

String construirListaOcupadas(
  uint16_t &capacidadEfectiva
);

void atenderEnrolamiento();

void sondearEnrolamiento();

void ejecutarEnrolamiento(
  const OrdenEnrolamiento& orden
);

void reportarProgreso(
  const OrdenEnrolamiento& orden,
  const char* etapa
);

bool reportarResultado(
  const OrdenEnrolamiento& orden,
  const String& cuerpo
);

void fallarEnrolamiento(
  const OrdenEnrolamiento& orden,
  const char* motivo,
  const String& detalle,
  const String& titulo,
  const String& subtitulo,
  bool adjuntarIndice
);

bool esImagenFantasma(
  uint8_t conversion
);

uint8_t esperarDedoEnrolamiento(
  unsigned long maximoMs,
  uint8_t buffer,
  uint8_t& errorCaptura
);

bool esperarRetiroDeEnrolamiento(
  unsigned long maximoMs
);

uint8_t estadoRanuraEnSensor(
  uint16_t ranura
);


bool es8311Escribir(
  uint8_t reg,
  uint8_t valor
) {

  Wire.beginTransmission(
    ES8311_ADDR
  );

  Wire.write(reg);
  Wire.write(valor);

  return Wire.endTransmission() == 0;
}


// Secuencia minima de la hoja de datos / driver esp-adf para usar solo el DAC,
// en esclavo, MCLK = 256 * fs y palabras de 16 bits.
bool es8311Iniciar() {

  Wire.beginTransmission(
    ES8311_ADDR
  );

  if (
    Wire.endTransmission() != 0
  ) {
    return false;
  }

  const uint8_t secuencia[][2] = {
    {0x00, 0x1F}, // reset
    {0x45, 0x00},
    {0x00, 0x80}, // esclavo, encendido
    {0x01, 0x3F}, // MCLK del pin, todos los relojes activos
    {0x02, 0x00}, // pre_div 1, pre_multi x1
    {0x03, 0x10}, // fs simple, adc_osr
    {0x04, 0x10}, // dac_osr
    {0x05, 0x00}, // adc_div / dac_div 1
    {0x06, 0x03}, // bclk_div 4
    {0x07, 0x00}, // lrck alto
    {0x08, 0xFF}, // lrck bajo = 256
    {0x09, 0x0C}, // SDP entrada (DAC) I2S 16 bits
    {0x0A, 0x0C}, // SDP salida (ADC) I2S 16 bits
    {0x0B, 0x00},
    {0x0C, 0x00},
    {0x10, 0x1F},
    {0x11, 0x7F},
    {0x13, 0x10},
    {0x0D, 0x01}, // encender analogico
    {0x0E, 0x02},
    {0x12, 0x00}, // encender DAC
    {0x14, 0x1A},
    {0x15, 0x40},
    {0x1B, 0x0A},
    {0x1C, 0x6A},
    {0x37, 0x08}, // rampa del DAC
    {0x32, AUDIO_VOLUMEN},
    {0x31, 0x00}  // sin silencio
  };

  for (
    size_t i = 0;
    i < sizeof(secuencia) / sizeof(secuencia[0]);
    i++
  ) {

    if (
      !es8311Escribir(
        secuencia[i][0],
        secuencia[i][1]
      )
    ) {
      return false;
    }

    if (
      i == 0
    ) {
      delay(20);
    }
  }

  return true;
}


// Escribe un tono (o silencio si hz == 0) de ms milisegundos. Bloquea, pero
// solo a la tarea de audio.
void audioTono(
  uint16_t hz,
  uint16_t ms
) {

  const size_t MUESTRAS_BLOQUE =
    256;

  int16_t bloque[MUESTRAS_BLOQUE * 2];

  uint32_t total =
    (uint32_t) AUDIO_HZ * ms / 1000;

  // Fundido de 4 ms en los bordes para que no chasquee.
  uint32_t fundido =
    AUDIO_HZ / 250;

  static float fase =
    0;

  float paso =
    2.0f * PI * hz / AUDIO_HZ;

  uint32_t hechas =
    0;

  while (
    hechas < total
  ) {

    size_t n =
      min(
        (uint32_t) MUESTRAS_BLOQUE,
        total - hechas
      );

    for (
      size_t i = 0;
      i < n;
      i++
    ) {

      int16_t v =
        0;

      if (
        hz > 0
      ) {

        uint32_t k =
          hechas + i;

        float env =
          1.0f;

        if (k < fundido) {
          env = (float) k / fundido;
        } else if (total - k < fundido) {
          env = (float) (total - k) / fundido;
        }

        v =
          (int16_t) (sinf(fase) * 12000.0f * env);

        fase += paso;

        if (fase > 2.0f * PI) {
          fase -= 2.0f * PI;
        }
      }

      bloque[i * 2] = v;
      bloque[i * 2 + 1] = v;
    }

    i2s.write(
      (uint8_t*) bloque,
      n * 2 * sizeof(int16_t)
    );

    hechas += n;
  }
}


// Escribe una frase grabada (PCM mono de voces.h, a AUDIO_HZ) en los dos
// canales. Bloquea, pero solo a la tarea de audio.
void audioFrase(
  const int16_t* pcm,
  uint32_t total
) {

  const size_t MUESTRAS_BLOQUE =
    256;

  int16_t bloque[MUESTRAS_BLOQUE * 2];

  uint32_t hechas =
    0;

  while (
    hechas < total
  ) {

    size_t n =
      min(
        (uint32_t) MUESTRAS_BLOQUE,
        total - hechas
      );

    for (
      size_t i = 0;
      i < n;
      i++
    ) {

      bloque[i * 2] = pcm[hechas + i];
      bloque[i * 2 + 1] = pcm[hechas + i];
    }

    i2s.write(
      (uint8_t*) bloque,
      n * 2 * sizeof(int16_t)
    );

    hechas += n;
  }
}


void tareaAudio(
  void* arg
) {

  Sonido s;

  for (;;) {

    if (
      xQueueReceive(
        colaSonidos,
        &s,
        portMAX_DELAY
      ) != pdTRUE
    ) {
      continue;
    }

    digitalWrite(
      AUD_PA_EN,
      LOW
    );

    switch (s) {

      // 1 tono ascendente: Laravel confirmo la marcacion.
      case SONIDO_EXITO:
        audioTono(1318, 90);
        audioTono(1760, 140);
        break;

      // 2 tonos graves: el dedo no vale.
      case SONIDO_ERROR:
        audioTono(330, 140);
        audioTono(0, 70);
        audioTono(330, 140);
        break;

      // 1 tono largo: el problema es la red, el token o el servidor.
      case SONIDO_ADVERTENCIA:
        audioTono(520, 420);
        break;

      // 3 chasquidos: ya marco, no se duplico.
      case SONIDO_COOLDOWN:
        audioTono(990, 50);
        audioTono(0, 60);
        audioTono(990, 50);
        audioTono(0, 60);
        audioTono(990, 50);
        break;

      case SONIDO_ARRANQUE:
        audioTono(880, 80);
        audioTono(1175, 80);
        audioTono(1568, 120);
        break;

      // Las voces repiten el tono de su caso y despues dicen la frase: el
      // tono avisa al instante y la frase confirma que paso.
      case SONIDO_VOZ_ENTRADA:
      case SONIDO_VOZ_SALIDA:
        audioTono(1318, 90);
        audioTono(1760, 140);
        audioTono(0, 120);
        if (s == SONIDO_VOZ_ENTRADA) {
          audioFrase(VOZ_ENTRADA_PCM, VOZ_ENTRADA_LARGO);
        } else {
          audioFrase(VOZ_SALIDA_PCM, VOZ_SALIDA_LARGO);
        }
        break;

      case SONIDO_VOZ_YA_MARCASTE:
        audioTono(990, 50);
        audioTono(0, 60);
        audioTono(990, 50);
        audioTono(0, 120);
        audioFrase(VOZ_YA_MARCASTE_PCM, VOZ_YA_MARCASTE_LARGO);
        break;

      case SONIDO_VOZ_NO_REGISTRADA:
        audioTono(330, 140);
        audioTono(0, 70);
        audioTono(330, 140);
        audioTono(0, 120);
        audioFrase(VOZ_NO_REGISTRADA_PCM, VOZ_NO_REGISTRADA_LARGO);
        break;
    }

    // Cola de silencio para vaciar el DMA antes de apagar el amplificador.
    audioTono(0, 40);

    digitalWrite(
      AUD_PA_EN,
      HIGH
    );
  }
}


void iniciarAudio() {

  pinMode(
    AUD_PA_EN,
    OUTPUT
  );

  digitalWrite(
    AUD_PA_EN,
    HIGH
  );

  Wire.begin(
    I2C_SDA,
    I2C_SCL,
    100000
  );

  i2s.setPins(
    AUD_BCLK,
    AUD_LRCK,
    AUD_DOUT,
    AUD_DIN,
    AUD_MCLK
  );

  if (
    !i2s.begin(
      I2S_MODE_STD,
      AUDIO_HZ,
      I2S_DATA_BIT_WIDTH_16BIT,
      I2S_SLOT_MODE_STEREO
    )
  ) {

    Serial.println(
      "AUDIO: no arranco el I2S"
    );

    return;
  }

  // El codec necesita MCLK corriendo antes de configurarse.
  delay(10);

  if (
    !es8311Iniciar()
  ) {

    Serial.println(
      "AUDIO: el ES8311 no contesta"
    );

    return;
  }

  colaSonidos =
    xQueueCreate(
      4,
      sizeof(Sonido)
    );

  xTaskCreatePinnedToCore(
    tareaAudio,
    "audio",
    6144,
    nullptr,
    1,
    nullptr,
    0
  );

  audioDisponible =
    true;

  Serial.println(
    "AUDIO OK"
  );
}


void sonar(
  Sonido s
) {

  if (
    !audioDisponible
  ) {
    return;
  }

  // Un sonido nuevo reemplaza a los que esperaban: nunca se acumula un eco de
  // marcaciones viejas.
  xQueueReset(
    colaSonidos
  );

  xQueueSend(
    colaSonidos,
    &s,
    0
  );
}


// -------------------------------------------------- LED RGB (WS2812 en IO42)

void led(
  uint8_t r,
  uint8_t g,
  uint8_t b
) {

  rgbLedWrite(
    LED_RGB,
    r,
    g,
    b
  );
}


// -------------------------------------------------- API del sketch original
//
// El resto del firmware llama a estas funciones igual que antes. atenderBuzzer()
// ya no tiene sonido que avanzar (la tarea de audio lo hace sola); como el loop
// la llama en cada vuelta, se aprovecha para refrescar el reloj en reposo.

void actualizarReloj();

void atenderBuzzer() {

  actualizarReloj();
}


void pausaConBuzzer(
  unsigned long ms
) {

  delay(ms);
}


void beepExito() {

  led(0, 60, 0);
  sonar(SONIDO_EXITO);
}


void beepError() {

  led(60, 0, 0);
  sonar(SONIDO_ERROR);
}


void beepAdvertencia() {

  led(60, 30, 0);
  sonar(SONIDO_ADVERTENCIA);
}


void beepCooldown() {

  led(50, 50, 0);
  sonar(SONIDO_COOLDOWN);
}


// -------------------------------------------------- Con voz (LECTOR_HABLA)
//
// Mismo LED que su beep; el sonido es el tono de siempre seguido de la frase.
// `tipo` es el tipo_label que manda Laravel («Entrada» / «Salida»). Si llega
// otra cosa no se adivina: solo suena el tono de exito.

void avisarMarcacion(
  const String& tipo
) {

  led(0, 60, 0);

  if (!LECTOR_HABLA) {
    sonar(SONIDO_EXITO);
  } else if (tipo.equalsIgnoreCase("Entrada")) {
    sonar(SONIDO_VOZ_ENTRADA);
  } else if (tipo.equalsIgnoreCase("Salida")) {
    sonar(SONIDO_VOZ_SALIDA);
  } else {
    sonar(SONIDO_EXITO);
  }
}


void avisarYaMarcaste() {

  led(50, 50, 0);
  sonar(LECTOR_HABLA ? SONIDO_VOZ_YA_MARCASTE : SONIDO_COOLDOWN);
}


void avisarNoRegistrada() {

  led(60, 0, 0);
  sonar(LECTOR_HABLA ? SONIDO_VOZ_NO_REGISTRADA : SONIDO_ERROR);
}


// =====================================================
// COLORES
// =====================================================

#define COLOR_NEGRO      ILI9341_BLACK
#define COLOR_BLANCO     ILI9341_WHITE
#define COLOR_ROJO       ILI9341_RED
#define COLOR_VERDE      ILI9341_GREEN
#define COLOR_AMARILLO   ILI9341_YELLOW
#define COLOR_CYAN       ILI9341_CYAN

#define COLOR_GRIS       0x8410
#define COLOR_GRIS_OSC   0x2104
#define COLOR_GRIS_MED   0x4208
#define COLOR_ROJO_OSC   0x8000
#define COLOR_VERDE_OSC  0x0320
#define COLOR_AZUL_OSC   0x0010
#define COLOR_AMBAR_OSC  0x6320
#define COLOR_GRIS_CLARO 0xC618
#define COLOR_NARANJA    0xFD20

// Marca (los mismos tonos del logo). COLOR_CREMA tiene que coincidir con FONDO
// de herramientas/generar_logo.py: el logo viene ya mezclado sobre ese color.
#define COLOR_CREMA      0xFFBC
#define COLOR_ROJO_MARCA 0xE225
#define COLOR_TINTA      0x18C2
#define COLOR_CAFE       0x7AE9
#define COLOR_FONDO      0x0841


// =====================================================
// TEXTO
//
// Fuentes FreeSans de Adafruit_GFX (solo ASCII: los acentos se normalizan).
// Con una fuente GFX el cursor marca la LINEA BASE, no la esquina; tc() recibe
// la Y de la parte de ARRIBA del texto y hace la cuenta.
// =====================================================

#define F_CHICA   (&FreeSans9pt7b)
#define F_NEGRITA (&FreeSansBold9pt7b)
#define F_MEDIA   (&FreeSansBold12pt7b)
#define F_GRANDE  (&FreeSansBold18pt7b)
#define F_ENORME  (&FreeSansBold24pt7b)

const int MARGEN =
  10;

const int ANCHO_UTIL =
  ANCHO_TFT - 2 * MARGEN;

// Geometria comun: cabecera 0-91, tarjeta 100-271, pie 280-319.
const int CABECERA_ALTO =
  92;

const int TARJETA_Y =
  100;

const int TARJETA_ALTO =
  172;

const int PIE_Y =
  280;


// Alto de las mayusculas de una fuente: lo que separa la linea base de la
// parte de arriba del texto.
int altoMayusculas(
  const GFXfont* f
) {

  int16_t x1, y1;
  uint16_t w, h;

  tft.setFont(f);
  tft.setTextSize(1);
  tft.getTextBounds("H", 0, 0, &x1, &y1, &w, &h);

  return -y1;
}


int anchoTexto(
  const String& texto,
  const GFXfont* f
) {

  int16_t x1, y1;
  uint16_t w, h;

  tft.setFont(f);
  tft.setTextSize(1);
  tft.getTextBounds(texto, 0, 0, &x1, &y1, &w, &h);

  return w + x1;
}


// Texto centrado en X, con la parte de arriba en yTop.
void tc(
  const String& texto,
  int yTop,
  const GFXfont* f,
  uint16_t color
) {

  int ancho =
    anchoTexto(texto, f);

  int x =
    (ANCHO_TFT - ancho) / 2;

  if (x < 0) {
    x = 0;
  }

  tft.setFont(f);
  tft.setTextColor(color);
  tft.setCursor(x, yTop + altoMayusculas(f));
  tft.print(texto);
}


// La fuente mas grande (de la lista, en orden) con la que el texto cabe en una
// linea. Si ni la mas chica alcanza, se recorta con "..".
void tcAjustado(
  String texto,
  int yTop,
  const GFXfont* const* fuentes,
  uint8_t total,
  uint16_t color
) {

  for (
    uint8_t i = 0;
    i < total;
    i++
  ) {

    if (
      anchoTexto(texto, fuentes[i]) <= ANCHO_UTIL
    ) {

      // Centrado vertical respecto de la fuente mas grande.
      int dy =
        (altoMayusculas(fuentes[0]) - altoMayusculas(fuentes[i])) / 2;

      tc(texto, yTop + dy, fuentes[i], color);

      return;
    }
  }

  const GFXfont* chica =
    fuentes[total - 1];

  while (
    texto.length() > 3 &&
    anchoTexto(texto + "..", chica) > ANCHO_UTIL
  ) {
    texto.remove(texto.length() - 1);
  }

  tc(
    texto + "..",
    yTop + (altoMayusculas(fuentes[0]) - altoMayusculas(chica)) / 2,
    chica,
    color
  );
}


const GFXfont* const FUENTES_NOMBRE[] = {
  F_GRANDE, F_MEDIA, F_NEGRITA
};

const GFXfont* const FUENTES_DETALLE[] = {
  F_MEDIA, F_NEGRITA, F_CHICA
};


String normalizarTextoTFT(
  String texto
) {

  texto.replace("á", "a");
  texto.replace("é", "e");
  texto.replace("í", "i");
  texto.replace("ó", "o");
  texto.replace("ú", "u");
  texto.replace("ü", "u");

  texto.replace("Á", "A");
  texto.replace("É", "E");
  texto.replace("Í", "I");
  texto.replace("Ó", "O");
  texto.replace("Ú", "U");
  texto.replace("Ü", "U");

  texto.replace("ñ", "n");
  texto.replace("Ñ", "N");

  texto.replace("¡", "");
  texto.replace("¿", "");

  return texto;
}


// "ANA MARIA LOPEZ" -> "Ana Maria Lopez".
String tipoTitulo(
  String texto
) {

  texto =
    normalizarTextoTFT(texto);

  texto.toLowerCase();

  bool inicio =
    true;

  for (
    unsigned int i = 0;
    i < texto.length();
    i++
  ) {

    char c =
      texto[i];

    if (
      inicio &&
      c >= 'a' &&
      c <= 'z'
    ) {
      texto.setCharAt(i, c - 32);
    }

    inicio =
      c == ' ' ||
      c == '-';
  }

  return texto;
}


// =====================================================
// RELOJ
//
// Solo para MOSTRAR. La hora de las marcaciones la sigue poniendo el servidor
// (regla 1 del modulo). El reloj se pone en hora con el `epoch` que devuelve
// el ping, y la zona es la de El Salvador (UTC-6, sin horario de verano).
// =====================================================

bool relojEnHora =
  false;

int minutoPintado =
  -1;


void ponerRelojDesdePing(
  const String& respuesta
) {

  int cuerpo =
    respuesta.indexOf("\r\n\r\n");

  String json =
    cuerpo >= 0
      ? respuesta.substring(cuerpo + 4)
      : respuesta;

  long epoch =
    obtenerIntJson(json, "epoch");

  // Cualquier cosa antes de 2024 es basura o una clave que no llego.
  if (
    epoch < 1704067200L
  ) {
    return;
  }

  struct timeval tv = {
    (time_t) epoch,
    0
  };

  settimeofday(&tv, nullptr);

  setenv("TZ", "CST6", 1);
  tzset();

  relojEnHora =
    true;

  Serial.print("Reloj en hora desde el servidor: ");
  Serial.println(epoch);
}


bool horaLocal(
  struct tm& t
) {

  if (
    !relojEnHora
  ) {
    return false;
  }

  time_t ahora =
    time(nullptr);

  localtime_r(&ahora, &t);

  return true;
}


String saludo() {

  struct tm t;

  if (
    !horaLocal(t)
  ) {
    return "Hola";
  }

  if (t.tm_hour < 12) {
    return "Buenos dias";
  }

  if (t.tm_hour < 19) {
    return "Buenas tardes";
  }

  return "Buenas noches";
}


String fechaLarga() {

  struct tm t;

  if (
    !horaLocal(t)
  ) {
    return "";
  }

  static const char* const DIAS[] = {
    "Domingo", "Lunes", "Martes", "Miercoles", "Jueves", "Viernes", "Sabado"
  };

  static const char* const MESES[] = {
    "ene", "feb", "mar", "abr", "may", "jun",
    "jul", "ago", "sep", "oct", "nov", "dic"
  };

  return String(DIAS[t.tm_wday]) + " " +
         String(t.tm_mday) + " " +
         MESES[t.tm_mon];
}


// =====================================================
// ICONOS
// =====================================================

void iconoOk(
  int cx,
  int cy,
  uint16_t color,
  uint16_t fondo
) {

  tft.fillCircle(cx, cy, 24, color);

  for (int g = -2; g <= 2; g++) {
    tft.drawLine(cx - 12, cy + g, cx - 4, cy + 8 + g, fondo);
    tft.drawLine(cx - 4, cy + 8 + g, cx + 12, cy - 9 + g, fondo);
  }
}


void iconoX(
  int cx,
  int cy,
  uint16_t color,
  uint16_t fondo
) {

  tft.fillCircle(cx, cy, 24, color);

  for (int g = -2; g <= 2; g++) {
    tft.drawLine(cx - 9 + g, cy - 9, cx + 9 + g, cy + 9, fondo);
    tft.drawLine(cx + 9 + g, cy - 9, cx - 9 + g, cy + 9, fondo);
  }
}


void iconoAviso(
  int cx,
  int cy,
  uint16_t color,
  uint16_t fondo
) {

  tft.fillTriangle(cx, cy - 24, cx - 26, cy + 20, cx + 26, cy + 20, color);
  tft.fillRoundRect(cx - 3, cy - 10, 6, 18, 2, fondo);
  tft.fillCircle(cx, cy + 13, 3, fondo);
}


// Huella estilizada: arcos concentricos abiertos por abajo.
void iconoHuella(
  int cx,
  int cy,
  uint16_t color
) {

  for (int r = 5; r <= 29; r += 6) {
    for (int g = 0; g < 2; g++) {
      tft.drawCircleHelper(cx, cy, r - g, 0x1 | 0x2, color);
    }
    int largo = 18 - r / 2;
    tft.fillRect(cx - r, cy, 2, largo, color);
    tft.fillRect(cx + r - 1, cy, 2, largo, color);
  }
}


// =====================================================
// PIEZAS COMUNES
// =====================================================

void dibujarCabecera() {

  tft.fillRect(0, 0, ANCHO_TFT, CABECERA_ALTO, COLOR_CREMA);

  tft.drawRGBBitmap(
    12,
    (CABECERA_ALTO - LOGO_CHICO_ALTO) / 2,
    LOGO_CHICO,
    LOGO_CHICO_ANCHO,
    LOGO_CHICO_ALTO
  );

  const int x =
    12 + LOGO_CHICO_ANCHO + 12;

  tft.setTextColor(COLOR_ROJO_MARCA);
  tft.setFont(F_MEDIA);
  tft.setCursor(x, 18 + altoMayusculas(F_MEDIA));
  tft.print("Dulces");

  tft.setTextColor(COLOR_TINTA);
  tft.setCursor(x, 44 + altoMayusculas(F_MEDIA));
  tft.print("La Negrita");

  tft.setTextColor(COLOR_CAFE);
  tft.setFont(F_CHICA);
  tft.setCursor(x, 72 + altoMayusculas(F_CHICA));
  tft.print("Asistencia");

  tft.fillRect(0, CABECERA_ALTO - 4, ANCHO_TFT, 4, COLOR_ROJO_MARCA);
}


void dibujarFooter() {

  tft.fillRect(0, PIE_Y, ANCHO_TFT, ALTO_TFT - PIE_Y, COLOR_FONDO);

  const int y =
    PIE_Y + 13;

  bool wifi =
    WiFi.status() == WL_CONNECTED;

  bool api =
    servidorDisponible &&
    dispositivoAutorizado;

  tft.fillRoundRect(10, y - 4, 104, 24, 12, COLOR_GRIS_OSC);
  tft.fillCircle(24, y + 8, 5, wifi ? COLOR_VERDE : COLOR_ROJO);
  tft.setFont(F_NEGRITA);
  tft.setTextColor(COLOR_BLANCO);
  tft.setCursor(36, y + altoMayusculas(F_NEGRITA) + 1);
  tft.print("Wi-Fi");

  tft.fillRoundRect(126, y - 4, 104, 24, 12, COLOR_GRIS_OSC);
  tft.fillCircle(140, y + 8, 5, api ? COLOR_VERDE : COLOR_ROJO);
  tft.setCursor(152, y + altoMayusculas(F_NEGRITA) + 1);
  tft.print("Sistema");
}


void tarjeta(
  uint16_t fondo,
  uint16_t borde
) {

  tft.fillRoundRect(
    MARGEN,
    TARJETA_Y,
    ANCHO_TFT - 2 * MARGEN,
    TARJETA_ALTO,
    14,
    fondo
  );

  if (
    borde != fondo
  ) {

    tft.drawRoundRect(
      MARGEN,
      TARJETA_Y,
      ANCHO_TFT - 2 * MARGEN,
      TARJETA_ALTO,
      14,
      borde
    );

    tft.drawRoundRect(
      MARGEN + 1,
      TARJETA_Y + 1,
      ANCHO_TFT - 2 * MARGEN - 2,
      TARJETA_ALTO - 2,
      13,
      borde
    );
  }
}


// Pantalla completa: cabecera, tarjeta de color y pie. Devuelve la Y donde
// empieza el contenido de la tarjeta.
int pantallaBase(
  uint16_t fondo,
  uint16_t borde
) {

  tft.fillRect(0, CABECERA_ALTO, ANCHO_TFT, PIE_Y - CABECERA_ALTO, COLOR_FONDO);

  dibujarCabecera();
  tarjeta(fondo, borde);
  dibujarFooter();

  return TARJETA_Y;
}


// =====================================================
// ARRANQUE
// =====================================================

void mostrarInicio() {

  pantallaActual =
    PANTALLA_NINGUNA;

  tft.fillScreen(COLOR_CREMA);

  tft.drawRGBBitmap(
    (ANCHO_TFT - LOGO_GRANDE_ANCHO) / 2,
    22,
    LOGO_GRANDE,
    LOGO_GRANDE_ANCHO,
    LOGO_GRANDE_ALTO
  );

  tc("Dulces", 204, F_MEDIA, COLOR_ROJO_MARCA);
  tc("La Negrita", 230, F_GRANDE, COLOR_TINTA);

  tft.fillRect(0, ALTO_TFT - 6, ANCHO_TFT, 6, COLOR_ROJO_MARCA);
}


// Mensaje de estado del arranque, debajo del nombre. Sin guard: solo setup().
void mostrarArranque(
  String texto
) {

  tft.fillRect(0, 272, ANCHO_TFT, 34, COLOR_CREMA);

  tc(
    normalizarTextoTFT(texto),
    282,
    F_CHICA,
    COLOR_CAFE
  );
}


// =====================================================
// LISTO (reposo)
// =====================================================

void dibujarRelojListo() {

  const int y =
    TARJETA_Y + 18;

  tft.fillRect(MARGEN + 4, y - 2, ANCHO_TFT - 2 * MARGEN - 8, 62, COLOR_GRIS_OSC);

  struct tm t;

  if (
    horaLocal(t)
  ) {

    char hhmm[6];

    snprintf(hhmm, sizeof(hhmm), "%02d:%02d", t.tm_hour, t.tm_min);

    tc(hhmm, y, F_ENORME, COLOR_BLANCO);
    tc(fechaLarga(), y + 44, F_CHICA, COLOR_GRIS_CLARO);

    minutoPintado =
      t.tm_min;

  } else {

    tc("Bienvenido", y + 12, F_GRANDE, COLOR_BLANCO);

    minutoPintado =
      -1;
  }
}


void dibujarTarjetaLista() {

  tarjeta(COLOR_GRIS_OSC, COLOR_GRIS_OSC);

  dibujarRelojListo();

  tft.drawFastHLine(40, TARJETA_Y + 86, ANCHO_TFT - 80, COLOR_GRIS);

  iconoHuella(ANCHO_TFT / 2, TARJETA_Y + 116, COLOR_VERDE);

  tc("Coloque su dedo", TARJETA_Y + 144, F_NEGRITA, COLOR_VERDE);
}


void mostrarListo() {

  if (
    pantallaActual ==
    PANTALLA_LISTO
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_LISTO;

  pantallaDetalle =
    "";

  led(0, 0, 0);

  tft.fillRect(0, CABECERA_ALTO, ANCHO_TFT, PIE_Y - CABECERA_ALTO, COLOR_FONDO);

  dibujarCabecera();
  dibujarTarjetaLista();
  dibujarFooter();
}


// Llamada desde el loop (via atenderBuzzer): repinta SOLO el reloj cuando cambia
// el minuto, y solo en reposo. Sin parpadeo del resto de la pantalla.
void actualizarReloj() {

  if (
    pantallaActual !=
    PANTALLA_LISTO
  ) {
    return;
  }

  struct tm t;

  if (
    !horaLocal(t) ||
    t.tm_min == minutoPintado
  ) {
    return;
  }

  dibujarRelojListo();
}


// =====================================================
// MARCACION
// =====================================================

void mostrarLeyendo() {

  if (
    pantallaActual ==
    PANTALLA_LEYENDO
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_LEYENDO;

  led(0, 0, 50);

  int y =
    pantallaBase(COLOR_GRIS_OSC, COLOR_AMARILLO);

  iconoHuella(ANCHO_TFT / 2, y + 50, COLOR_AMARILLO);
  tc("Leyendo...", y + 88, F_GRANDE, COLOR_AMARILLO);
  tc("No retire el dedo", y + 132, F_CHICA, COLOR_BLANCO);
}


void mostrarRegistrando() {

  pantallaActual =
    PANTALLA_REGISTRANDO;

  int y =
    pantallaBase(COLOR_GRIS_OSC, COLOR_CYAN);

  tc("Registrando", y + 52, F_GRANDE, COLOR_CYAN);
  tc("Un momento...", y + 100, F_CHICA, COLOR_BLANCO);
}


void mostrarExito(
  String nombre,
  String tipo,
  String hora
) {

  pantallaActual =
    PANTALLA_EXITO;

  nombre =
    tipoTitulo(nombre);

  tipo =
    normalizarTextoTFT(tipo);

  tipo.toUpperCase();

  if (
    hora.length() > 5
  ) {
    hora =
      hora.substring(0, 5);
  }

  bool esSalida =
    tipo.indexOf("SALIDA") >= 0;

  uint16_t acento =
    esSalida ? COLOR_NARANJA : COLOR_VERDE;

  int y =
    pantallaBase(COLOR_VERDE_OSC, acento);

  iconoOk(ANCHO_TFT / 2, y + 32, acento, COLOR_VERDE_OSC);

  tc(
    esSalida ? String("Hasta luego") : saludo(),
    y + 64,
    F_CHICA,
    COLOR_GRIS_CLARO
  );

  tcAjustado(nombre, y + 86, FUENTES_NOMBRE, 3, COLOR_BLANCO);

  // Pastilla con el tipo y la hora oficial que devolvio el servidor.
  String pastilla =
    hora.length() > 0
      ? tipo + "  " + hora
      : tipo;

  int ancho =
    anchoTexto(pastilla, F_MEDIA) + 28;

  tft.fillRoundRect((ANCHO_TFT - ancho) / 2, y + 126, ancho, 34, 17, acento);
  tc(pastilla, y + 134, F_MEDIA, COLOR_NEGRO);
}


void mostrarDesconocida() {

  pantallaActual =
    PANTALLA_DESCONOCIDA;

  int y =
    pantallaBase(COLOR_ROJO_OSC, COLOR_ROJO);

  iconoX(ANCHO_TFT / 2, y + 36, COLOR_ROJO, COLOR_ROJO_OSC);
  tc("Huella no", y + 74, F_GRANDE, COLOR_BLANCO);
  tc("registrada", y + 106, F_GRANDE, COLOR_BLANCO);
  tc("Consulte a su encargado", y + 146, F_CHICA, COLOR_GRIS_CLARO);
}


void mostrarAjustarDedo() {

  pantallaActual =
    PANTALLA_AJUSTAR;

  led(40, 30, 0);

  int y =
    pantallaBase(COLOR_GRIS_MED, COLOR_AMARILLO);

  iconoHuella(ANCHO_TFT / 2, y + 44, COLOR_AMARILLO);
  tc("Coloque bien", y + 82, F_GRANDE, COLOR_AMARILLO);
  tc("el dedo", y + 114, F_GRANDE, COLOR_AMARILLO);
  tc("Intente de nuevo", y + 150, F_CHICA, COLOR_BLANCO);
}


void mostrarCooldown(
  int segundos
) {

  pantallaActual =
    PANTALLA_COOLDOWN;

  int y =
    pantallaBase(COLOR_AMBAR_OSC, COLOR_AMARILLO);

  iconoOk(ANCHO_TFT / 2, y + 34, COLOR_AMARILLO, COLOR_AMBAR_OSC);
  tc("Ya marco", y + 72, F_GRANDE, COLOR_AMARILLO);

  if (
    segundos > 0
  ) {

    tc("Espere " + String(segundos) + " s", y + 114, F_MEDIA, COLOR_BLANCO);

  } else {

    tc("Registro reciente", y + 114, F_MEDIA, COLOR_BLANCO);
  }

  tc("No se duplico", y + 148, F_CHICA, COLOR_GRIS_CLARO);
}


void mostrarError(
  String titulo,
  String detalle
) {

  pantallaActual =
    PANTALLA_ERROR;

  titulo =
    normalizarTextoTFT(titulo);

  titulo.toUpperCase();

  detalle =
    normalizarTextoTFT(detalle);

  led(60, 0, 0);

  int y =
    pantallaBase(COLOR_ROJO_OSC, COLOR_ROJO);

  iconoAviso(ANCHO_TFT / 2, y + 36, COLOR_ROJO, COLOR_ROJO_OSC);
  tcAjustado(titulo, y + 74, FUENTES_DETALLE, 3, COLOR_BLANCO);

  // El detalle puede ser largo (mensaje del servidor): hasta dos lineas.
  if (
    anchoTexto(detalle, F_CHICA) <= ANCHO_UTIL
  ) {

    tc(detalle, y + 116, F_CHICA, COLOR_GRIS_CLARO);

    return;
  }

  int corte =
    detalle.length() / 2;

  int espacio =
    detalle.lastIndexOf(' ', corte + 6);

  if (
    espacio > 0
  ) {
    corte = espacio;
  }

  const GFXfont* const soloChica[] = {
    F_CHICA
  };

  tcAjustado(detalle.substring(0, corte), y + 108, soloChica, 1, COLOR_GRIS_CLARO);
  tcAjustado(detalle.substring(corte + 1), y + 132, soloChica, 1, COLOR_GRIS_CLARO);
}


// =====================================================
// PANTALLAS DE ENROLAMIENTO
//
// Todas llevan guard antiparpadeo sobre (pantallaActual + pantallaDetalle).
// Se pintan dentro de bucles de espera que giran cada 40-60 ms; sin el guard
// la pantalla parpadearia sin parar durante los 20 s de espera del dedo.
// =====================================================

bool pantallaVigente(
  EstadoPantalla estado,
  const String& detalle
) {

  if (
    pantallaActual == estado &&
    pantallaDetalle == detalle
  ) {
    return true;
  }

  pantallaActual =
    estado;

  pantallaDetalle =
    detalle;

  return false;
}


void mostrarEnrolInicio(
  String nombre,
  int intento
) {

  if (
    pantallaVigente(
      PANTALLA_ENROL_INICIO,
      nombre + "|" + String(intento)
    )
  ) {
    return;
  }

  led(0, 30, 60);

  int y =
    pantallaBase(COLOR_AZUL_OSC, COLOR_CYAN);

  tc("Registro de huella", y + 34, F_MEDIA, COLOR_CYAN);
  tcAjustado(tipoTitulo(nombre), y + 80, FUENTES_NOMBRE, 3, COLOR_BLANCO);
  tc("Prepare el dedo", y + 132, F_CHICA, COLOR_GRIS_CLARO);
}


void mostrarEnrolColoque(
  String nombre
) {

  if (
    pantallaVigente(
      PANTALLA_ENROL_COLOQUE,
      nombre
    )
  ) {
    return;
  }

  int y =
    pantallaBase(COLOR_AZUL_OSC, COLOR_CYAN);

  iconoHuella(ANCHO_TFT / 2, y + 42, COLOR_CYAN);
  tc("Coloque el dedo", y + 80, F_MEDIA, COLOR_CYAN);
  tcAjustado(tipoTitulo(nombre), y + 114, FUENTES_DETALLE, 3, COLOR_BLANCO);
  tc("Captura 1 de 2", y + 146, F_CHICA, COLOR_GRIS_CLARO);
}


void mostrarEnrolRetire() {

  if (
    pantallaVigente(
      PANTALLA_ENROL_RETIRE,
      ""
    )
  ) {
    return;
  }

  int y =
    pantallaBase(COLOR_GRIS_MED, COLOR_AMARILLO);

  iconoOk(ANCHO_TFT / 2, y + 36, COLOR_VERDE, COLOR_GRIS_MED);
  tc("Retire el dedo", y + 78, F_MEDIA, COLOR_AMARILLO);
  tc("Primera captura lista", y + 124, F_CHICA, COLOR_BLANCO);
}


void mostrarEnrolRepita(
  String nombre
) {

  if (
    pantallaVigente(
      PANTALLA_ENROL_REPITA,
      nombre
    )
  ) {
    return;
  }

  int y =
    pantallaBase(COLOR_AZUL_OSC, COLOR_CYAN);

  iconoHuella(ANCHO_TFT / 2, y + 42, COLOR_CYAN);
  tc("Otra vez", y + 80, F_MEDIA, COLOR_CYAN);
  tc("el mismo dedo", y + 112, F_MEDIA, COLOR_BLANCO);
  tc("Captura 2 de 2", y + 146, F_CHICA, COLOR_GRIS_CLARO);
}


void mostrarEnrolGuardando(
  int ranura
) {

  if (
    pantallaVigente(
      PANTALLA_ENROL_GUARDANDO,
      String(ranura)
    )
  ) {
    return;
  }

  int y =
    pantallaBase(COLOR_GRIS_OSC, COLOR_CYAN);

  tc("Guardando", y + 46, F_GRANDE, COLOR_CYAN);
  tc("Ranura " + String(ranura), y + 100, F_MEDIA, COLOR_BLANCO);
}


void mostrarEnrolOk(
  String nombre,
  int ranura
) {

  if (
    pantallaVigente(
      PANTALLA_ENROL_OK,
      nombre + "|" + String(ranura)
    )
  ) {
    return;
  }

  int y =
    pantallaBase(COLOR_VERDE_OSC, COLOR_VERDE);

  iconoOk(ANCHO_TFT / 2, y + 32, COLOR_VERDE, COLOR_VERDE_OSC);
  tc("Huella guardada", y + 66, F_MEDIA, COLOR_VERDE);
  tcAjustado(tipoTitulo(nombre), y + 102, FUENTES_NOMBRE, 3, COLOR_BLANCO);
  tc("Ranura " + String(ranura), y + 146, F_CHICA, COLOR_GRIS_CLARO);
}


void mostrarEnrolFallo(
  String titulo,
  String detalle
) {

  if (
    pantallaVigente(
      PANTALLA_ENROL_FALLO,
      titulo + "|" + detalle
    )
  ) {
    return;
  }

  int y =
    pantallaBase(COLOR_ROJO_OSC, COLOR_ROJO);

  iconoX(ANCHO_TFT / 2, y + 32, COLOR_ROJO, COLOR_ROJO_OSC);
  tc("No se grabo", y + 66, F_MEDIA, COLOR_ROJO);
  tcAjustado(normalizarTextoTFT(titulo), y + 100, FUENTES_DETALLE, 3, COLOR_BLANCO);

  const GFXfont* const soloChica[] = {
    F_CHICA
  };

  tcAjustado(normalizarTextoTFT(detalle), y + 138, soloChica, 1, COLOR_GRIS_CLARO);
}


void mostrarEnrolIndice() {

  if (
    pantallaVigente(
      PANTALLA_ENROL_INDICE,
      ""
    )
  ) {
    return;
  }

  int y =
    pantallaBase(COLOR_GRIS_OSC, COLOR_GRIS);

  tc("Sincronizando", y + 56, F_MEDIA, COLOR_CYAN);
  tc("sensor...", y + 92, F_MEDIA, COLOR_BLANCO);
}


// =====================================================
// WIFI
// =====================================================

void conectarWiFi() {

  Serial.println();
  Serial.println(
    "CONECTANDO WIFI..."
  );

  WiFi.mode(
    WIFI_STA
  );

  WiFi.setAutoReconnect(
    true
  );

  WiFi.begin(
    WIFI_SSID,
    WIFI_PASSWORD
  );

  unsigned long inicio =
    millis();

  while (
    WiFi.status() !=
      WL_CONNECTED &&
    millis() - inicio <
      12000
  ) {

    Serial.print(".");
    delay(250);
  }

  Serial.println();

  if (
    WiFi.status() ==
    WL_CONNECTED
  ) {

    Serial.println(
      "WIFI CONECTADO"
    );

    Serial.print(
      "IP ESP32: "
    );

    Serial.println(
      WiFi.localIP()
    );

  } else {

    Serial.println(
      "NO SE PUDO CONECTAR WIFI"
    );
  }

  wifiAnterior =
    WiFi.status() ==
    WL_CONNECTED;
}


// =====================================================
// MANTENER WIFI
// =====================================================

void mantenerWiFi() {

  if (
    millis() -
      ultimoChequeoWifi <
    5000
  ) {
    return;
  }

  ultimoChequeoWifi =
    millis();

  bool wifiAhora =
    WiFi.status() ==
    WL_CONNECTED;

  if (
    wifiAhora !=
    wifiAnterior
  ) {

    wifiAnterior =
      wifiAhora;

    if (
      pantallaActual ==
      PANTALLA_LISTO
    ) {
      dibujarFooter();
    }
  }

  if (
    !wifiAhora
  ) {

    servidorDisponible =
      false;

    dispositivoAutorizado =
      false;

    WiFi.reconnect();
  }
}


// =====================================================
// PING REAL CON CREDENCIALES
// =====================================================

bool probarServidor() {

  if (
    WiFi.status() !=
    WL_CONNECTED
  ) {

    servidorDisponible =
      false;

    dispositivoAutorizado =
      false;

    return false;
  }

  WiFiClient client;

  // MILISEGUNDOS los dos, en el core esp32 3.x. Ver el bloque HTTP de arriba.
  client.setTimeout(
    HTTP_STREAM_TIMEOUT_MS
  );

  client.setConnectionTimeout(
    HTTP_CONNECT_TIMEOUT_MS
  );

  Serial.println();
  Serial.println(
    "============================"
  );
  Serial.println(
    "PING CON CREDENCIALES ESP32"
  );
  Serial.println(
    "============================"
  );

  if (
    !client.connect(
      SERVER_IP,
      SERVER_PORT
    )
  ) {

    Serial.println(
      "NO CONECTA TCP"
    );

    servidorDisponible =
      false;

    dispositivoAutorizado =
      false;

    return false;
  }

  client.println(
    "GET /api/asistencia/ping HTTP/1.1"
  );

  client.print(
    "Host: "
  );

  client.println(
    SERVER_HOST
  );

  client.print(
    "X-Dispositivo: "
  );

  client.println(
    DEVICE_CODE
  );

  client.print(
    "X-Dispositivo-Token: "
  );

  client.println(
    DEVICE_TOKEN
  );

  client.println(
    "Accept: application/json"
  );

  client.println(
    "Connection: close"
  );

  client.println();

  unsigned long inicio =
    millis();

  while (
    !client.available()
  ) {

    if (
      millis() - inicio >
      4000
    ) {

      client.stop();

      Serial.println(
        "TIMEOUT PING"
      );

      servidorDisponible =
        false;

      dispositivoAutorizado =
        false;

      return false;
    }

    delay(5);
  }

  // El techo absoluto se ancla AQUI, no al principio: la espera de arriba puede
  // consumir varios segundos legitimamente, y contarlos dentro del presupuesto
  // de lectura acortaria una respuesta que estaba llegando bien.
  unsigned long inicioRespuesta =
    millis();

  String respuesta =
    "";

  // Reservar de una vez evita ~400 realloc por peticion. Con el sondeo del
  // enrolamiento son decenas de miles de peticiones al dia y el ESP32 no
  // compacta el heap.
  respuesta.reserve(
    512
  );

  unsigned long ultimoDato =
    millis();

  while (
    client.connected() ||
    client.available()
  ) {

    while (
      client.available()
    ) {

      respuesta +=
        (char)client.read();

      ultimoDato =
        millis();
    }

    if (
      millis() -
        ultimoDato >
      1000
    ) {
      break;
    }

    // Techo absoluto: ninguna lectura puede pasar de aca aunque el servidor
    // mande datos a cuentagotas y el silencio nunca llegue a 1000 ms.
    if (
      millis() - inicioRespuesta >
      HTTP_TOTAL_TIMEOUT_MS
    ) {
      break;
    }

    delay(1);
  }

  client.stop();

  Serial.println();
  Serial.println(
    "RESPUESTA COMPLETA PING:"
  );

  Serial.println(
    respuesta
  );

  Serial.println(
    "============================"
  );

  servidorDisponible =
    respuesta.indexOf(
      "HTTP/1.1 200"
    ) >= 0 ||
    respuesta.indexOf(
      "HTTP/1.0 200"
    ) >= 0;

  dispositivoAutorizado =
    respuesta.indexOf(
      "\"reconocido\":true"
    ) >= 0;

  // Solo para el reloj de la pantalla: ver ponerRelojDesdePing().
  if (
    servidorDisponible
  ) {

    ponerRelojDesdePing(
      respuesta
    );
  }

  Serial.print(
    "SERVIDOR DISPONIBLE: "
  );

  Serial.println(
    servidorDisponible
      ? "SI"
      : "NO"
  );

  Serial.print(
    "DISPOSITIVO RECONOCIDO POR LARAVEL: "
  );

  Serial.println(
    dispositivoAutorizado
      ? "SI"
      : "NO"
  );

  return (
    servidorDisponible &&
    dispositivoAutorizado
  );
}


// =====================================================
// IDENTIFICAR HUELLA
// =====================================================

ResultadoHuella identificarHuella(
  uint16_t &id,
  uint16_t &confianza
) {

  const unsigned long TIEMPO_MAXIMO =
    1600;

  const int MAX_NO_COINCIDE =
    2;

  unsigned long inicio =
    millis();

  bool primeraImagen =
    true;

  bool huboLecturaValida =
    false;

  bool mostramosLeyendo =
    false;

  int noCoincide =
    0;

  int sinDedoConsecutivo =
    0;

  while (
    millis() - inicio <
    TIEMPO_MAXIMO
  ) {

    uint8_t p;

    if (
      primeraImagen
    ) {

      p =
        FINGERPRINT_OK;

      primeraImagen =
        false;

    } else {

      p =
        finger.getImage();
    }

    if (
      p ==
      FINGERPRINT_NOFINGER
    ) {

      sinDedoConsecutivo++;

      if (
        sinDedoConsecutivo >= 4
      ) {

        if (
          noCoincide >=
          MAX_NO_COINCIDE
        ) {
          return RH_DESCONOCIDA;
        }

        if (
          huboLecturaValida
        ) {
          return RH_INDETERMINADA;
        }

        return RH_RETIRADA;
      }

      delay(35);
      continue;
    }

    sinDedoConsecutivo =
      0;

    if (
      p ==
      FINGERPRINT_PACKETRECIEVEERR
    ) {

      delay(35);
      continue;
    }

    if (
      p !=
      FINGERPRINT_OK
    ) {

      delay(35);
      continue;
    }

    uint8_t convertido =
      finger.image2Tz();

    Serial.print(
      "image2Tz = "
    );

    Serial.println(
      convertido
    );

    if (
      convertido !=
      FINGERPRINT_OK
    ) {

      delay(45);
      continue;
    }

    huboLecturaValida =
      true;

    if (
      !mostramosLeyendo
    ) {

      mostrarLeyendo();

      mostramosLeyendo =
        true;
    }

    uint8_t busqueda =
      finger.fingerFastSearch();

    Serial.print(
      "fingerFastSearch = "
    );

    Serial.println(
      busqueda
    );

    if (
      busqueda ==
      FINGERPRINT_OK
    ) {

      id =
        finger.fingerID;

      confianza =
        finger.confidence;

      Serial.print(
        "ID = "
      );

      Serial.println(
        id
      );

      Serial.print(
        "Confianza = "
      );

      Serial.println(
        confianza
      );

      return RH_RECONOCIDA;
    }

    if (
      busqueda ==
      FINGERPRINT_NOTFOUND
    ) {

      noCoincide++;

      Serial.print(
        "Huella valida sin coincidencia #"
      );

      Serial.println(
        noCoincide
      );

      if (
        noCoincide >=
        MAX_NO_COINCIDE
      ) {

        Serial.println(
          "HUELLA DESCONOCIDA CONFIRMADA"
        );

        return RH_DESCONOCIDA;
      }

      delay(70);
      continue;
    }

    delay(40);
  }

  if (
    noCoincide >=
    MAX_NO_COINCIDE
  ) {
    return RH_DESCONOCIDA;
  }

  if (
    huboLecturaValida
  ) {
    return RH_INDETERMINADA;
  }

  return RH_SIN_LECTURA;
}


// =====================================================
// ESPERAR RETIRO
// =====================================================

void esperarRetiroConMensaje(
  unsigned long minimoMs,
  unsigned long maximoMs
) {

  unsigned long inicio =
    millis();

  int sinDedo =
    0;

  bool retirado =
    false;

  while (
    millis() - inicio <
    maximoMs
  ) {

    uint8_t p =
      finger.getImage();

    if (
      p ==
      FINGERPRINT_NOFINGER
    ) {

      sinDedo++;

      if (
        sinDedo >= 2
      ) {

        retirado =
          true;
      }

    } else {

      sinDedo =
        0;
    }

    if (
      retirado &&
      millis() - inicio >=
      minimoMs
    ) {

      break;
    }

    atenderBuzzer();

    delay(45);
  }
}


// =====================================================
// JSON
// =====================================================

String obtenerValorJson(
  const String& json,
  const String& clave
) {

  String patron =
    "\"" +
    clave +
    "\":";

  int posicion =
    json.indexOf(
      patron
    );

  if (
    posicion < 0
  ) {
    return "";
  }

  posicion +=
    patron.length();

  while (
    posicion <
      json.length() &&
    (
      json[posicion] == ' ' ||
      json[posicion] == '\r' ||
      json[posicion] == '\n' ||
      json[posicion] == '\t'
    )
  ) {

    posicion++;
  }

  if (
    posicion >=
    json.length()
  ) {
    return "";
  }

  if (
    json[posicion] ==
    '"'
  ) {

    posicion++;

    int fin =
      posicion;

    while (
      fin <
      json.length()
    ) {

      if (
        json[fin] == '"' &&
        (
          fin == posicion ||
          json[fin - 1] != '\\'
        )
      ) {
        break;
      }

      fin++;
    }

    return json.substring(
      posicion,
      fin
    );
  }

  int fin =
    posicion;

  while (
    fin <
    json.length() &&
    json[fin] != ',' &&
    json[fin] != '}' &&
    json[fin] != '\r' &&
    json[fin] != '\n'
  ) {

    fin++;
  }

  String valor =
    json.substring(
      posicion,
      fin
    );

  valor.trim();

  return valor;
}


int obtenerIntJson(
  const String& json,
  const String& clave
) {

  String valor =
    obtenerValorJson(
      json,
      clave
    );

  if (
    valor.length() == 0
  ) {
    return -1;
  }

  return valor.toInt();
}


bool obtenerBoolJson(
  const String& json,
  const String& clave
) {

  String valor =
    obtenerValorJson(
      json,
      clave
    );

  valor.toLowerCase();

  return (
    valor == "true" ||
    valor == "1"
  );
}


// =====================================================
// EXTRAER OBJETO JSON
//
// obtenerValorJson() es plano: encuentra la PRIMERA aparicion de la clave en
// todo el cuerpo, sin mirar el anidamiento. Para /marcar da igual porque las
// claves no colisionan, pero la respuesta del sondeo trae "id" dos veces —el
// de la orden y el del empleado— y cual gana dependeria del orden en que
// Laravel construya el array. Con esto se acota la busqueda al objeto correcto
// y el firmware deja de depender de ese orden.
//
// Devuelve la subcadena {...} equilibrada, o "" si no esta.
// =====================================================

String extraerObjetoJson(
  const String& json,
  const String& clave
) {

  String patron =
    "\"" +
    clave +
    "\":";

  int posicion =
    json.indexOf(
      patron
    );

  if (
    posicion < 0
  ) {
    return "";
  }

  posicion +=
    patron.length();

  while (
    posicion <
      json.length() &&
    json[posicion] != '{'
  ) {

    // Solo espacios entre los dos puntos y la llave. Cualquier otra cosa
    // significa que esa clave no es un objeto.
    if (
      json[posicion] != ' ' &&
      json[posicion] != '\r' &&
      json[posicion] != '\n' &&
      json[posicion] != '\t'
    ) {
      return "";
    }

    posicion++;
  }

  if (
    posicion >=
    json.length()
  ) {
    return "";
  }

  int profundidad =
    0;

  bool dentroDeCadena =
    false;

  for (
    int i = posicion;
    i < json.length();
    i++
  ) {

    char c =
      json[i];

    if (
      dentroDeCadena
    ) {

      if (
        c == '\\'
      ) {
        i++;
        continue;
      }

      if (
        c == '"'
      ) {
        dentroDeCadena = false;
      }

      continue;
    }

    if (
      c == '"'
    ) {
      dentroDeCadena = true;
      continue;
    }

    if (
      c == '{'
    ) {
      profundidad++;
      continue;
    }

    if (
      c == '}'
    ) {

      profundidad--;

      if (
        profundidad == 0
      ) {

        return json.substring(
          posicion,
          i + 1
        );
      }
    }
  }

  return "";
}


// =====================================================
// ENVIAR MARCACION
// =====================================================

RespuestaAPI enviarMarcacion(
  uint16_t fingerprintID
) {

  RespuestaAPI r;

  r.httpCode =
    0;

  r.ok =
    false;

  r.estado =
    "";

  r.mensaje =
    "";

  r.nombre =
    "";

  r.tipo =
    "";

  r.hora =
    "";

  r.esperaSegundos =
    -1;

  if (
    WiFi.status() !=
    WL_CONNECTED
  ) {

    servidorDisponible =
      false;

    dispositivoAutorizado =
      false;

    r.mensaje =
      "Sin WiFi";

    return r;
  }

  String body =
    "{\"fingerprint_id\":" +
    String(fingerprintID) +
    "}";

  WiFiClient client;

  // MILISEGUNDOS los dos, en el core esp32 3.x. Ver el bloque HTTP de arriba.
  client.setTimeout(
    HTTP_STREAM_TIMEOUT_MS
  );

  client.setConnectionTimeout(
    HTTP_CONNECT_TIMEOUT_MS
  );

  Serial.println();

  Serial.println(
    "============================"
  );

  Serial.println(
    "ENVIANDO MARCACION"
  );

  Serial.print(
    "Fingerprint ID: "
  );

  Serial.println(
    fingerprintID
  );

  Serial.print(
    "BODY: "
  );

  Serial.println(
    body
  );

  if (
    !client.connect(
      SERVER_IP,
      SERVER_PORT
    )
  ) {

    servidorDisponible =
      false;

    r.mensaje =
      "Servidor no disponible";

    return r;
  }

  client.println(
    "POST /api/asistencia/marcar HTTP/1.1"
  );

  client.print(
    "Host: "
  );

  client.println(
    SERVER_HOST
  );

  client.print(
    "X-Dispositivo: "
  );

  client.println(
    DEVICE_CODE
  );

  client.print(
    "X-Dispositivo-Token: "
  );

  client.println(
    DEVICE_TOKEN
  );

  client.println(
    "Content-Type: application/json"
  );

  client.println(
    "Accept: application/json"
  );

  client.print(
    "Content-Length: "
  );

  client.println(
    body.length()
  );

  client.println(
    "Connection: close"
  );

  client.println();

  client.print(
    body
  );

  unsigned long inicio =
    millis();

  while (
    !client.available()
  ) {

    if (
      millis() - inicio >
      5000
    ) {

      client.stop();

      servidorDisponible =
        false;

      r.mensaje =
        "Timeout servidor";

      return r;
    }

    delay(5);
  }

  // El techo absoluto se ancla AQUI, no al principio: la espera de arriba puede
  // consumir varios segundos legitimamente, y contarlos dentro del presupuesto
  // de lectura acortaria una respuesta que estaba llegando bien.
  unsigned long inicioRespuesta =
    millis();

  String statusLine =
    client.readStringUntil(
      '\n'
    );

  statusLine.trim();

  Serial.print(
    "STATUS: "
  );

  Serial.println(
    statusLine
  );

  if (
    statusLine.startsWith(
      "HTTP/1.1 "
    ) ||
    statusLine.startsWith(
      "HTTP/1.0 "
    )
  ) {

    r.httpCode =
      statusLine.substring(
        9,
        12
      ).toInt();
  }

  Serial.print(
    "HTTP = "
  );

  Serial.println(
    r.httpCode
  );

  if (
    r.httpCode > 0
  ) {

    servidorDisponible =
      true;
  }

  while (
    client.connected() ||
    client.available()
  ) {

    // Guarda de tiempo: readStringUntil() puede devolver una linea vacia por
    // agotar su propio timeout sin que la cabecera haya terminado, y sin este
    // techo el salto de cabeceras podria girar mas de lo que dura la peticion.
    if (
      millis() - inicioRespuesta >
      HTTP_TOTAL_TIMEOUT_MS
    ) {
      break;
    }

    String linea =
      client.readStringUntil(
        '\n'
      );

    if (
      linea == "\r" ||
      linea.length() == 0
    ) {
      break;
    }
  }

  String respuesta =
    "";

  respuesta.reserve(
    768
  );

  unsigned long ultimoDato =
    millis();

  while (
    client.connected() ||
    client.available()
  ) {

    while (
      client.available()
    ) {

      respuesta +=
        (char)client.read();

      ultimoDato =
        millis();
    }

    if (
      millis() -
        ultimoDato >
      1200
    ) {
      break;
    }

    if (
      millis() - inicioRespuesta >
      HTTP_TOTAL_TIMEOUT_MS
    ) {
      break;
    }

    delay(1);
  }

  client.stop();

  Serial.println(
    "RESPUESTA:"
  );

  Serial.println(
    respuesta
  );

  r.ok =
    obtenerBoolJson(
      respuesta,
      "ok"
    );

  r.estado =
    obtenerValorJson(
      respuesta,
      "estado"
    );

  r.mensaje =
    obtenerValorJson(
      respuesta,
      "mensaje"
    );

  r.nombre =
    obtenerValorJson(
      respuesta,
      "nombre_corto"
    );

  if (
    r.nombre.length() == 0
  ) {

    r.nombre =
      obtenerValorJson(
        respuesta,
        "nombre"
      );
  }

  r.tipo =
    obtenerValorJson(
      respuesta,
      "tipo_label"
    );

  if (
    r.tipo.length() == 0
  ) {

    r.tipo =
      obtenerValorJson(
        respuesta,
        "tipo"
      );
  }

  r.hora =
    obtenerValorJson(
      respuesta,
      "hora"
    );

  r.esperaSegundos =
    obtenerIntJson(
      respuesta,
      "espera_segundos"
    );

  if (
    r.httpCode ==
    401
  ) {

    dispositivoAutorizado =
      false;
  }

  return r;
}


// =====================================================
// PETICION HTTP DEL ENROLAMIENTO
//
// Deliberadamente SEPARADA de enviarMarcacion(): el camino de la marcacion ya
// funciona en produccion y no se reimplementa para reaprovechar codigo. El
// precio es esta duplicacion; la alternativa era refactorizar el unico camino
// que hoy no falla.
//
// Devuelve el codigo HTTP, o 0 si no se llego a hablar con el servidor.
// =====================================================

int peticionEnrolamiento(
  const char* metodo,
  const String& ruta,
  const String& cuerpo,
  String& respuesta
) {

  respuesta =
    "";

  respuesta.reserve(
    768
  );

  if (
    WiFi.status() !=
    WL_CONNECTED
  ) {

    servidorDisponible =
      false;

    dispositivoAutorizado =
      false;

    return 0;
  }

  WiFiClient client;

  client.setTimeout(
    HTTP_STREAM_TIMEOUT_MS
  );

  client.setConnectionTimeout(
    HTTP_CONNECT_TIMEOUT_MS
  );

  if (
    !client.connect(
      SERVER_IP,
      SERVER_PORT
    )
  ) {

    servidorDisponible =
      false;

    return 0;
  }

  client.print(
    metodo
  );

  client.print(
    " "
  );

  client.print(
    ruta
  );

  client.println(
    " HTTP/1.1"
  );

  client.print(
    "Host: "
  );

  client.println(
    SERVER_HOST
  );

  client.print(
    "X-Dispositivo: "
  );

  client.println(
    DEVICE_CODE
  );

  client.print(
    "X-Dispositivo-Token: "
  );

  client.println(
    DEVICE_TOKEN
  );

  client.println(
    "Accept: application/json"
  );

  if (
    cuerpo.length() > 0
  ) {

    client.println(
      "Content-Type: application/json"
    );

    client.print(
      "Content-Length: "
    );

    client.println(
      cuerpo.length()
    );
  }

  client.println(
    "Connection: close"
  );

  client.println();

  if (
    cuerpo.length() > 0
  ) {

    client.print(
      cuerpo
    );
  }

  unsigned long inicio =
    millis();

  while (
    !client.available()
  ) {

    if (
      millis() - inicio >
      5000
    ) {

      client.stop();

      servidorDisponible =
        false;

      return 0;
    }

    delay(5);
  }

  // El techo absoluto se ancla AQUI, no al principio: la espera de arriba puede
  // consumir varios segundos legitimamente, y contarlos dentro del presupuesto
  // de lectura acortaria una respuesta que estaba llegando bien.
  unsigned long inicioRespuesta =
    millis();

  String statusLine =
    client.readStringUntil(
      '\n'
    );

  statusLine.trim();

  int httpCode =
    0;

  if (
    statusLine.startsWith(
      "HTTP/1.1 "
    ) ||
    statusLine.startsWith(
      "HTTP/1.0 "
    )
  ) {

    httpCode =
      statusLine.substring(
        9,
        12
      ).toInt();
  }

  if (
    httpCode > 0
  ) {

    servidorDisponible =
      true;
  }

  while (
    client.connected() ||
    client.available()
  ) {

    if (
      millis() - inicioRespuesta >
      HTTP_TOTAL_TIMEOUT_MS
    ) {
      break;
    }

    String linea =
      client.readStringUntil(
        '\n'
      );

    if (
      linea == "\r" ||
      linea.length() == 0
    ) {
      break;
    }
  }

  unsigned long ultimoDato =
    millis();

  while (
    client.connected() ||
    client.available()
  ) {

    while (
      client.available()
    ) {

      respuesta +=
        (char)client.read();

      ultimoDato =
        millis();
    }

    if (
      millis() -
        ultimoDato >
      1200
    ) {
      break;
    }

    if (
      millis() - inicioRespuesta >
      HTTP_TOTAL_TIMEOUT_MS
    ) {
      break;
    }

    delay(1);
  }

  client.stop();

  if (
    httpCode ==
    401
  ) {

    dispositivoAutorizado =
      false;

  } else if (
    httpCode > 0
  ) {

    dispositivoAutorizado =
      true;
  }

  return httpCode;
}


// =====================================================
// ESTADO DE UNA RANURA EN EL SENSOR
//
// loadModel() carga la plantilla de esa ranura en el CHARBUFFER 1 —el mismo
// donde createModel() deja la plantilla recien compuesta—. Por eso el orden
// importa:
//
//   OK      la ranura tiene plantilla. El buffer queda pisado, pero da igual:
//           en ese caso se aborta y NUNCA se llama a storeModel().
//   error   el sensor no transfiere nada y el buffer queda intacto, que es lo
//           que permite grabar a continuacion.
//
// ─────────────── Los cuatro desenlaces, sin colapsar ninguno ───────────────
//
//   FINGERPRINT_OK           0x00  la ranura EXISTE y tiene plantilla
//   FINGERPRINT_DBRANGEFAIL  0x0C  la ranura EXISTE y esta vacia
//   FINGERPRINT_BADLOCATION  0x0B  la ranura NO EXISTE (fuera del rango)
//   cualquier otro                 el sensor no contesto: NO se sabe
//
// 0x0B y 0x0C dicen cosas OPUESTAS y antes se devolvian como el mismo valor.
// Colapsarlas hacia "libre" significaba que el barrido reportaba como
// disponibles ranuras que el sensor no tiene, el servidor reservaba una de
// ellas, y el fallo aparecia recien en storeModel() como un fallo_guardado
// generico —delante del empleado, con el dedo puesto—.
//
// Tambien es lo que impedia usar el propio barrido para averiguar donde termina
// de verdad el rango del sensor: pasaba por la frontera y tiraba el dato.
//
// El codigo se devuelve TAL CUAL. Quien llama decide que hacer con cada uno; lo
// que no puede es confundirlos.
// =====================================================

uint8_t estadoRanuraEnSensor(
  uint16_t ranura
) {

  return finger.loadModel(
    ranura
  );
}


// =====================================================
// LISTA DE RANURAS OCUPADAS
//
// Barrido con la API publica de la libreria (opcion A): loadModel() ranura por
// ranura. La libreria 2.1.4 no expone la tabla de indices del AS608 (comando
// 0x1F), y bajar a los paquetes crudos por ahorrar dos segundos no compensa.
//
// Coste: ~capacidad idas y vueltas por UART a 57600 bps. Con 162 ranuras son
// 1-3 s. Por eso solo corre en reposo, NUNCA con un dedo en curso.
//
// Ante un error de COMUNICACION la ranura se marca OCUPADA, no libre: errar
// hacia "ocupada" hace que el servidor elija otra —molesto pero inofensivo—;
// errar hacia "libre" haria que reservara una ranura con plantilla y el
// enrolamiento chocara al grabar.
// =====================================================

String construirListaOcupadas(
  uint16_t &capacidadEfectiva
) {

  String lista =
    "";

  lista.reserve(
    1024
  );

  uint16_t tope =
    capacidadSensor;

  if (
    tope > ENROL_MAX_RANURAS_BARRIDO
  ) {

    tope =
      ENROL_MAX_RANURAS_BARRIDO;
  }

  // Se arranca creyendole a getParameters(). Si el sensor contesta BADLOCATION
  // antes de llegar al tope declarado, la capacidad de verdad es MENOR y este
  // valor baja: es el unico sitio del sistema que puede descubrirlo.
  capacidadEfectiva =
    tope;

  int encontradas =
    0;

  for (
    uint16_t i = 0;
    i < tope;
    i++
  ) {

    uint8_t estado =
      estadoRanuraEnSensor(
        i
      );

    // ─────────── FUERA DE RANGO: aca se acaba el sensor ───────────
    //
    // No es una ranura libre: no existe. Se corta el barrido —lo que venga
    // despues tampoco existe— y se corrige la capacidad que se le va a
    // reportar al servidor, para que no reserve una ranura inexistente.
    if (
      estado ==
      FINGERPRINT_BADLOCATION
    ) {

      capacidadEfectiva =
        i;

      Serial.print(
        "BADLOCATION en la ranura "
      );

      Serial.print(
        i
      );

      Serial.print(
        ": el sensor declaraba "
      );

      Serial.print(
        tope
      );

      Serial.println(
        " ranuras y no las tiene"
      );

      break;
    }

    if (
      estado ==
      FINGERPRINT_DBRANGEFAIL
    ) {

      // Existe y esta vacia.
      delay(2);
      continue;
    }

    if (
      estado !=
      FINGERPRINT_OK
    ) {

      // Error de comunicacion: un reintento antes de darla por ocupada.
      // Errar hacia "ocupada" hace que el servidor elija otra —molesto pero
      // inofensivo—; errar hacia "libre" lo haria reservar una ranura con
      // plantilla.
      delay(15);

      estado =
        estadoRanuraEnSensor(
          i
        );

      if (
        estado ==
        FINGERPRINT_BADLOCATION
      ) {

        capacidadEfectiva =
          i;

        break;
      }

      if (
        estado ==
        FINGERPRINT_DBRANGEFAIL
      ) {

        delay(2);
        continue;
      }
    }

    if (
      encontradas > 0
    ) {
      lista += ",";
    }

    lista +=
      String(i);

    encontradas++;

    delay(2);
  }

  Serial.print(
    "RANURAS OCUPADAS EN EL SENSOR: "
  );

  Serial.println(
    encontradas
  );

  Serial.print(
    "RANGO REAL DEL SENSOR: 0.."
  );

  Serial.println(
    capacidadEfectiva > 0
      ? capacidadEfectiva - 1
      : 0
  );

  return lista;
}



// =====================================================
// SINCRONIZAR INDICE DEL SENSOR
//
// Lo que el AS608 dice de si mismo. Sin esto el servidor elige ranura a ciegas
// y choca con las plantillas heredadas.
//
// Se llama al arrancar y cada vez que el sondeo contesta sincronizar_indice.
// =====================================================

bool sincronizarIndiceSensor() {

  if (
    enrolando
  ) {
    return false;
  }

  if (
    WiFi.status() !=
    WL_CONNECTED
  ) {
    return false;
  }

  // Cinturon: el barrido tarda segundos y jamas puede correr con un dedo
  // encima. El unico sitio que llama aca ya comprobo NOFINGER, pero esta
  // funcion tambien se invoca desde setup() y desde el fallo por ranura
  // ocupada, asi que la comprobacion vive aca dentro.
  //
  // Una imagen vacia (dedo fantasma, ver esImagenFantasma) no es un dedo: con
  // un sensor que la devuelve casi siempre, exigir NOFINGER pospondria el
  // indice para siempre y el servidor nunca podria reservar ranura.
  uint8_t imagen =
    finger.getImage();

  bool hayDedo =
    imagen !=
    FINGERPRINT_NOFINGER;

  if (
    imagen ==
    FINGERPRINT_OK
  ) {

    hayDedo =
      !esImagenFantasma(
        finger.image2Tz(1)
      );
  }

  if (
    hayDedo
  ) {

    Serial.println(
      "INDICE POSPUESTO: hay un dedo en el sensor"
    );

    return false;
  }

  uint8_t parametros =
    finger.getParameters();

  if (
    parametros !=
    FINGERPRINT_OK
  ) {

    Serial.print(
      "getParameters fallo = "
    );

    Serial.println(
      parametros
    );

    return false;
  }

  capacidadSensor =
    finger.capacity;

  if (
    capacidadSensor == 0
  ) {

    Serial.println(
      "CAPACIDAD 0: no se manda indice"
    );

    return false;
  }

  Serial.print(
    "CAPACIDAD DEL SENSOR: "
  );

  Serial.println(
    capacidadSensor
  );

  mostrarEnrolIndice();

  uint16_t capacidadEfectiva =
    capacidadSensor;

  String ocupadas =
    construirListaOcupadas(
      capacidadEfectiva
    );

  // Se reporta la capacidad que el barrido pudo RECORRER, no la que
  // getParameters() declaro. Si el sensor mintio —o es un clon con menos
  // paginas de las que dice— el servidor se entera aca y deja de reservar
  // ranuras inexistentes. En un sensor honesto los dos valores coinciden.
  if (
    capacidadEfectiva !=
    capacidadSensor
  ) {

    Serial.print(
      "CAPACIDAD CORREGIDA: declarada "
    );

    Serial.print(
      capacidadSensor
    );

    Serial.print(
      " -> real "
    );

    Serial.println(
      capacidadEfectiva
    );

    capacidadSensor =
      capacidadEfectiva;
  }

  if (
    capacidadEfectiva == 0
  ) {

    Serial.println(
      "EL SENSOR NO TIENE NINGUNA RANURA DIRECCIONABLE"
    );

    mostrarListo();

    return false;
  }

  String cuerpo =
    "{\"capacidad\":" +
    String(capacidadEfectiva) +
    ",\"ocupadas\":[" +
    ocupadas +
    "]}";

  String respuesta;

  int http =
    peticionEnrolamiento(
      "POST",
      "/api/asistencia/enrolamiento/indice-sensor",
      cuerpo,
      respuesta
    );

  Serial.print(
    "INDICE SENSOR HTTP = "
  );

  Serial.println(
    http
  );

  mostrarListo();

  return http == 200;
}


// =====================================================
// PROGRESO
//
// SECUNDARIO a proposito: mueve la orden a en_curso, refresca su vencimiento y
// hace que quien mira la pantalla web vea lo mismo que quien esta frente al
// lector. Un fallo de red aca NO aborta el enrolamiento, y por eso se ignora el
// desenlace.
// =====================================================

void reportarProgreso(
  const OrdenEnrolamiento& orden,
  const char* etapa
) {

  String cuerpo =
    "{\"token\":\"" +
    orden.token +
    "\",\"etapa\":\"" +
    String(etapa) +
    "\"}";

  String respuesta;

  peticionEnrolamiento(
    "POST",
    "/api/asistencia/enrolamiento/" +
      String(orden.id) +
      "/progreso",
    cuerpo,
    respuesta
  );
}


// =====================================================
// RESULTADO
//
// El acto. Es IDEMPOTENTE en el servidor: reintentar devuelve el mismo
// desenlace, nunca una segunda asignacion. Por eso se puede reintentar sin
// miedo cuando la red se corta despues de haber grabado la plantilla.
//
// 200 / 409 / 422 son DESENLACES: el servidor recibio y decidio. Solo se
// reintenta cuando no hubo respuesta (0) o cuando fue un 5xx.
//
// Si se agotan los reintentos, el cuerpo queda en RAM y el loop lo reenvia en
// reposo.
// =====================================================

bool reportarResultado(
  const OrdenEnrolamiento& orden,
  const String& cuerpo
) {

  String ruta =
    "/api/asistencia/enrolamiento/" +
    String(orden.id) +
    "/resultado";

  unsigned long espera =
    1000;

  for (
    uint8_t intento = 0;
    intento < ENROL_MAX_REINTENTOS_RESULTADO;
    intento++
  ) {

    String respuesta;

    int http =
      peticionEnrolamiento(
        "POST",
        ruta,
        cuerpo,
        respuesta
      );

    Serial.print(
      "RESULTADO HTTP = "
    );

    Serial.println(
      http
    );

    if (
      http == 200 ||
      http == 409 ||
      http == 422
    ) {

      Serial.println(
        respuesta
      );

      return true;
    }

    // 404: el token de la orden ya no vale —el servidor lo reemite en cada
    // sondeo que la encuentre viva—. No se puede recuperar desde aca sin
    // volver a sondear, y volver a sondear desde dentro del enrolamiento
    // reentraria en esta misma funcion. Se deja pendiente para el loop.
    if (
      http == 404 ||
      http == 401
    ) {

      Serial.println(
        "RESULTADO RECHAZADO: orden o credencial no validas"
      );

      return false;
    }

    delay(espera);

    espera *= 2;
  }

  // No hubo forma de entregarlo. Queda pendiente: es idempotente.
  resultadoPendiente =
    true;

  resultadoPendienteOrden =
    orden.id;

  resultadoPendienteCuerpo =
    cuerpo;

  proximoReintentoPendiente =
    millis() + 5000;

  Serial.println(
    "RESULTADO GUARDADO PARA REINTENTO EN REPOSO"
  );

  return false;
}


// =====================================================
// FALLAR UN ENROLAMIENTO
//
// Un solo camino de salida para todos los fallos: pinta la pantalla, reporta
// el motivo —siempre uno de los que el LECTOR puede alegar, nunca uno que solo
// decide el servidor— y adjunta el indice del sensor cuando el motivo es el
// conflicto de ranura, que es lo que permite que el reintento del servidor ya
// excluya la plantilla heredada recien descubierta.
// =====================================================

void fallarEnrolamiento(
  const OrdenEnrolamiento& orden,
  const char* motivo,
  const String& detalle,
  const String& titulo,
  const String& subtitulo,
  bool adjuntarIndice
) {

  Serial.print(
    "ENROLAMIENTO FALLIDO: "
  );

  Serial.println(
    motivo
  );

  String cuerpo =
    "{\"token\":\"" +
    orden.token +
    "\",\"exito\":false,\"motivo\":\"" +
    String(motivo) +
    "\"";

  if (
    detalle.length() > 0
  ) {

    cuerpo +=
      ",\"detalle\":\"" +
      detalle +
      "\"";
  }

  if (
    adjuntarIndice &&
    capacidadSensor > 0
  ) {

    // El barrido es seguro aca: el dedo ya se retiro y no hay captura en
    // curso. Es lo que convierte este fallo en un reintento util.
    uint16_t capacidadEfectiva =
      capacidadSensor;

    String ocupadas =
      construirListaOcupadas(
        capacidadEfectiva
      );

    if (
      capacidadEfectiva !=
      capacidadSensor
    ) {

      capacidadSensor =
        capacidadEfectiva;
    }

    cuerpo +=
      ",\"indice\":{\"capacidad\":" +
      String(capacidadEfectiva) +
      ",\"ocupadas\":[" +
      ocupadas +
      "]}";
  }

  cuerpo +=
    "}";

  mostrarEnrolFallo(
    titulo,
    subtitulo
  );

  reportarResultado(
    orden,
    cuerpo
  );

  beepError();

  pausaConBuzzer(2500);
}


// =====================================================
// DEDO FANTASMA
//
// Hay AS608 que devuelven FINGERPRINT_OK en getImage() sin nadie apoyado. En
// el lector ES3C28P se midio el 06/10/2026: 67 de 67 lecturas en 20 s, con el
// Wi-Fi apagado, y luz verde parpadeando sola. Esa imagen vacia no tiene
// minucias e image2Tz() la rechaza con FINGERPRINT_FEATUREFAIL.
//
// La marcacion ya lo tolera (identificarHuella reintenta y lo descarta como
// ruido). Lo que no lo toleraba era todo lo que confiaba en getImage() a secas
// para saber si habia un dedo: el indice del sensor se posponia para siempre y
// el enrolamiento tomaba la imagen vacia como primera captura.
//
// Un dedo real mal apoyado tambien puede dar FEATUREFAIL. Tratarlo como "sin
// dedo" solo hace que se le siga esperando, que es lo correcto: la persona lo
// reacomoda dentro del mismo plazo.
// =====================================================

bool esImagenFantasma(
  uint8_t conversion
) {

  return
    conversion ==
    FINGERPRINT_FEATUREFAIL;
}


// =====================================================
// ESPERAR EL DEDO (ENROLAMIENTO)
//
// Devuelve FINGERPRINT_OK cuando hay un dedo y su imagen ya quedo convertida
// en `buffer` (image2Tz), FINGERPRINT_TIMEOUT si se agoto la espera, o el
// codigo de error del sensor si dejo de responder.
//
// Convierte aca dentro porque solo la conversion distingue un dedo de la
// imagen vacia del fantasma. Si hubo un dedo real cuya imagen no sirvio
// (IMAGEMESS, INVALIDIMAGE...), se sigue esperando a que lo reacomode y el
// ultimo codigo queda en `errorCaptura`: con el plazo agotado, quien llama
// reporta captura_defectuosa en vez de timeout_dedo.
//
// Llama a mantenerWiFi() porque el loop esta bloqueado mientras se enrola y
// una espera de 20 s no puede dejar la reconexion sin atender. No repinta
// nada: mantenerWiFi solo toca el footer cuando la pantalla es LISTO.
// =====================================================

uint8_t esperarDedoEnrolamiento(
  unsigned long maximoMs,
  uint8_t buffer,
  uint8_t& errorCaptura
) {

  unsigned long inicio =
    millis();

  int erroresSeguidos =
    0;

  errorCaptura =
    FINGERPRINT_OK;

  while (
    millis() - inicio <
    maximoMs
  ) {

    mantenerWiFi();

    uint8_t p =
      finger.getImage();

    if (
      p ==
      FINGERPRINT_OK
    ) {

      erroresSeguidos =
        0;

      uint8_t conv =
        finger.image2Tz(
          buffer
        );

      if (
        conv ==
        FINGERPRINT_OK
      ) {

        Serial.printf(
          "image2Tz(%u) = 0\n",
          buffer
        );

        return FINGERPRINT_OK;
      }

      if (
        !esImagenFantasma(
          conv
        )
      ) {

        Serial.printf(
          "image2Tz(%u) = %u, se espera que reacomode el dedo\n",
          buffer,
          conv
        );

        errorCaptura =
          conv;
      }

      delay(60);
      continue;
    }

    if (
      p ==
      FINGERPRINT_NOFINGER
    ) {

      erroresSeguidos =
        0;

      delay(60);
      continue;
    }

    // PACKETRECIEVEERR o TIMEOUT repetidos: el sensor dejo de hablar.
    erroresSeguidos++;

    if (
      erroresSeguidos >= 20
    ) {
      return p;
    }

    delay(60);
  }

  return FINGERPRINT_TIMEOUT;
}


// =====================================================
// ESPERAR EL RETIRO (ENROLAMIENTO)
//
// esperarRetiroConMensaje() no dice si el dedo se retiro de verdad —solo
// espera— y el camino de la marcacion depende de ella tal como esta, asi que
// no se toca. Esta variante devuelve el dato que el enrolamiento necesita para
// poder fallar con timeout_dedo en vez de seguir a ciegas.
// =====================================================

bool esperarRetiroDeEnrolamiento(
  unsigned long maximoMs
) {

  unsigned long inicio =
    millis();

  int sinDedo =
    0;

  while (
    millis() - inicio <
    maximoMs
  ) {

    mantenerWiFi();

    uint8_t p =
      finger.getImage();

    bool retirado =
      p ==
      FINGERPRINT_NOFINGER;

    // La imagen vacia del fantasma tambien cuenta como dedo retirado; sin
    // esto un sensor que la devuelve casi siempre nunca juntaria tres
    // NOFINGER seguidos. Se convierte en el buffer 2 a proposito: la primera
    // captura vive en el 1 y el 2 lo pisa de todos modos la segunda.
    if (
      p ==
      FINGERPRINT_OK
    ) {

      retirado =
        esImagenFantasma(
          finger.image2Tz(2)
        );
    }

    if (
      retirado
    ) {

      sinDedo++;

      if (
        sinDedo >= 3
      ) {
        return true;
      }

    } else {

      sinDedo =
        0;
    }

    delay(60);
  }

  return false;
}


// =====================================================
// EJECUTAR UN ENROLAMIENTO
//
// BLOQUEANTE respecto al loop: mientras esta funcion corre no se sondea otra
// orden y no se procesa ninguna marcacion. Ver la nota de la cabecera del
// archivo sobre por que eso es correccion y no comodidad.
//
// Secuencia:
//
//   comprobacion previa de la ranura
//   getImage -> image2Tz(1)
//   retirar el dedo
//   getImage -> image2Tz(2)
//   createModel
//   comprobacion de la ranura (otra vez, justo antes de grabar)
//   storeModel(orden.ranura)
//
// La ranura NUNCA la elige el firmware: sale de la orden y se manda de vuelta
// tal cual. Si el servidor recibe otra, no asocia nada.
//
// Salga como salga, se termina en mostrarListo() y enrolando = false.
// =====================================================

void ejecutarEnrolamiento(
  const OrdenEnrolamiento& orden
) {

  enrolando =
    true;

  Serial.println();
  Serial.println(
    "============================"
  );
  Serial.println(
    "ENROLAMIENTO"
  );
  Serial.print(
    "Orden: "
  );
  Serial.println(
    orden.id
  );
  Serial.print(
    "Ranura reservada: "
  );
  Serial.println(
    orden.ranura
  );
  Serial.print(
    "Empleado: "
  );
  Serial.println(
    orden.nombreCorto
  );
  Serial.println(
    "============================"
  );

  mostrarEnrolInicio(
    orden.nombreCorto,
    orden.intento
  );

  delay(1200);

  // ---------------------------------------------------
  // COMPROBACION PREVIA DE LA RANURA
  //
  // Antes de pedirle el dedo a nadie. Si ya hay una plantilla heredada, no
  // tiene sentido hacer que la persona apoye el dedo dos veces para descubrir
  // al final que no se puede grabar. Ademas aca no hay ningun buffer que
  // perder: loadModel() todavia no compite con createModel().
  // ---------------------------------------------------

  uint8_t previa =
    estadoRanuraEnSensor(
      orden.ranura
    );

  if (
    previa ==
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "ranura_ocupada_en_sensor",
      "La ranura " +
        String(orden.ranura) +
        " ya tenia plantilla (comprobacion previa).",
      "RANURA OCUPADA",
      "N " + String(orden.ranura),
      true
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // La ranura reservada NO EXISTE en este sensor. No es un fallo del AS608 ni
  // una ranura ocupada: el servidor reservo fuera del rango real. Se adjunta el
  // indice para que aprenda la capacidad de verdad y la proxima reserva caiga
  // dentro. No se usa `ranura_ocupada_en_sensor` a proposito: ese motivo
  // dispara el reintento automatico del servidor, y reintentar no arregla una
  // capacidad mal declarada.
  if (
    previa ==
    FINGERPRINT_BADLOCATION
  ) {

    fallarEnrolamiento(
      orden,
      "fallo_guardado",
      "La ranura " +
        String(orden.ranura) +
        " no existe en este sensor (BADLOCATION).",
      "RANURA INVALIDA",
      "N " + String(orden.ranura),
      true
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  if (
    previa !=
    FINGERPRINT_DBRANGEFAIL
  ) {

    fallarEnrolamiento(
      orden,
      "sin_sensor",
      "El AS608 no contesto la comprobacion previa (codigo " +
        String(previa) +
        ").",
      "SENSOR",
      "Sin respuesta",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // ---------------------------------------------------
  // PRIMERA CAPTURA
  // ---------------------------------------------------

  reportarProgreso(
    orden,
    "esperando_dedo"
  );

  mostrarEnrolColoque(
    orden.nombreCorto
  );

  uint8_t errorCaptura =
    FINGERPRINT_OK;

  uint8_t p =
    esperarDedoEnrolamiento(
      ENROL_TIMEOUT_DEDO_MS,
      1,
      errorCaptura
    );

  if (
    p ==
    FINGERPRINT_TIMEOUT &&
    errorCaptura !=
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "captura_defectuosa",
      "La primera imagen no sirvio (codigo " +
        String(errorCaptura) +
        ").",
      "MALA LECTURA",
      "Limpie el dedo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  if (
    p ==
    FINGERPRINT_TIMEOUT
  ) {

    fallarEnrolamiento(
      orden,
      "timeout_dedo",
      "Nadie coloco el dedo en la primera captura.",
      "SIN DEDO",
      "Se agoto el tiempo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  if (
    p !=
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "sin_sensor",
      "El AS608 dejo de responder (codigo " +
        String(p) +
        ").",
      "SENSOR",
      "Sin respuesta",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // La imagen ya quedo convertida en el charBuffer 1 (esperarDedoEnrolamiento).
  reportarProgreso(
    orden,
    "primera_captura"
  );

  // ---------------------------------------------------
  // RETIRAR EL DEDO
  // ---------------------------------------------------

  mostrarEnrolRetire();

  reportarProgreso(
    orden,
    "retire_dedo"
  );

  if (
    !esperarRetiroDeEnrolamiento(
      ENROL_TIMEOUT_RETIRO_MS
    )
  ) {

    fallarEnrolamiento(
      orden,
      "timeout_dedo",
      "El dedo no se retiro entre las dos capturas.",
      "NO RETIRO",
      "Se agoto el tiempo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // ---------------------------------------------------
  // SEGUNDA CAPTURA
  // ---------------------------------------------------

  mostrarEnrolRepita(
    orden.nombreCorto
  );

  p =
    esperarDedoEnrolamiento(
      ENROL_TIMEOUT_DEDO_MS,
      2,
      errorCaptura
    );

  if (
    p ==
    FINGERPRINT_TIMEOUT &&
    errorCaptura !=
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "captura_defectuosa",
      "La segunda imagen no sirvio (codigo " +
        String(errorCaptura) +
        ").",
      "MALA LECTURA",
      "Limpie el dedo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  if (
    p ==
    FINGERPRINT_TIMEOUT
  ) {

    fallarEnrolamiento(
      orden,
      "timeout_dedo",
      "Nadie coloco el dedo en la segunda captura.",
      "SIN DEDO",
      "Se agoto el tiempo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  if (
    p !=
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "sin_sensor",
      "El AS608 dejo de responder (codigo " +
        String(p) +
        ").",
      "SENSOR",
      "Sin respuesta",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // La imagen ya quedo convertida en el charBuffer 2 (esperarDedoEnrolamiento).
  reportarProgreso(
    orden,
    "segunda_captura"
  );

  // ---------------------------------------------------
  // COMPONER LA PLANTILLA
  // ---------------------------------------------------

  uint8_t modelo =
    finger.createModel();

  Serial.print(
    "createModel = "
  );

  Serial.println(
    modelo
  );

  if (
    modelo ==
    FINGERPRINT_ENROLLMISMATCH
  ) {

    fallarEnrolamiento(
      orden,
      "dedos_no_coinciden",
      "Las dos capturas no eran del mismo dedo.",
      "DEDOS DISTINTOS",
      "Use el mismo dedo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  if (
    modelo !=
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "fallo_modelo",
      "El sensor no compuso la plantilla (codigo " +
        String(modelo) +
        ").",
      "SIN PLANTILLA",
      "Intente de nuevo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // ---------------------------------------------------
  // COMPROBACION DE LA RANURA, JUSTO ANTES DE GRABAR
  //
  // Segunda pasada. Si devuelve OK, la ranura tiene plantilla: se aborta y da
  // igual que loadModel haya pisado el charBuffer 1, porque no se va a grabar.
  // Si devuelve el error de "no hay plantilla", el sensor no transfirio nada y
  // el modelo recien compuesto sigue intacto para el storeModel de abajo.
  // ---------------------------------------------------

  uint8_t ocupada =
    estadoRanuraEnSensor(
      orden.ranura
    );

  if (
    ocupada ==
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "ranura_ocupada_en_sensor",
      "La ranura " +
        String(orden.ranura) +
        " tenia una plantilla que el sistema no conocia.",
      "RANURA OCUPADA",
      "N " + String(orden.ranura),
      true
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // Fuera de rango, descubierto recien ahora. Sin esta rama caeria en el
  // `sin_sensor` de abajo y el operador leeria "el sensor no responde" sobre un
  // sensor que respondio perfectamente: dijo que esa pagina no existe.
  if (
    ocupada ==
    FINGERPRINT_BADLOCATION
  ) {

    fallarEnrolamiento(
      orden,
      "fallo_guardado",
      "La ranura " +
        String(orden.ranura) +
        " no existe en este sensor (BADLOCATION).",
      "RANURA INVALIDA",
      "N " + String(orden.ranura),
      true
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  if (
    ocupada !=
    FINGERPRINT_DBRANGEFAIL
  ) {

    // No se pudo comprobar. No se graba: sobrescribir una plantilla ajena por
    // no poder mirar seria exactamente lo que el contrato prohibe.
    fallarEnrolamiento(
      orden,
      "sin_sensor",
      "No se pudo comprobar la ranura antes de grabar (codigo " +
        String(ocupada) +
        ").",
      "SENSOR",
      "No se comprobo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // ---------------------------------------------------
  // GRABAR
  // ---------------------------------------------------

  mostrarEnrolGuardando(
    orden.ranura
  );

  reportarProgreso(
    orden,
    "guardando"
  );

  uint8_t guardado =
    finger.storeModel(
      orden.ranura
    );

  Serial.print(
    "storeModel = "
  );

  Serial.println(
    guardado
  );

  if (
    guardado !=
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "fallo_guardado",
      "El sensor no guardo la plantilla (codigo " +
        String(guardado) +
        ").",
      "NO SE GUARDO",
      "Intente de nuevo",
      false
    );

    mostrarListo();

    enrolando =
      false;

    return;
  }

  // ---------------------------------------------------
  // RESULTADO
  //
  // fingerprint_id es EXACTAMENTE orden.ranura. El servidor lo compara contra
  // la ranura que reservo; si no coincide, no asocia nada.
  // ---------------------------------------------------

  String cuerpo =
    "{\"token\":\"" +
    orden.token +
    "\",\"exito\":true,\"fingerprint_id\":" +
    String(orden.ranura) +
    "}";

  bool entregado =
    reportarResultado(
      orden,
      cuerpo
    );

  if (
    entregado
  ) {

    mostrarEnrolOk(
      orden.nombreCorto,
      orden.ranura
    );

    beepExito();

  } else {

    // La plantilla ESTA grabada en el sensor; lo que fallo es avisar. El
    // reenvio queda pendiente y el endpoint es idempotente.
    mostrarEnrolFallo(
      "GRABADA SIN AVISO",
      "Reintentando..."
    );

    // La plantilla si quedo: esto es un problema de red, no del dedo.
    beepAdvertencia();
  }

  pausaConBuzzer(2500);

  mostrarListo();

  enrolando =
    false;
}


// =====================================================
// SONDEO
//
// La mitad del truco que permite que el servidor le pida algo al lector sin
// poder llamarlo. Solo se ejecuta cuando el AS608 acaba de decir NOFINGER: ver
// atenderEnrolamiento().
// =====================================================

void sondearEnrolamiento() {

  String respuesta;

  int http =
    peticionEnrolamiento(
      "GET",
      "/api/asistencia/enrolamiento/pendiente",
      "",
      respuesta
    );

  if (
    http != 200
  ) {

    if (
      http == 401
    ) {

      Serial.println(
        "SONDEO: token del lector rechazado"
      );
    }

    return;
  }

  if (
    !obtenerBoolJson(
      respuesta,
      "hay_orden"
    )
  ) {

    // El servidor aprovecha el sondeo para pedir el indice cuando no lo tiene.
    if (
      obtenerBoolJson(
        respuesta,
        "sincronizar_indice"
      )
    ) {

      Serial.println(
        "EL SERVIDOR PIDE EL INDICE DEL SENSOR"
      );

      sincronizarIndiceSensor();
    }

    return;
  }

  Serial.println();
  Serial.println(
    "HAY ORDEN DE ENROLAMIENTO"
  );

  Serial.println(
    respuesta
  );

  String objOrden =
    extraerObjetoJson(
      respuesta,
      "orden"
    );

  if (
    objOrden.length() == 0
  ) {

    Serial.println(
      "ORDEN ILEGIBLE"
    );

    return;
  }

  String objEmpleado =
    extraerObjetoJson(
      objOrden,
      "empleado"
    );

  // Se saca el sub-objeto del empleado antes de leer el id: los dos objetos
  // tienen una clave "id" y el parser es plano.
  String ordenPlano =
    objOrden;

  if (
    objEmpleado.length() > 0
  ) {

    ordenPlano.replace(
      objEmpleado,
      ""
    );
  }

  OrdenEnrolamiento orden;

  orden.id =
    obtenerIntJson(
      ordenPlano,
      "id"
    );

  orden.ranura =
    obtenerIntJson(
      ordenPlano,
      "ranura"
    );

  orden.capacidad =
    obtenerIntJson(
      ordenPlano,
      "capacidad"
    );

  orden.intento =
    obtenerIntJson(
      ordenPlano,
      "intento"
    );

  orden.expiraEn =
    obtenerIntJson(
      ordenPlano,
      "expira_en"
    );

  orden.token =
    obtenerValorJson(
      ordenPlano,
      "token"
    );

  orden.nombreCorto =
    obtenerValorJson(
      objEmpleado,
      "nombre_corto"
    );

  if (
    orden.nombreCorto.length() == 0
  ) {

    orden.nombreCorto =
      "EMPLEADO";
  }

  orden.valida =
    orden.id > 0 &&
    orden.ranura >= 0 &&
    orden.token.length() > 0;

  if (
    !orden.valida
  ) {

    Serial.println(
      "ORDEN INCOMPLETA: no se ejecuta"
    );

    return;
  }

  ejecutarEnrolamiento(
    orden
  );
}


// =====================================================
// ATENDER EL ENROLAMIENTO
//
// Punto de entrada desde el loop: en la rama NOFINGER y, por el dedo
// fantasma, tambien cuando identificarHuella() termina sin ninguna lectura
// valida (RH_SIN_LECTURA). Todo lo que hay aca dentro puede tardar segundos,
// asi que nada de esto puede correr con un dedo reconocible apoyado ni entre
// el getImage() del loop y identificarHuella().
// =====================================================

void atenderEnrolamiento() {

  if (
    enrolando
  ) {
    return;
  }

  if (
    WiFi.status() !=
    WL_CONNECTED
  ) {
    return;
  }

  // Un resultado sin entregar tiene prioridad sobre recoger otra orden: si se
  // tomara una nueva, el cuerpo pendiente se perderia. El endpoint es
  // idempotente, asi que reenviarlo no duplica nada.
  if (
    resultadoPendiente
  ) {

    if (
      millis() <
      proximoReintentoPendiente
    ) {
      return;
    }

    String respuesta;

    int http =
      peticionEnrolamiento(
        "POST",
        "/api/asistencia/enrolamiento/" +
          String(resultadoPendienteOrden) +
          "/resultado",
        resultadoPendienteCuerpo,
        respuesta
      );

    Serial.print(
      "REENVIO DE RESULTADO PENDIENTE HTTP = "
    );

    Serial.println(
      http
    );

    if (
      http == 200 ||
      http == 409 ||
      http == 422 ||
      http == 404
    ) {

      // 404 incluido: la orden ya no existe o su token cambio. Insistir no la
      // va a resucitar y el operador lo ve en la web.
      resultadoPendiente =
        false;

      resultadoPendienteCuerpo =
        "";

      resultadoPendienteOrden =
        0;

    } else {

      proximoReintentoPendiente =
        millis() + 15000;
    }

    return;
  }

  if (
    millis() -
      ultimoSondeoEnrolamiento <
    ENROL_SONDEO_MS
  ) {
    return;
  }

  ultimoSondeoEnrolamiento =
    millis();

  sondearEnrolamiento();
}



// =====================================================
// SENSOR: BUSCAR EN QUE PINES QUEDO
// =====================================================

bool probarSensorEn(
  int8_t rx,
  int8_t tx,
  uint32_t baudios
) {

  FingerSerial.end();

  FingerSerial.begin(
    baudios,
    SERIAL_8N1,
    rx,
    tx
  );

  // finger.begin() NO: solo vuelve a abrir el puerto que ya esta abierto y
  // mete un delay(1000) fijo, que multiplicado por cada pareja son minutos.
  delay(60);

  // Basura que haya dejado el intento anterior o el arranque del sensor.
  while (
    FingerSerial.available()
  ) {
    FingerSerial.read();
  }

  return finger.verifyPassword();
}


// Un TX de UART en reposo esta en ALTO. Si el pin se queda en BAJO con un
// pull-down, ahi no hay ningun sensor transmitiendo y no vale la pena gastar
// el segundo de timeout de verifyPassword() en cada pareja. IO15/16 tienen
// pull-ups fisicos (bus I2C) y siempre pasan: por eso van al final.
bool lineaEnReposo(
  int8_t pin
) {

  FingerSerial.end();

  pinMode(
    pin,
    INPUT_PULLDOWN
  );

  delay(2);

  return digitalRead(pin) == HIGH;
}


bool buscarSensor() {

  preferencias.begin(
    "asistencia",
    false
  );

  int8_t rxGuardado =
    preferencias.getChar("fp_rx", -1);

  int8_t txGuardado =
    preferencias.getChar("fp_tx", -1);

  uint32_t baudGuardado =
    preferencias.getUInt("fp_baud", FP_BAUDIOS[0]);

  // Arranque del AS608: tarda ~200-400 ms en contestar tras recibir energia.
  delay(400);

  if (
    rxGuardado >= 0 &&
    txGuardado >= 0 &&
    probarSensorEn(
      rxGuardado,
      txGuardado,
      baudGuardado
    )
  ) {

    fpRx = rxGuardado;
    fpTx = txGuardado;
    fpBaudios = baudGuardado;

    preferencias.end();

    return true;
  }

  mostrarArranque(
    "Buscando sensor..."
  );

  const uint8_t total =
    sizeof(FP_PINES_CANDIDATOS);

  for (
    uint32_t baudios : FP_BAUDIOS
  ) {

  for (
    uint8_t i = 0;
    i < total;
    i++
  ) {

    for (
      uint8_t j = 0;
      j < total;
      j++
    ) {

      int8_t rx =
        FP_PINES_CANDIDATOS[i];

      int8_t tx =
        FP_PINES_CANDIDATOS[j];

      if (
        rx == tx ||
        !lineaEnReposo(rx)
      ) {
        continue;
      }

      {

        Serial.printf(
          "Probando sensor RX=%d TX=%d a %lu\n",
          rx,
          tx,
          (unsigned long) baudios
        );

        if (
          probarSensorEn(
            rx,
            tx,
            baudios
          )
        ) {

          fpRx = rx;
          fpTx = tx;
          fpBaudios = baudios;

          preferencias.putChar("fp_rx", rx);
          preferencias.putChar("fp_tx", tx);
          preferencias.putUInt("fp_baud", baudios);

          preferencias.end();

          return true;
        }
      }
    }
  }

  }

  // Sin respuesta en ningun lado: se deja el UART en un pin inocuo.
  FingerSerial.end();

  preferencias.end();

  return false;
}


// =====================================================
// SETUP
// =====================================================

void setup() {

  Serial.begin(
    115200
  );

#if ARDUINO_USB_CDC_ON_BOOT && ARDUINO_USB_MODE
  // Serial es el USB nativo (HWCDC). Enchufado a una PC sin nadie leyendo el
  // puerto, cada print espera hasta 100 ms a que se vacie el buffer: el loop
  // se arrastra y el lector parece apagado (visto el 07/10/2026). Sin espera,
  // lo que no entra se descarta; el diagnostico por Serial sigue funcionando
  // cuando hay un monitor abierto.
  Serial.setTxTimeoutMs(
    0
  );
#endif

  delay(
    400
  );

  // Pantalla
  pinMode(
    TFT_BL,
    OUTPUT
  );

  digitalWrite(
    TFT_BL,
    HIGH
  );

  SPI.begin(
    TFT_SCLK,
    TFT_MISO,
    TFT_MOSI,
    TFT_CS
  );

  tft.begin(
    40000000
  );

  tft.setRotation(
    PANTALLA_ROTACION
  );

  // El panel de la ES3C28P es IPS y trae la logica de color invertida: sin
  // esto el negro sale blanco y el crema sale azul.
  tft.invertDisplay(
    PANTALLA_INVERTIDA
  );

  tft.setTextWrap(
    false
  );

  led(0, 0, 0);

  mostrarInicio();

  // SENSOR
  //
  // Antes que el audio: si el sensor resultara estar en IO15/16 (bus I2C del
  // codec), el audio no se inicia para no pelear por esos pines.
  mostrarArranque(
    "Iniciando sensor"
  );

  // Sin sensor no hay nada que hacer, pero tampoco hace falta reiniciar: se
  // reintenta solo, asi que basta con corregir el cable en caliente.
  while (
    !buscarSensor()
  ) {

    Serial.println(
      "ERROR SENSOR AS608: no contesta en ningun par de pines"
    );

    led(60, 0, 0);

    mostrarError(
      "Sensor sin respuesta",
      "Revise los cables del sensor"
    );

    delay(3000);
  }

  led(0, 0, 0);

  Serial.printf(
    "SENSOR AS608 OK en RX=%d TX=%d a %lu\n",
    fpRx,
    fpTx,
    (unsigned long) fpBaudios
  );

  bool sensorEnBusI2C =
    fpRx == I2C_SDA ||
    fpRx == I2C_SCL ||
    fpTx == I2C_SDA ||
    fpTx == I2C_SCL;

  if (
    sensorEnBusI2C
  ) {

    Serial.println(
      "AUDIO desactivado: el sensor ocupa el bus I2C del codec"
    );

  } else {

    iniciarAudio();

    sonar(
      SONIDO_ARRANQUE
    );
  }

  finger.getTemplateCount();

  Serial.print(
    "Huellas guardadas: "
  );

  Serial.println(
    finger.templateCount
  );

  // CAPACIDAD REAL DEL SENSOR
  //
  // verifyPassword() NO la llena: el campo `capacity` de la libreria arranca en
  // 64 y solo lo escribe getParameters(). Si no se lee, no se manda indice: un
  // valor inventado se descubriria fallando un enrolamiento contra una ranura
  // inexistente.
  if (
    finger.getParameters() ==
    FINGERPRINT_OK
  ) {

    capacidadSensor =
      finger.capacity;

    Serial.print(
      "Capacidad del sensor: "
    );

    Serial.println(
      capacidadSensor
    );

  } else {

    Serial.println(
      "NO SE PUDO LEER LA CAPACIDAD DEL SENSOR"
    );
  }

  // WIFI
  mostrarArranque(
    "Conectando WiFi"
  );

  conectarWiFi();

  // PING REAL
  if (
    WiFi.status() ==
    WL_CONNECTED
  ) {

    mostrarArranque(
      "Probando servidor"
    );

    probarServidor();

  } else {

    servidorDisponible =
      false;

    dispositivoAutorizado =
      false;
  }

  // INDICE DEL SENSOR
  //
  // Al arrancar, para que el servidor pueda elegir ranura sin chocar con las
  // plantillas heredadas. Si falla no pasa nada: el sondeo se lo volvera a
  // pedir con sincronizar_indice.
  if (
    dispositivoAutorizado &&
    capacidadSensor > 0
  ) {

    sincronizarIndiceSensor();
  }

  mostrarListo();
}


// =====================================================
// LOOP
// =====================================================

void loop() {

  atenderBuzzer();

  mantenerWiFi();

  uint8_t p =
    finger.getImage();

  if (
    p ==
    FINGERPRINT_NOFINGER
  ) {

    // El UNICO punto del loop donde se sabe con certeza que no hay nadie
    // apoyado. El sondeo del enrolamiento vive aca y en ningun otro sitio:
    // ponerlo antes del getImage() le robaria la ventana a la marcacion, y
    // ponerlo despues le quitaria a identificarHuella() la imagen que ya
    // capturo este mismo getImage().
    atenderEnrolamiento();

    delay(
      POLLING_MS
    );

    return;
  }

  if (
    p ==
    FINGERPRINT_PACKETRECIEVEERR
  ) {

    delay(
      POLLING_MS
    );

    return;
  }

  if (
    p !=
    FINGERPRINT_OK
  ) {

    delay(
      POLLING_MS
    );

    return;
  }

  Serial.println();

  Serial.println(
    "============================"
  );

  Serial.println(
    "POSIBLE DEDO"
  );

  Serial.println(
    "============================"
  );

  uint16_t id =
    0;

  uint16_t confianza =
    0;

  ResultadoHuella resultado =
    identificarHuella(
      id,
      confianza
    );

  // ==================================================
  // RECONOCIDA
  // ==================================================

  if (
    resultado ==
    RH_RECONOCIDA
  ) {

    Serial.println();

    Serial.println(
      ">>> HUELLA RECONOCIDA <<<"
    );

    Serial.print(
      "ID: "
    );

    Serial.println(
      id
    );

    Serial.print(
      "Confianza: "
    );

    Serial.println(
      confianza
    );

    mostrarRegistrando();

    RespuestaAPI respuesta =
      enviarMarcacion(
        id
      );

    // -----------------------------------------
    // OK
    // -----------------------------------------

    if (
      respuesta.httpCode ==
      200 &&
      (
        respuesta.ok ||
        respuesta.estado ==
        "registrada"
      )
    ) {

      dispositivoAutorizado =
        true;

      String nombre =
        respuesta.nombre;

      String tipo =
        respuesta.tipo;

      String hora =
        respuesta.hora;

      if (
        nombre.length() == 0
      ) {
        nombre =
          "EMPLEADO";
      }

      if (
        tipo.length() == 0
      ) {
        tipo =
          "REGISTRADA";
      }

      mostrarExito(
        nombre,
        tipo,
        hora
      );

      // Recien aca: el AS608 encontro la huella hace rato, pero el unico que
      // decide si hubo marcacion es el servidor.
      avisarMarcacion(
        respuesta.tipo
      );

      esperarRetiroConMensaje(
        1200,
        3000
      );

      mostrarListo();

      return;
    }

    // -----------------------------------------
    // COOLDOWN
    // -----------------------------------------

    if (
      respuesta.httpCode ==
      409 ||
      respuesta.estado ==
      "cooldown"
    ) {

      dispositivoAutorizado =
        true;

      mostrarCooldown(
        respuesta.esperaSegundos
      );

      avisarYaMarcaste();

      esperarRetiroConMensaje(
        1200,
        2600
      );

      mostrarListo();

      return;
    }

    // -----------------------------------------
    // HUELLA NO ASOCIADA EN LARAVEL
    // -----------------------------------------

    if (
      respuesta.httpCode ==
      404 &&
      respuesta.estado ==
      "huella_desconocida"
    ) {

      dispositivoAutorizado =
        true;

      mostrarDesconocida();

      avisarNoRegistrada();

      esperarRetiroConMensaje(
        1200,
        2600
      );

      mostrarListo();

      return;
    }

    // -----------------------------------------
    // TOKEN
    // -----------------------------------------

    if (
      respuesta.httpCode ==
      401
    ) {

      dispositivoAutorizado =
        false;

      mostrarError(
        "NO AUTORIZADO",
        "Token rechazado"
      );

      beepAdvertencia();

      esperarRetiroConMensaje(
        1500,
        3000
      );

      mostrarListo();

      return;
    }

    // -----------------------------------------
    // SIN SERVIDOR
    // -----------------------------------------

    if (
      respuesta.httpCode <=
      0
    ) {

      mostrarError(
        "SIN SERVIDOR",
        respuesta.mensaje
      );

      beepAdvertencia();

      esperarRetiroConMensaje(
        1500,
        3000
      );

      mostrarListo();

      return;
    }

    String mensaje =
      respuesta.mensaje;

    if (
      mensaje.length() == 0
    ) {

      mensaje =
        "HTTP " +
        String(
          respuesta.httpCode
        );
    }

    mostrarError(
      "ERROR API",
      mensaje
    );

    beepAdvertencia();

    esperarRetiroConMensaje(
      1500,
      3000
    );

    mostrarListo();

    return;
  }

  // ==================================================
  // DESCONOCIDA
  // ==================================================

  if (
    resultado ==
    RH_DESCONOCIDA
  ) {

    Serial.println();

    Serial.println(
      ">>> HUELLA DESCONOCIDA <<<"
    );

    mostrarDesconocida();

    avisarNoRegistrada();

    esperarRetiroConMensaje(
      1200,
      2600
    );

    mostrarListo();

    return;
  }

  // ==================================================
  // INDETERMINADA
  // ==================================================

  if (
    resultado ==
    RH_INDETERMINADA
  ) {

    Serial.println(
      "Lectura valida pero indeterminada"
    );

    mostrarAjustarDedo();

    esperarRetiroConMensaje(
      800,
      1800
    );

    mostrarListo();

    return;
  }

  // ==================================================
  // RUIDO
  // ==================================================

  if (
    resultado ==
    RH_SIN_LECTURA
  ) {

    Serial.println(
      "Ruido / captura mala ignorada"
    );

    if (
      pantallaActual !=
      PANTALLA_LISTO
    ) {

      mostrarListo();
    }

    // Con el dedo fantasma (ver esImagenFantasma) el sensor casi nunca
    // devuelve NOFINGER, y la rama NOFINGER era el unico sitio del sondeo:
    // las ordenes de la web no llegaban al lector («le doy a registrar y no
    // pasa nada»). Aca identificarHuella() ya termino sin ninguna lectura
    // valida, asi que no hay imagen que perder ni marcacion en curso.
    atenderEnrolamiento();

    delay(
      60
    );

    return;
  }

  // ==================================================
  // RETIRO
  // ==================================================

  if (
    resultado ==
    RH_RETIRADA
  ) {

    if (
      pantallaActual !=
      PANTALLA_LISTO
    ) {

      mostrarListo();
    }

    delay(
      50
    );

    return;
  }
}
