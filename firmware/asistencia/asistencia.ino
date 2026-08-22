#include <WiFi.h>
#include <SPI.h>
#include <Adafruit_GFX.h>
#include <Adafruit_ST7735.h>
#include <Adafruit_Fingerprint.h>

#include "secretos.h"

// =====================================================
// DULCES LA NEGRITA
// CONTROL DE ASISTENCIA
// ESP32 + AS608 + TFT ST7735 1.44" 128x128
// =====================================================
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
// WIFI / LARAVEL
//
// WIFI_SSID, WIFI_PASSWORD, SERVER_IP, SERVER_PORT, SERVER_HOST, DEVICE_CODE
// y DEVICE_TOKEN estan en secretos.h.
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
//                              NetworkClient (alias de WiFiClient) ya NO lo
//                              sobrescribe, asi que aca no hay segundos.
//   setConnectionTimeout()  -> NetworkClient::_timeout, el del connect().
//                              Se fija explicitamente para no depender del
//                              valor por defecto del core.
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
// TFT ST7735
//
// RST -> GPIO33
// CS  -> GPIO5
// D/C -> GPIO27
// DIN -> GPIO23
// CLK -> GPIO18
// =====================================================

#define TFT_CS    5
#define TFT_DC    27
#define TFT_RST   33
#define TFT_SCLK  18
#define TFT_MOSI  23

Adafruit_ST7735 tft(
  TFT_CS,
  TFT_DC,
  TFT_RST
);


// =====================================================
// SENSOR AS608
// =====================================================

#define FP_RX 16
#define FP_TX 17

HardwareSerial FingerSerial(2);

Adafruit_Fingerprint finger(
  &FingerSerial
);


// =====================================================
// COLORES
// =====================================================

#define COLOR_NEGRO      ST77XX_BLACK
#define COLOR_BLANCO     ST77XX_WHITE
#define COLOR_ROJO       ST77XX_RED
#define COLOR_VERDE      ST77XX_GREEN
#define COLOR_AMARILLO   ST77XX_YELLOW
#define COLOR_CYAN       ST77XX_CYAN

#define COLOR_GRIS       0x8410
#define COLOR_GRIS_OSC   0x2104
#define COLOR_ROJO_OSC   0x8000
#define COLOR_VERDE_OSC  0x0320
#define COLOR_AZUL_OSC   0x0010


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

void textoCentrado(
  String texto,
  int y,
  uint8_t size,
  uint16_t color
);

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

uint8_t esperarDedoEnrolamiento(
  unsigned long maximoMs
);

bool esperarRetiroDeEnrolamiento(
  unsigned long maximoMs
);

uint8_t estadoRanuraEnSensor(
  uint16_t ranura
);


// =====================================================
// TEXTO CENTRADO
// =====================================================

void textoCentrado(
  String texto,
  int y,
  uint8_t size,
  uint16_t color
) {

  int16_t x1;
  int16_t y1;

  uint16_t ancho;
  uint16_t alto;

  tft.setTextSize(size);
  tft.setTextColor(color);

  tft.getTextBounds(
    texto,
    0,
    y,
    &x1,
    &y1,
    &ancho,
    &alto
  );

  int x =
    (128 - ancho) / 2;

  if (x < 0) {
    x = 0;
  }

  tft.setCursor(
    x,
    y
  );

  tft.print(texto);
}


// =====================================================
// NORMALIZAR TEXTO
// =====================================================

String normalizarTextoTFT(
  String texto
) {

  texto.replace("á", "a");
  texto.replace("é", "e");
  texto.replace("í", "i");
  texto.replace("ó", "o");
  texto.replace("ú", "u");

  texto.replace("Á", "A");
  texto.replace("É", "E");
  texto.replace("Í", "I");
  texto.replace("Ó", "O");
  texto.replace("Ú", "U");

  texto.replace("ñ", "n");
  texto.replace("Ñ", "N");

  return texto;
}


// =====================================================
// CABECERA
// =====================================================

