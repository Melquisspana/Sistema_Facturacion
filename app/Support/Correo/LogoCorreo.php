<?php

namespace App\Support\Correo;

use Illuminate\Mail\Message;

/**
 * El logo de la empresa dentro del correo (`x-mail::documento`), como un sello: el dibujo en
 * un círculo blanco con un filo del rojo de la marca, 56 px en pantalla (168 px, para que
 * se vea nítido en el celular).
 *
 * Por qué (Melqui, 25/11/2026, «el logo no sale bien»): el logo es un dibujo negro sobre
 * fondo transparente. En el modo oscuro de Gmail el fondo del correo se oscurece y el pelo
 * negro desaparecía. Con su propio círculo blanco se ve igual en claro y en oscuro.
 *
 * Va incrustado en el mensaje (cid:), nunca pedido por una dirección: el sistema está detrás
 * del acceso de la empresa y los correos no muestran imágenes en data-URI. Sale del mismo
 * archivo que los PDF (`dte.pdf.logo_path`). Sin archivo, o si GD no lo puede leer, el correo
 * sale solo con el nombre: un SVG no se ve en Gmail ni en Outlook de escritorio, y el archivo
 * tal cual iría sin su círculo blanco y estirado a 56×56.
 */
final class LogoCorreo
{
    /** Lado de la imagen: 3 veces los 56 px con que se muestra. */
    public const LADO = 168;

    /** El rojo del logo, el acento del correo (themes/documento.css). */
    private const ROJO = [0xC2, 0x3B, 0x26];

    /** Incrusta el sello del logo en el mensaje y devuelve su `cid:`, o null si no hay logo que mostrar. */
    public static function incrustar(Message $message): ?string
    {
        $ruta = (string) config('dte.pdf.logo_path', '');
        if ($ruta === '' || ! is_file($ruta)) {
            return null;
        }
        $sello = self::sello((string) file_get_contents($ruta));

        return $sello !== null ? $message->embedData($sello, 'logo.png', 'image/png') : null;
    }

    /** El PNG del sello, o null si GD no puede leer la imagen. */
    public static function sello(string $imagen): ?string
    {
        $logo = function_exists('imagecreatefromstring') ? @imagecreatefromstring($imagen) : false;
        if ($logo === false) {
            return null;
        }
        imagepalettetotruecolor($logo);

        // Se dibuja a 4 veces el tamaño y se achica: así el borde del círculo sale suave.
        $grande = self::LADO * 4;
        $lienzo = self::transparente($grande);
        imagealphablending($lienzo, true);
        $centro = intdiv($grande, 2);
        imagefilledellipse($lienzo, $centro, $centro, $grande, $grande, imagecolorallocate($lienzo, ...self::ROJO));
        $filo = 12;
        imagefilledellipse($lienzo, $centro, $centro, $grande - 2 * $filo, $grande - 2 * $filo, imagecolorallocate($lienzo, 255, 255, 255));

        // El dibujo entero dentro del círculo: la diagonal ocupa el 88 % del diámetro blanco.
        [$ancho, $alto] = [imagesx($logo), imagesy($logo)];
        $escala = 0.88 * ($grande - 2 * $filo) / sqrt($ancho ** 2 + $alto ** 2);
        [$w, $h] = [(int) round($ancho * $escala), (int) round($alto * $escala)];
        imagecopyresampled($lienzo, $logo, intdiv($grande - $w, 2), intdiv($grande - $h, 2), 0, 0, $w, $h, $ancho, $alto);

        $sello = self::transparente(self::LADO);
        imagecopyresampled($sello, $lienzo, 0, 0, 0, 0, self::LADO, self::LADO, $grande, $grande);

        ob_start();
        imagepng($sello, null, 9);

        return (string) ob_get_clean();
    }

    /** Un cuadrado transparente que guarda la transparencia al copiar y al guardar. */
    private static function transparente(int $lado): \GdImage
    {
        $imagen = imagecreatetruecolor($lado, $lado);
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);
        imagefill($imagen, 0, 0, imagecolorallocatealpha($imagen, 0, 0, 0, 127));

        return $imagen;
    }
}
