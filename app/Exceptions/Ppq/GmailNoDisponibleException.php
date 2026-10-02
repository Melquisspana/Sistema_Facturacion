<?php

namespace App\Exceptions\Ppq;

/**
 * Gmail no respondió como se esperaba, pero NADA prueba que la autorización se
 * haya perdido: red caída, Google con un error 5xx, un 403 por cuota o un token
 * recién renovado que igual fue rechazado. La cuenta queda como estaba y la
 * próxima corrida reintenta sola.
 *
 * Extiende GmailDesconectadoException a propósito: todo llamador que ya degrada
 * a la búsqueda local ante una desconexión hace lo mismo acá, sin cambios. El
 * que necesite distinguir «reconectá la cuenta» de «reintentá» captura esta
 * primero.
 */
class GmailNoDisponibleException extends GmailDesconectadoException {}