void dibujarCabecera() {

  tft.fillRect(
    0,
    0,
    128,
    49,
    COLOR_NEGRO
  );

  tft.fillRoundRect(
    8,
    4,
    112,
    3,
    2,
    COLOR_ROJO
  );

  textoCentrado(
    "DULCES",
    12,
    1,
    COLOR_ROJO
  );

  textoCentrado(
    "LA NEGRITA",
    24,
    1,
    COLOR_BLANCO
  );

  textoCentrado(
    "CONTROL DE ASISTENCIA",
    37,
    1,
    COLOR_GRIS
  );

  tft.drawFastHLine(
    8,
    48,
    112,
    COLOR_ROJO
  );
}


// =====================================================
// FOOTER
// =====================================================

void dibujarFooter() {

  tft.fillRect(
    0,
    111,
    128,
    17,
    COLOR_NEGRO
  );

  tft.drawFastHLine(
    8,
    111,
    112,
    COLOR_GRIS_OSC
  );

  tft.setTextSize(1);

  // WIFI
  tft.fillCircle(
    12,
    120,
    3,
    WiFi.status() == WL_CONNECTED
      ? COLOR_VERDE
      : COLOR_ROJO
  );

  tft.setTextColor(
    COLOR_BLANCO
  );

  tft.setCursor(
    20,
    117
  );

  tft.print(
    "WIFI"
  );

  // API
  tft.fillCircle(
    77,
    120,
    3,
    servidorDisponible &&
    dispositivoAutorizado
      ? COLOR_VERDE
      : COLOR_ROJO
  );

  tft.setCursor(
    85,
    117
  );

  tft.print(
    "API"
  );
}


// =====================================================
// TARJETA LISTO
// =====================================================

void dibujarTarjetaLista() {

  tft.fillRoundRect(
    12,
    56,
    104,
    47,
    7,
    COLOR_GRIS_OSC
  );

  tft.drawRoundRect(
    12,
    56,
    104,
    47,
    7,
    COLOR_GRIS
  );

  tft.fillCircle(
    27,
    69,
    5,
    COLOR_VERDE
  );

  tft.drawLine(
    24,
    69,
    27,
    72,
    COLOR_NEGRO
  );

  tft.drawLine(
    27,
    72,
    31,
    65,
    COLOR_NEGRO
  );

  tft.setTextSize(2);

  tft.setTextColor(
    COLOR_VERDE
  );

  tft.setCursor(
    40,
    62
  );

  tft.print(
    "LISTO"
  );

  tft.drawFastHLine(
    21,
    80,
    86,
    COLOR_GRIS
  );

  textoCentrado(
    "MARQUE SU",
    85,
    1,
    COLOR_BLANCO
  );

  textoCentrado(
    "ASISTENCIA",
    95,
    1,
    COLOR_ROJO
  );
}


// =====================================================
// INICIO
// =====================================================

void mostrarInicio() {

  pantallaActual =
    PANTALLA_NINGUNA;

  tft.fillScreen(
    COLOR_NEGRO
  );

  tft.fillRoundRect(
    19,
    19,
    90,
    4,
    2,
    COLOR_ROJO
  );

  textoCentrado(
    "DULCES",
    36,
    2,
    COLOR_ROJO
  );

  textoCentrado(
    "LA NEGRITA",
    59,
    2,
    COLOR_BLANCO
  );

  tft.drawFastHLine(
    25,
    84,
    78,
    COLOR_GRIS
  );

  textoCentrado(
    "CONTROL",
    94,
    1,
    COLOR_GRIS
  );

  textoCentrado(
    "DE ASISTENCIA",
    105,
    1,
    COLOR_GRIS
  );
}


// =====================================================
// LISTO
// =====================================================

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

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();
  dibujarTarjetaLista();
  dibujarFooter();
}


// =====================================================
// LEYENDO
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

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    10,
    57,
    108,
    45,
    7,
    COLOR_GRIS_OSC
  );

  textoCentrado(
    "LEYENDO...",
    64,
    2,
    COLOR_AMARILLO
  );

  textoCentrado(
    "No retire el dedo",
    90,
    1,
    COLOR_BLANCO
  );

  dibujarFooter();
}


// =====================================================
// REGISTRANDO
// =====================================================

