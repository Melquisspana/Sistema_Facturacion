"""Convierte el logo de la empresa en mapas de bits RGB565 para la pantalla.

Uso (desde la raiz del proyecto):

    python firmware/asistencia_es3c28p/herramientas/generar_logo.py

Lee public/images/dte/logo-transparent.png y escribe
firmware/asistencia_es3c28p/logo.h con dos tamanos, ya mezclados sobre el
color crema de la cabecera (la pantalla no tiene transparencia).
"""

from pathlib import Path

from PIL import Image

RAIZ = Path(__file__).resolve().parents[3]
ORIGEN = RAIZ / "public/images/dte/logo-transparent.png"
DESTINO = RAIZ / "firmware/asistencia_es3c28p/logo.h"

# Debe coincidir con COLOR_CREMA del firmware (RGB888 -> RGB565).
FONDO = (0xFF, 0xF4, 0xE0)

TAMANOS = {
    "LOGO_CHICO": 70,    # cabecera de todas las pantallas
    "LOGO_GRANDE": 170,  # pantalla de arranque
}


def rgb565(r, g, b):
    return ((r & 0xF8) << 8) | ((g & 0xFC) << 3) | (b >> 3)


def recortar(img):
    """Quita el margen transparente para que el alto sea todo logo."""
    caja = img.getchannel("A").point(lambda a: 255 if a > 16 else 0).getbbox()
    return img.crop(caja)


def convertir(img, alto):
    ancho = round(img.width * alto / img.height)
    chica = img.resize((ancho, alto), Image.LANCZOS)
    fondo = Image.new("RGBA", chica.size, FONDO + (255,))
    plana = Image.alpha_composite(fondo, chica).convert("RGB")
    return ancho, [rgb565(*plana.getpixel((x, y))) for y in range(alto) for x in range(ancho)]


def main():
    img = recortar(Image.open(ORIGEN).convert("RGBA"))
    partes = [
        "// Generado por herramientas/generar_logo.py. No editar a mano.",
        "#pragma once",
        "#include <stdint.h>",
        "",
    ]
    for nombre, alto in TAMANOS.items():
        ancho, px = convertir(img, alto)
        partes.append(f"const uint16_t {nombre}_ANCHO = {ancho};")
        partes.append(f"const uint16_t {nombre}_ALTO = {alto};")
        partes.append(f"const uint16_t {nombre}[] PROGMEM = {{")
        for i in range(0, len(px), 16):
            partes.append("  " + ", ".join(f"0x{v:04X}" for v in px[i:i + 16]) + ",")
        partes.append("};")
        partes.append("")
    DESTINO.write_text("\n".join(partes), encoding="ascii")
    print("escrito", DESTINO)


if __name__ == "__main__":
    main()
