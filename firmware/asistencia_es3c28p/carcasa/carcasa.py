# Carcasa del lector de asistencia: LCDwiki ES3C28P (2.8") + AS608 + bocina.
#
# Script parametrico de FreeCAD. Genera dos piezas imprimibles y su STEP:
#
#   frente.stl  caja con ventana de pantalla, ventana del sensor, rejilla de la
#               bocina, ranura del USB-C y postes para la placa. Se imprime
#               boca abajo (la cara frontal sobre la cama), sin soportes.
#   tapa.stl    tapa trasera con dos ojales para colgarla en la pared. Plana.
#
# Uso (desde esta carpeta):
#
#   "C:\Program Files\FreeCAD 1.1\bin\FreeCADCmd.exe" carcasa.py
#
# Medidas de la placa tomadas del modelo 3D oficial del fabricante
# (ES3C28P_3D.step de lcdwiki.com). Las del sensor y la bocina son las del
# modelo comprado y conviene confirmarlas con un vernier antes de imprimir:
# estan todas en el bloque SENSOR / BOCINA.
#
# Ejes: X ancho (derecha +), Y profundidad (0 = cara frontal, crece hacia la
# pared), Z alto (0 = piso). Todo en mm.

import os
import FreeCAD as App
import Part
import Mesh
from FreeCAD import Vector as V

SALIDA = os.path.dirname(os.path.abspath(__file__))

# ------------------------------------------------------------------ GENERAL
PARED = 2.0          # paredes laterales, techo y piso
FRENTE = 2.0         # grosor de la cara frontal
TAPA = 2.4           # grosor de la tapa trasera
HOLGURA = 0.4        # juego entre piezas impresas y componentes
RADIO_CANTO = 3.0    # redondeo de las aristas verticales exteriores

# ------------------------------------------------------------------ PLACA (medidas del STEP oficial)
PCB_ANCHO = 50.0
PCB_ALTO = 86.0
PCB_GROSOR = 1.6
AGUJERO_BORDE = 4.0          # centro de los agujeros M3 al borde
VIDRIO_SOBRE_PCB = 4.3       # del frente del PCB a la cara del vidrio tactil
COMPONENTES_ATRAS = 6.3      # lo mas alto por detras del PCB (USB-C)
VIDRIO_DESDE_USB = 8.4       # borde del vidrio medido desde el borde del USB
VIDRIO_ALTO = 69.2
ACTIVA_ANCHO = 43.6
ACTIVA_DESDE_USB = 16.81     # inicio del area visible desde el borde del USB
ACTIVA_ALTO = 58.05
USB_ANCHO_RANURA = 13.0      # para la funda del conector del cable
USB_ALTO_RANURA = 7.5
USB_CENTRO_DETRAS_VIDRIO = 7.0  # centro del USB-C detras de la cara del vidrio

# ------------------------------------------------------------------ SENSOR AS608 (CONFIRMAR CON VERNIER)
SENSOR_ANCHO = 21.0      # cara frontal del cuerpo negro
SENSOR_ALTO = 21.0
SENSOR_FONDO = 33.0      # del vidrio a la placa trasera ZA620 incluida
SENSOR_CONECTOR = 8.0    # lo que sobresale el conector + curva del cable
VENTANA_ANCHO = 17.0     # hueco en el frente: un poco mas que el vidrio 16x18
VENTANA_ALTO = 19.0
FUNDA_LARGO = 16.0       # largo del tubo que abraza el cuerpo del sensor

# ------------------------------------------------------------------ BOCINA (CONFIRMAR CON VERNIER)
BOCINA_ANCHO = 40.0
BOCINA_FONDO = 28.0
BOCINA_ALTO = 8.0

# ------------------------------------------------------------------ TORNILLOS
PASO_M3 = 2.6        # agujero piloto para tornillo M3 autorroscante en PLA/PETG
PASANTE_M3 = 3.4
CABEZA_M3 = 6.2
POSTE_TAPA_D = 7.0
POSTE_TAPA_LARGO = 12.0

# ------------------------------------------------------------------ DERIVADAS
ANCHO_INT = PCB_ANCHO + 2 * 2.0
ANCHO = ANCHO_INT + 2 * PARED