void mostrarRegistrando() {

  pantallaActual =
    PANTALLA_REGISTRANDO;

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    10,
    57,
    108,
    45,
    7,
    COLOR_GRIS_OSC
  );

  textoCentrado(
    "REGISTRANDO",
    65,
    1,
    COLOR_CYAN
  );

  textoCentrado(
    "ESPERE...",
    82,
    2,
    COLOR_BLANCO
  );

  dibujarFooter();
}


// =====================================================
// EXITO
// =====================================================

void mostrarExito(
  String nombre,
  String tipo,
  String hora
) {

  pantallaActual =
    PANTALLA_EXITO;

  nombre =
    normalizarTextoTFT(nombre);

  tipo =
    normalizarTextoTFT(tipo);

  nombre.toUpperCase();
  tipo.toUpperCase();

  if (
    nombre.length() > 19
  ) {
    nombre =
      nombre.substring(
        0,
        19
      );
  }

  if (
    hora.length() > 5
  ) {
    hora =
      hora.substring(
        0,
        5
      );
  }

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    6,
    54,
    116,
    54,
    7,
    COLOR_VERDE_OSC
  );

  tft.drawCircle(
    19,
    67,
    9,
    COLOR_VERDE
  );

  tft.drawLine(
    14,
    67,
    18,
    72,
    COLOR_VERDE
  );

  tft.drawLine(
    18,
    72,
    25,
    61,
    COLOR_VERDE
  );

  tft.setTextSize(1);

  tft.setTextColor(
    COLOR_VERDE
  );

  tft.setCursor(
    34,
    58
  );

  tft.print(
    "MARCACION OK"
  );

  textoCentrado(
    tipo,
    73,
    2,
    COLOR_BLANCO
  );

  textoCentrado(
    nombre,
    95,
    1,
    COLOR_BLANCO
  );

  if (
    hora.length() > 0
  ) {

    tft.fillRoundRect(
      87,
      54,
      34,
      11,
      3,
      COLOR_VERDE
    );

    tft.setTextColor(
      COLOR_NEGRO
    );

    tft.setTextSize(
      1
    );

    tft.setCursor(
      89,
      56
    );

    tft.print(
      hora
    );
  }

  dibujarFooter();
}


// =====================================================
// DESCONOCIDA
// =====================================================

void mostrarDesconocida() {

  pantallaActual =
    PANTALLA_DESCONOCIDA;

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    54,
    114,
    54,
    7,
    COLOR_ROJO_OSC
  );

  tft.drawCircle(
    20,
    69,
    10,
    COLOR_ROJO
  );

  tft.drawLine(
    15,
    64,
    25,
    74,
    COLOR_ROJO
  );

  tft.drawLine(
    25,
    64,
    15,
    74,
    COLOR_ROJO
  );

  tft.setTextColor(
    COLOR_ROJO
  );

  tft.setTextSize(
    1
  );

  tft.setCursor(
    37,
    58
  );

  tft.print(
    "NO REGISTRADA"
  );

  textoCentrado(
    "HUELLA",
    77,
    2,
    COLOR_BLANCO
  );

  textoCentrado(
    "DESCONOCIDA",
    97,
    1,
    COLOR_ROJO
  );

  dibujarFooter();
}


// =====================================================
// AJUSTAR DEDO
// =====================================================

void mostrarAjustarDedo() {

  pantallaActual =
    PANTALLA_AJUSTAR;

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    55,
    114,
    52,
    7,
    0x4208
  );

  textoCentrado(
    "COLOQUE",
    62,
    2,
    COLOR_AMARILLO
  );

  textoCentrado(
    "BIEN EL DEDO",
    84,
    1,
    COLOR_BLANCO
  );

  textoCentrado(
    "Intente de nuevo",
    99,
    1,
    COLOR_GRIS
  );

  dibujarFooter();
}


// =====================================================
// COOLDOWN
// =====================================================

void mostrarCooldown(
  int segundos
) {

  pantallaActual =
    PANTALLA_COOLDOWN;

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    55,
    114,
    52,
    7,
    0x6320
  );

  textoCentrado(
    "YA MARCO",
    62,
    2,
    COLOR_AMARILLO
  );

  if (
    segundos > 0
  ) {

    textoCentrado(
      "Espere " +
      String(segundos) +
      " segundos",
      88,
      1,
      COLOR_BLANCO
    );

  } else {

    textoCentrado(
      "Registro reciente",
      88,
      1,
      COLOR_BLANCO
    );
  }

  textoCentrado(
    "No se duplico",
    101,
    1,
    COLOR_GRIS
  );

  dibujarFooter();
}


// =====================================================
// ERROR
// =====================================================

void mostrarError(
  String titulo,
  String detalle
) {

  pantallaActual =
    PANTALLA_ERROR;

  titulo =
    normalizarTextoTFT(
      titulo
    );

  detalle =
    normalizarTextoTFT(
      detalle
    );

  titulo.toUpperCase();

  if (
    titulo.length() > 18
  ) {
    titulo =
      titulo.substring(
        0,
        18
      );
  }

  if (
    detalle.length() > 19
  ) {
    detalle =
      detalle.substring(
        0,
        19
      );
  }

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    55,
    114,
    52,
    7,
    COLOR_ROJO_OSC
  );

  textoCentrado(
    "ERROR",
    61,
    2,
    COLOR_ROJO
  );

  textoCentrado(
    titulo,
    85,
    1,
    COLOR_BLANCO
  );

  textoCentrado(
    detalle,
    99,
    1,
    COLOR_GRIS
  );

  dibujarFooter();
}


// =====================================================
// PANTALLAS DE ENROLAMIENTO
//
// Todas llevan guard antiparpadeo sobre (pantallaActual + pantallaDetalle).
// Se pintan dentro de bucles de espera que giran cada 40-60 ms; sin el guard
// la pantalla parpadearia sin parar durante los 20 s de espera del dedo.
// =====================================================

void mostrarEnrolInicio(
  String nombre,
  int intento
) {

  String detalle =
    nombre +
    "|" +
    String(intento);

  if (
    pantallaActual ==
      PANTALLA_ENROL_INICIO &&
    pantallaDetalle ==
      detalle
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_ENROL_INICIO;

  pantallaDetalle =
    detalle;

  nombre =
    normalizarTextoTFT(nombre);

  nombre.toUpperCase();

  if (
    nombre.length() > 19
  ) {
    nombre =
      nombre.substring(
        0,
        19
      );
  }

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    55,
    114,
    52,
    7,
    COLOR_AZUL_OSC
  );

  textoCentrado(
    "REGISTRO",
    60,
    2,
    COLOR_CYAN
  );

  textoCentrado(
    "DE HUELLA",
    80,
    1,
    COLOR_BLANCO
  );

  textoCentrado(
    nombre,
    94,
    1,
    COLOR_BLANCO
  );

  dibujarFooter();
}


void mostrarEnrolColoque(
  String nombre
) {

  if (
    pantallaActual ==
      PANTALLA_ENROL_COLOQUE &&
    pantallaDetalle ==
      nombre
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_ENROL_COLOQUE;

  pantallaDetalle =
    nombre;

  String mostrado =
    normalizarTextoTFT(nombre);

  mostrado.toUpperCase();

  if (
    mostrado.length() > 19
  ) {
    mostrado =
      mostrado.substring(
        0,
        19
      );
  }

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    55,
    114,
    52,
    7,
    COLOR_AZUL_OSC
  );

  textoCentrado(
    "COLOQUE",
    59,
    2,
    COLOR_CYAN
  );

  textoCentrado(
    "EL DEDO",
    79,
    2,
    COLOR_BLANCO
  );

  textoCentrado(
    mostrado,
    100,
    1,
    COLOR_GRIS
  );

  dibujarFooter();
}


void mostrarEnrolRetire() {

  if (
    pantallaActual ==
    PANTALLA_ENROL_RETIRE
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_ENROL_RETIRE;

  pantallaDetalle =
    "";

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    55,
    114,
    52,
    7,
    0x4208
  );

  textoCentrado(
    "RETIRE",
    59,
    2,
    COLOR_AMARILLO
  );

  textoCentrado(
    "EL DEDO",
    79,
    2,
    COLOR_BLANCO
  );

  textoCentrado(
    "Primera captura ok",
    100,
    1,
    COLOR_GRIS
  );

  dibujarFooter();
}