# Fondo: lo que mande el sensor (con su cable) o la pila placa+componentes.
FONDO_INT = max(
    SENSOR_FONDO + SENSOR_CONECTOR,
    VIDRIO_SOBRE_PCB + PCB_GROSOR + COMPONENTES_ATRAS + 20.0,
)
FONDO = FRENTE + FONDO_INT          # la tapa va aparte, por detras

# Alto: placa arriba (USB contra el techo), sensor abajo, bocina en el piso
# por debajo del sensor.
Z_SENSOR_BASE = PARED + BOCINA_ALTO + 3.0
Z_SENSOR_TOPE = Z_SENSOR_BASE + SENSOR_ALTO
Z_PCB_BAJO = Z_SENSOR_TOPE + 5.0
Z_PCB_ALTO = Z_PCB_BAJO + PCB_ALTO
ALTO = Z_PCB_ALTO + 0.5 + PARED

# La placa va de cabeza (USB arriba): todo lo que el STEP mide desde el borde
# del USB aqui se mide hacia abajo desde Z_PCB_ALTO.
def z_desde_usb(d):
    return Z_PCB_ALTO - d

Y_VIDRIO = FRENTE                      # el vidrio apoya en la cara interna
Y_PCB_FRENTE = Y_VIDRIO + VIDRIO_SOBRE_PCB


def caja(x0, y0, z0, dx, dy, dz):
    return Part.makeBox(dx, dy, dz, V(x0, y0, z0))


def caja_redondeada(x0, y0, z0, dx, dy, dz, r):
    """Caja con las aristas paralelas a Y redondeadas (vista de frente)."""
    b = caja(x0, y0, z0, dx, dy, dz)
    aristas = [e for e in b.Edges
               if abs(e.Vertexes[0].Point.y - e.Vertexes[1].Point.y) > dy - 1e-6]
    return b.makeFillet(r, aristas) if r > 0 else b


def cilindro_y(x, y0, z, d, largo):
    return Part.makeCylinder(d / 2, largo, V(x, y0, z), V(0, 1, 0))


def cilindro_z(x, y, z0, d, alto):
    return Part.makeCylinder(d / 2, alto, V(x, y, z0), V(0, 0, 1))


def ventana_biselada(cx, cz, ancho, alto, bisel):
    """Hueco en la cara frontal, mas ancho por fuera (bisel de 45 grados)."""
    interior = caja(cx - ancho / 2, -1, cz - alto / 2, ancho, FRENTE + 2, alto)
    exterior = Part.makeLoft([
        Part.makePolygon([
            V(cx - ancho / 2 - bisel, -0.01, cz - alto / 2 - bisel),
            V(cx + ancho / 2 + bisel, -0.01, cz - alto / 2 - bisel),
            V(cx + ancho / 2 + bisel, -0.01, cz + alto / 2 + bisel),
            V(cx - ancho / 2 - bisel, -0.01, cz + alto / 2 + bisel),
            V(cx - ancho / 2 - bisel, -0.01, cz - alto / 2 - bisel)]),
        Part.makePolygon([
            V(cx - ancho / 2, bisel, cz - alto / 2),
            V(cx + ancho / 2, bisel, cz - alto / 2),
            V(cx + ancho / 2, bisel, cz + alto / 2),
            V(cx - ancho / 2, bisel, cz + alto / 2),
            V(cx - ancho / 2, bisel, cz - alto / 2)]),
    ], True)
    return interior.fuse(exterior)


# ================================================================== FRENTE
exterior = caja_redondeada(-ANCHO / 2, 0, 0, ANCHO, FONDO, ALTO, RADIO_CANTO)
hueco = caja_redondeada(-ANCHO_INT / 2, FRENTE, PARED,
                        ANCHO_INT, FONDO_INT + 1, ALTO - 2 * PARED,
                        max(RADIO_CANTO - PARED, 0.5))
frente = exterior.cut(hueco)

# --- postes de la placa: del frente interno a la cara delantera del PCB
x_ag = PCB_ANCHO / 2 - AGUJERO_BORDE
for dz in (AGUJERO_BORDE, PCB_ALTO - AGUJERO_BORDE):
    for sx in (-1, 1):
        z = z_desde_usb(dz)
        poste = cilindro_y(sx * x_ag, FRENTE - 0.01, z, 6.0, VIDRIO_SOBRE_PCB + 0.01)
        frente = frente.fuse(poste)
        frente = frente.cut(cilindro_y(sx * x_ag, FRENTE + 0.8, z, PASO_M3, 10))