void mostrarEnrolRepita(
  String nombre
) {

  if (
    pantallaActual ==
      PANTALLA_ENROL_REPITA &&
    pantallaDetalle ==
      nombre
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_ENROL_REPITA;

  pantallaDetalle =
    nombre;

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    55,
    114,
    52,
    7,
    COLOR_AZUL_OSC
  );

  textoCentrado(
    "OTRA VEZ",
    59,
    2,
    COLOR_CYAN
  );

  textoCentrado(
    "EL MISMO DEDO",
    81,
    1,
    COLOR_BLANCO
  );

  textoCentrado(
    "Segunda captura",
    97,
    1,
    COLOR_GRIS
  );

  dibujarFooter();
}


void mostrarEnrolGuardando(
  int ranura
) {

  String detalle =
    String(ranura);

  if (
    pantallaActual ==
      PANTALLA_ENROL_GUARDANDO &&
    pantallaDetalle ==
      detalle
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_ENROL_GUARDANDO;

  pantallaDetalle =
    detalle;

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    10,
    57,
    108,
    45,
    7,
    COLOR_GRIS_OSC
  );

  textoCentrado(
    "GUARDANDO",
    65,
    1,
    COLOR_CYAN
  );

  textoCentrado(
    "RANURA " +
    String(ranura),
    82,
    2,
    COLOR_BLANCO
  );

  dibujarFooter();
}


void mostrarEnrolOk(
  String nombre,
  int ranura
) {

  String detalle =
    nombre +
    "|" +
    String(ranura);

  if (
    pantallaActual ==
      PANTALLA_ENROL_OK &&
    pantallaDetalle ==
      detalle
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_ENROL_OK;

  pantallaDetalle =
    detalle;

  nombre =
    normalizarTextoTFT(nombre);

  nombre.toUpperCase();

  if (
    nombre.length() > 19
  ) {
    nombre =
      nombre.substring(
        0,
        19
      );
  }

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    6,
    54,
    116,
    54,
    7,
    COLOR_VERDE_OSC
  );

  tft.drawCircle(
    19,
    67,
    9,
    COLOR_VERDE
  );

  tft.drawLine(
    14,
    67,
    18,
    72,
    COLOR_VERDE
  );

  tft.drawLine(
    18,
    72,
    25,
    61,
    COLOR_VERDE
  );

  tft.setTextSize(1);

  tft.setTextColor(
    COLOR_VERDE
  );

  tft.setCursor(
    34,
    58
  );

  tft.print(
    "HUELLA GUARDADA"
  );

  textoCentrado(
    "RANURA " +
    String(ranura),
    73,
    2,
    COLOR_BLANCO
  );

  textoCentrado(
    nombre,
    95,
    1,
    COLOR_BLANCO
  );

  dibujarFooter();
}


void mostrarEnrolFallo(
  String titulo,
  String detalle
) {

  String clave =
    titulo +
    "|" +
    detalle;

  if (
    pantallaActual ==
      PANTALLA_ENROL_FALLO &&
    pantallaDetalle ==
      clave
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_ENROL_FALLO;

  pantallaDetalle =
    clave;

  titulo =
    normalizarTextoTFT(
      titulo
    );

  detalle =
    normalizarTextoTFT(
      detalle
    );

  titulo.toUpperCase();

  if (
    titulo.length() > 18
  ) {
    titulo =
      titulo.substring(
        0,
        18
      );
  }

  if (
    detalle.length() > 19
  ) {
    detalle =
      detalle.substring(
        0,
        19
      );
  }

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    7,
    55,
    114,
    52,
    7,
    COLOR_ROJO_OSC
  );

  textoCentrado(
    "NO SE GRABO",
    60,
    1,
    COLOR_ROJO
  );

  textoCentrado(
    titulo,
    76,
    1,
    COLOR_BLANCO
  );

  textoCentrado(
    detalle,
    92,
    1,
    COLOR_GRIS
  );

  dibujarFooter();
}