# --- ventana de la pantalla (area visible + 0.8 por lado)
z_act_centro = z_desde_usb(ACTIVA_DESDE_USB + ACTIVA_ALTO / 2)
frente = frente.cut(ventana_biselada(0, z_act_centro,
                                     ACTIVA_ANCHO + 1.6, ACTIVA_ALTO + 1.6, 1.2))

# --- ventana y funda del sensor
z_sen = (Z_SENSOR_BASE + Z_SENSOR_TOPE) / 2
funda_ext = caja(-SENSOR_ANCHO / 2 - HOLGURA - 1.6, FRENTE - 0.01,
                 Z_SENSOR_BASE - HOLGURA - 1.6,
                 SENSOR_ANCHO + 2 * HOLGURA + 3.2, FUNDA_LARGO,
                 SENSOR_ALTO + 2 * HOLGURA + 3.2)
funda_int = caja(-SENSOR_ANCHO / 2 - HOLGURA, FRENTE,
                 Z_SENSOR_BASE - HOLGURA,
                 SENSOR_ANCHO + 2 * HOLGURA, FUNDA_LARGO + 1,
                 SENSOR_ALTO + 2 * HOLGURA)
frente = frente.fuse(funda_ext).cut(funda_int)
frente = frente.cut(ventana_biselada(0, z_sen, VENTANA_ANCHO, VENTANA_ALTO, 1.5))

# --- ranura del USB-C en el techo
y_usb = Y_VIDRIO + USB_CENTRO_DETRAS_VIDRIO
ranura = Part.makeBox(USB_ANCHO_RANURA - USB_ALTO_RANURA, USB_ALTO_RANURA, PARED + 2,
                      V(-(USB_ANCHO_RANURA - USB_ALTO_RANURA) / 2,
                        y_usb - USB_ALTO_RANURA / 2, ALTO - PARED - 1))
for sx in (-1, 1):
    ranura = ranura.fuse(cilindro_z(sx * (USB_ANCHO_RANURA - USB_ALTO_RANURA) / 2,
                                    y_usb, ALTO - PARED - 1, USB_ALTO_RANURA, PARED + 2))
frente = frente.cut(ranura)

# --- bocina: cerco en el piso y rejilla de ranuras
y_boc = FRENTE + (FONDO_INT - BOCINA_FONDO) / 2
cerco = caja(-BOCINA_ANCHO / 2 - HOLGURA - 1.2, y_boc - HOLGURA - 1.2, PARED - 0.01,
             BOCINA_ANCHO + 2 * HOLGURA + 2.4, BOCINA_FONDO + 2 * HOLGURA + 2.4, 3.0)
cerco = cerco.cut(caja(-BOCINA_ANCHO / 2 - HOLGURA, y_boc - HOLGURA, PARED - 1,
                       BOCINA_ANCHO + 2 * HOLGURA, BOCINA_FONDO + 2 * HOLGURA, 5))
frente = frente.fuse(cerco)
n_ranuras = 7
paso = (BOCINA_FONDO - 8) / (n_ranuras - 1)
for i in range(n_ranuras):
    y = y_boc + 4 + i * paso
    largo = BOCINA_ANCHO - 12
    r = Part.makeBox(largo - 2, 2.0, PARED + 2, V(-largo / 2 + 1, y - 1.0, -1))
    r = r.fuse(cilindro_z(-largo / 2 + 1, y, -1, 2.0, PARED + 2))
    r = r.fuse(cilindro_z(largo / 2 - 1, y, -1, 2.0, PARED + 2))
    frente = frente.cut(r)

# --- postes para los tornillos de la tapa (pegados a las paredes laterales)
x_pt = ANCHO_INT / 2 - POSTE_TAPA_D / 2 + 1.0
z_postes = (Z_SENSOR_BASE + 4.0, ALTO - PARED - POSTE_TAPA_D / 2 - 6.0)
for z in z_postes:
    for sx in (-1, 1):
        y0 = FONDO - POSTE_TAPA_LARGO
        poste = cilindro_y(sx * x_pt, y0, z, POSTE_TAPA_D, POSTE_TAPA_LARGO)
        # Refuerzo hasta la pared, y debajo una cuna a 45 grados: la caja se
        # imprime boca abajo y sin ella el poste arrancaria en el aire.
        pared_x = sx * (ANCHO_INT / 2 + 0.5)
        lejos_x = sx * (x_pt - POSTE_TAPA_D / 2)
        refuerzo = caja(min(pared_x, sx * x_pt), y0, z - POSTE_TAPA_D / 2,
                        abs(pared_x - sx * x_pt), POSTE_TAPA_LARGO, POSTE_TAPA_D)
        vuelo = abs(pared_x - lejos_x)
        cuna = Part.Face(Part.makePolygon([
            V(pared_x, y0 - vuelo, z - POSTE_TAPA_D / 2),
            V(pared_x, y0 + 0.01, z - POSTE_TAPA_D / 2),
            V(lejos_x, y0 + 0.01, z - POSTE_TAPA_D / 2),
            V(pared_x, y0 - vuelo, z - POSTE_TAPA_D / 2)])).extrude(V(0, 0, POSTE_TAPA_D))
        frente = frente.fuse(poste.fuse(refuerzo).fuse(cuna).common(hueco))
        frente = frente.cut(cilindro_y(sx * x_pt, y0 - 1, z, PASO_M3, POSTE_TAPA_LARGO + 2))

frente = frente.removeSplitter()

# ================================================================== TAPA
tapa = caja_redondeada(-ANCHO / 2, FONDO, 0, ANCHO, TAPA, ALTO, RADIO_CANTO)
# Pestana de centrado que entra en el hueco de la caja.
pestana = caja_redondeada(-ANCHO_INT / 2 + HOLGURA, FONDO - 1.5, PARED + HOLGURA,
                          ANCHO_INT - 2 * HOLGURA, 1.5, ALTO - 2 * PARED - 2 * HOLGURA,
                          max(RADIO_CANTO - PARED, 0.5))
pestana = pestana.cut(caja_redondeada(-ANCHO_INT / 2 + HOLGURA + 1.6, FONDO - 2,
                                      PARED + HOLGURA + 1.6,
                                      ANCHO_INT - 2 * HOLGURA - 3.2, 3,
                                      ALTO - 2 * PARED - 2 * HOLGURA - 3.2, 0.5))
# Cortes en la pestana donde estan los postes.
for z in z_postes:
    for sx in (-1, 1):
        pestana = pestana.cut(cilindro_y(sx * x_pt, FONDO - 3, z, POSTE_TAPA_D + 1.5, 4))
tapa = tapa.fuse(pestana)

for z in z_postes:
    for sx in (-1, 1):
        tapa = tapa.cut(cilindro_y(sx * x_pt, FONDO - 3, z, PASANTE_M3, TAPA + 6))
        tapa = tapa.cut(cilindro_y(sx * x_pt, FONDO + TAPA - 1.6, z, CABEZA_M3, 2))

# Ojales para colgar (tornillo de cabeza <= 8 mm), detras de la placa.
for z in (Z_PCB_ALTO - 22, Z_PCB_BAJO + 22):
    tapa = tapa.cut(cilindro_y(0, FONDO - 3, z, 8.5, TAPA + 6))
    tapa = tapa.cut(caja(-2.1, FONDO - 3, z, 4.2, TAPA + 6, 9))
    tapa = tapa.cut(cilindro_y(0, FONDO - 3, z + 9, 4.2, TAPA + 6))

tapa = tapa.removeSplitter()

# ================================================================== SALIDA
for nombre, forma in (("frente", frente), ("tapa", tapa)):
    assert forma.isValid(), nombre + " no es un solido valido"
    malla = Mesh.Mesh()
    malla.addFacets(forma.tessellate(0.05))
    malla.write(os.path.join(SALIDA, nombre + ".stl"))
    forma.exportStep(os.path.join(SALIDA, nombre + ".step"))
    b = forma.BoundBox
    print("%-7s %.1f x %.1f x %.1f mm  vol %.1f cm3" % (
        nombre, b.XLength, b.ZLength, b.YLength, forma.Volume / 1000))

print("Caja cerrada: %.1f ancho x %.1f alto x %.1f fondo mm" % (ANCHO, ALTO, FONDO + TAPA))