void mostrarEnrolIndice() {

  if (
    pantallaActual ==
    PANTALLA_ENROL_INDICE
  ) {
    return;
  }

  pantallaActual =
    PANTALLA_ENROL_INDICE;

  pantallaDetalle =
    "";

  tft.fillScreen(
    COLOR_NEGRO
  );

  dibujarCabecera();

  tft.fillRoundRect(
    10,
    57,
    108,
    45,
    7,
    COLOR_GRIS_OSC
  );

  textoCentrado(
    "SINCRONIZANDO",
    66,
    1,
    COLOR_CYAN
  );

  textoCentrado(
    "SENSOR...",
    82,
    1,
    COLOR_BLANCO
  );

  dibujarFooter();
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
  if (
    finger.getImage() !=
    FINGERPRINT_NOFINGER
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

  delay(2500);
}


// =====================================================
// ESPERAR EL DEDO (ENROLAMIENTO)
//
// Devuelve FINGERPRINT_OK cuando hay imagen, FINGERPRINT_TIMEOUT si se agoto
// la espera, o el codigo de error del sensor si dejo de responder.
//
// Llama a mantenerWiFi() porque el loop esta bloqueado mientras se enrola y
// una espera de 20 s no puede dejar la reconexion sin atender. No repinta
// nada: mantenerWiFi solo toca el footer cuando la pantalla es LISTO.
// =====================================================

uint8_t esperarDedoEnrolamiento(
  unsigned long maximoMs
) {

  unsigned long inicio =
    millis();

  int erroresSeguidos =
    0;

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
      return FINGERPRINT_OK;
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

    if (
      p ==
      FINGERPRINT_NOFINGER
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

  uint8_t p =
    esperarDedoEnrolamiento(
      ENROL_TIMEOUT_DEDO_MS
    );

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

  uint8_t conv =
    finger.image2Tz(1);

  Serial.print(
    "image2Tz(1) = "
  );

  Serial.println(
    conv
  );

  if (
    conv !=
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "captura_defectuosa",
      "La primera imagen no sirvio (codigo " +
        String(conv) +
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
      ENROL_TIMEOUT_DEDO_MS
    );

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

  conv =
    finger.image2Tz(2);

  Serial.print(
    "image2Tz(2) = "
  );

  Serial.println(
    conv
  );

  if (
    conv !=
    FINGERPRINT_OK
  ) {

    fallarEnrolamiento(
      orden,
      "captura_defectuosa",
      "La segunda imagen no sirvio (codigo " +
        String(conv) +
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

  } else {

    // La plantilla ESTA grabada en el sensor; lo que fallo es avisar. El
    // reenvio queda pendiente y el endpoint es idempotente.
    mostrarEnrolFallo(
      "GRABADA SIN AVISO",
      "Reintentando..."
    );
  }

  delay(2500);

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
// UNICO punto de entrada desde el loop, y solo desde la rama NOFINGER. Todo lo
// que hay aca dentro puede tardar segundos, asi que nada de esto puede correr
// con un dedo apoyado ni entre el getImage() del loop y identificarHuella().
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
// SETUP
// =====================================================

void setup() {

  Serial.begin(
    115200
  );

  delay(
    400
  );

  // TFT
  SPI.begin(
    TFT_SCLK,
    -1,
    TFT_MOSI,
    TFT_CS
  );

  tft.initR(
    INITR_144GREENTAB
  );

  tft.setRotation(
    0
  );

  tft.setTextWrap(
    false
  );

  mostrarInicio();

  delay(
    800
  );

  // SENSOR
  FingerSerial.begin(
    57600,
    SERIAL_8N1,
    FP_RX,
    FP_TX
  );

  finger.begin(
    57600
  );

  delay(
    350
  );

  if (
    !finger.verifyPassword()
  ) {

    Serial.println(
      "ERROR SENSOR AS608"
    );

    mostrarError(
      "SENSOR",
      "Revise conexion"
    );

    while (true) {
      delay(1000);
    }
  }

  Serial.println(
    "SENSOR AS608 OK"
  );

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
  conectarWiFi();

  // PING REAL
  if (
    WiFi.status() ==
    WL_CONNECTED
  ) {

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
