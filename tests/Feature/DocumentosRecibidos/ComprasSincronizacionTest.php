<?php

namespace Tests\Feature\DocumentosRecibidos;

use App\Exceptions\DocumentosRecibidos\AutenticacionBuzonException;
use App\Exceptions\DocumentosRecibidos\BuzonInaccesibleException;
use App\Models\DocumentoRecibido;
use App\Models\DocumentoRecibidoProgreso;
use App\Services\DocumentosRecibidos\Buzon\IdentidadCorreo;
use App\Services\DocumentosRecibidos\ProgresoSincronizacionCompras;
use App\Services\DocumentosRecibidos\ResumenSincronizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuzonFalso;
use Tests\Support\SincronizaCompras;
use Tests\TestCase;

/**
 * El recorrido del buzón de compras: paginación por día, progreso, reanudación,
 * identidad estable y errores visibles.
 *
 * Cada prueba de acá corresponde a una forma concreta en que el sistema anterior perdía
 * correos o mentía sobre lo que había hecho. Nada toca un buzón real: el {@see BuzonFalso}
 * replica la semántica de IMAP (ventana por día, UID ascendente, cursor, truncado).
 */
class ComprasSincronizacionTest extends TestCase
{
    use RefreshDatabase;
    use SincronizaCompras;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function progreso(): ProgresoSincronizacionCompras
    {
        return app(ProgresoSincronizacionCompras::class);
    }

    private function filaDe(string $dia): ?DocumentoRecibidoProgreso
    {
        return DocumentoRecibidoProgreso::where('dia', $dia)->where('carpeta', 'INBOX')->first();
    }

    // ---------------------------------------------------------------- paginación

    /**
     * EL FALLO ORIGINAL. Con más correos que el límite en un mismo día, el lector viejo
     * se quedaba con los de UID más alto y la marca avanzaba igual: los de abajo del
     * corte no se leían nunca. Ahora el día se agota paginando.
     */
    public function test_mas_mensajes_que_el_limite_en_un_dia_se_leen_todos(): void
    {
        $buzon = new BuzonFalso;
        for ($i = 1; $i <= 7; $i++) {
            $buzon->conDte(1000 + $i, '2026-08-05 0'.$i.':00:00', 'COD-'.$i);
        }
        $this->instalarBuzon($buzon);

        $r = $this->sincronizar('2026-08-05', limite: 2);

        $this->assertSame(7, $r->nuevos, 'los 7 correos del día tienen que entrar, no solo los 2 de la primera página');
        $this->assertSame(7, DocumentoRecibido::count());
        $this->assertSame(ResumenSincronizacion::COMPLETA, $r->desenlace);
        $this->assertTrue($this->filaDe('2026-08-05')->estaCompleto());
    }

    /** Varias páginas y varios días en la misma corrida, sin perder ninguno. */
    public function test_varios_dias_y_varias_paginas(): void
    {
        $buzon = new BuzonFalso;
        $uid = 2000;
        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $dia) {
            for ($i = 1; $i <= 5; $i++) {
                $buzon->conDte(++$uid, $dia.' 0'.$i.':00:00', 'C-'.$dia.'-'.$i);
            }
        }
        $this->instalarBuzon($buzon);

        $r = $this->sincronizar('2026-08-01', '2026-08-03', limite: 2);

        $this->assertSame(15, $r->nuevos);
        $this->assertCount(3, $r->diasCompletos);
        $this->assertSame([], $r->diasIncompletos);
        $this->assertSame(15, DocumentoRecibido::count());
    }

    /**
     * El cursor avanza también con los correos SIN adjuntos útiles. Si solo avanzara con
     * los que sirven, un bloque de correos sin adjunto haría que la página siguiente los
     * volviera a mirar para siempre.
     */
    public function test_el_cursor_avanza_aunque_el_correo_no_traiga_adjuntos(): void
    {
        $buzon = (new BuzonFalso)
            ->conMensaje(['uid' => 10, 'message_id' => '<vacio@x>', 'asunto' => 'Boletín',
                'remitente' => 'x@x.com', 'fecha' => '2026-08-05 01:00:00', 'adjuntos' => []])
            ->conDte(11, '2026-08-05 02:00:00', 'COD-A');
        $this->instalarBuzon($buzon);

        $r = $this->sincronizar('2026-08-05', limite: 1);

        $this->assertSame(1, $r->nuevos);
        $this->assertTrue($this->filaDe('2026-08-05')->estaCompleto());
    }

    // ---------------------------------------------------------------- idempotencia

    public function test_una_segunda_corrida_del_mismo_rango_no_duplica_nada(): void
    {
        $buzon = (new BuzonFalso)
            ->conDte(3001, '2026-08-10 09:00:00', 'COD-X')
            ->conDte(3002, '2026-08-10 10:00:00', 'COD-Y');
        $this->instalarBuzon($buzon);

        $primera = $this->sincronizar('2026-08-10');
        $segunda = $this->sincronizar('2026-08-10');

        $this->assertSame(2, $primera->nuevos);
        $this->assertSame(0, $segunda->nuevos);
        $this->assertSame(ResumenSincronizacion::SIN_NOVEDADES, $segunda->desenlace);
        $this->assertSame(2, DocumentoRecibido::count());
    }

    /**
     * Un correo que llega con retraso, fechado un día ya cubierto, entra cuando el solape
     * vuelve a pasar por ese día. Antes el `SINCE` arrancaba después y no volvía nunca.
     */
    public function test_un_correo_atrasado_entra_al_releer_el_dia_con_solape(): void
    {
        $buzon = (new BuzonFalso)->conDte(4001, '2026-08-12 09:00:00', 'COD-1');
        $this->instalarBuzon($buzon);
        $this->sincronizar('2026-08-12');
        $this->assertSame(1, DocumentoRecibido::count());

        // El proveedor manda un CCF con fecha del 12 cuando ese día ya estaba cubierto.
        $buzon->conDte(4009, '2026-08-12 23:00:00', 'COD-TARDE');

        $r = $this->sincronizar('2026-08-12');

        $this->assertSame(1, $r->nuevos, 'el solape tiene que recoger el correo atrasado');
        $this->assertSame(2, DocumentoRecibido::count());
    }

    // ---------------------------------------------------------------- identidad

    /**
     * Mover un correo de carpeta le cambia el UID. Con el UID como identidad, el mismo
     * CCF se registraba dos veces; con el `Message-ID` se reconoce y solo se actualiza
     * dónde está.
     */
    public function test_el_mismo_correo_en_otra_carpeta_con_otro_uid_no_se_duplica(): void
    {
        $buzon = (new BuzonFalso)->conDte(5001, '2026-08-15 09:00:00', 'COD-MOVIDO');
        $this->instalarBuzon($buzon);
        $this->sincronizar('2026-08-15');

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertSame('mid:cod-movido@proveedor.example', $doc->identidad);
        $this->assertSame(5001, $doc->uid);
        $this->assertSame('INBOX', $doc->buzon_carpeta);

        // Alguien lo archiva: otra carpeta, otro UID, mismo Message-ID.
        $buzon->moverA('Archivo');
        $r = $this->sincronizar('2026-08-15');

        $this->assertSame(0, $r->nuevos);
        $this->assertSame(1, $r->duplicados);
        $this->assertSame(1, DocumentoRecibido::count());

        // La ubicación se refresca (diagnóstico); la identidad no cambia.
        $doc->refresh();
        $this->assertSame('mid:cod-movido@proveedor.example', $doc->identidad);
        $this->assertSame(15001, $doc->uid);
        $this->assertSame('Archivo', $doc->buzon_carpeta);
    }

    /**
     * Sin `Message-ID` la identidad se reconstruye con un hash determinista de lo que sí
     * viaja con el correo. El mismo correo, leído dos veces, produce el mismo hash.
     */
    public function test_un_correo_sin_message_id_usa_una_identidad_determinista(): void
    {
        $sinId = [
            'uid' => 6001,
            'message_id' => null,
            'asunto' => 'CCF sin encabezado',
            'remitente' => 'raro@proveedor.example',
            'fecha' => '2026-08-18 09:00:00',
            'adjuntos' => [[
                'filename' => 'dte.json',
                'mime' => 'application/json',
                'data' => (string) json_encode([
                    'identificacion' => ['tipoDte' => '03', 'numeroControl' => 'DTE-03-P-9', 'codigoGeneracion' => 'COD-SINID', 'fecEmi' => '2026-08-18'],
                    'emisor' => ['nombre' => 'PROVEEDOR RARO', 'nit' => '0614', 'nrc' => '999'],
                    'resumen' => ['totalPagar' => 50.0],
                ]),
            ]],
        ];

        $buzon = (new BuzonFalso)->conMensaje($sinId);
        $this->instalarBuzon($buzon);
        $this->sincronizar('2026-08-18');

        $doc = DocumentoRecibido::firstOrFail();
        $this->assertStringStartsWith(IdentidadCorreo::PREFIJO_HASH, (string) $doc->identidad);
        $this->assertNull($doc->message_id);

        // Releerlo —incluso con otro UID, como tras moverlo— no lo duplica.
        $buzon->moverA('Archivo');
        $r = $this->sincronizar('2026-08-18');

        $this->assertSame(0, $r->nuevos);
        $this->assertSame(1, DocumentoRecibido::count());
    }

    /** Las filas anteriores a la migración se reconocen por su `gmail_message_id`. */
    public function test_no_reimporta_las_filas_historicas_sin_identidad(): void
    {
        // Fila "vieja": identidad NULL y el UID crudo en gmail_message_id, como quedaron
        // las 50 que ya existen en producción.
        DocumentoRecibido::create([
            'gmail_message_id' => '7001',
            'emisor_nombre' => 'PROVEEDOR VIEJO',
            'tipo_documento' => '03',
            'estado' => 'pendiente',
            'tiene_pdf' => true,
            'tiene_json' => true,
            'fecha_correo' => Carbon::parse('2026-08-20 09:00:00'),
        ]);

        $buzon = (new BuzonFalso)->conDte(7001, '2026-08-20 09:00:00', 'COD-VIEJO');
        $this->instalarBuzon($buzon);

        $r = $this->sincronizar('2026-08-20');

        $this->assertSame(0, $r->nuevos, 'la fila histórica se reconoce por el UID que guardaba');
        $this->assertSame(1, $r->duplicados);
        $this->assertSame(1, DocumentoRecibido::count());
    }

    // ---------------------------------------------------------------- progreso

    /**
     * LA PIEZA QUE EVITA PERDER CORREOS. Si el día quedó truncado, no se declara
     * completo; la marca no lo pasa y la corrida siguiente vuelve a él.
     */
    public function test_un_dia_truncado_no_avanza_la_marca(): void
    {
        // Buzón que nunca da el día por agotado: cada página dice que quedan más.
        $this->instalarBuzon(
            (new BuzonFalso)->conDte(8001, '2026-08-22 01:00:00', 'T-1')->siempreTruncado()
        );

        $r = $this->sincronizar('2026-08-22', limite: 1);

        $this->assertSame(ResumenSincronizacion::INCOMPLETA, $r->desenlace);
        $this->assertSame(['2026-08-22'], $r->diasIncompletos);
        $this->assertSame([], $r->diasCompletos);

        $fila = $this->filaDe('2026-08-22');
        $this->assertNotNull($fila);
        $this->assertSame(DocumentoRecibidoProgreso::ESTADO_PARCIAL, $fila->estado);
        $this->assertFalse($fila->estaCompleto(), 'un día que no se pudo agotar NO puede quedar como completo');

        // Y lo que importa de verdad: la marca no lo pasa, así que la corrida siguiente
        // vuelve a este día en vez de darlo por cubierto.
        $this->assertNull($this->progreso()->ultimoDiaCompletoContiguo('INBOX'));
        $this->assertSame(
            ['2026-08-22'],
            $this->progreso()->diasSinCubrir(Carbon::parse('2026-08-22'), Carbon::parse('2026-08-22'), 'INBOX')
                ->pluck('dia')->all(),
        );
    }

    /**
     * Una corrida que muere a mitad deja el cursor, y la siguiente continúa desde ahí:
     * ni repite lo leído ni saltea lo que faltaba.
     */
    public function test_falla_a_mitad_y_reanuda_sin_repetir_ni_saltear(): void
    {
        $mensajes = [];
        for ($i = 1; $i <= 6; $i++) {
            $mensajes[] = [9000 + $i, '2026-08-25 0'.$i.':00:00', 'R-'.$i];
        }

        // Primera corrida: lee 2 páginas de 2 y se cae.
        $roto = new BuzonFalso;
        foreach ($mensajes as [$uid, $fecha, $cod]) {
            $roto->conDte($uid, $fecha, $cod);
        }
        $roto->queFallaDespuesDe(2, new BuzonInaccesibleException('el servidor cortó la conexión'));
        $this->instalarBuzon($roto);

        $primera = $this->sincronizar('2026-08-25', limite: 2);

        $this->assertSame(ResumenSincronizacion::BUZON_INACCESIBLE, $primera->desenlace);
        $this->assertSame(4, DocumentoRecibido::count(), 'lo procesado antes del corte queda guardado');
        $fila = $this->filaDe('2026-08-25');
        $this->assertSame(DocumentoRecibidoProgreso::ESTADO_ERROR, $fila->estado);
        $this->assertSame(9004, $fila->ultimo_uid, 'el cursor marca hasta dónde llegó');

        // Segunda corrida: el buzón vuelve. Retoma desde el cursor.
        $sano = new BuzonFalso;
        foreach ($mensajes as [$uid, $fecha, $cod]) {
            $sano->conDte($uid, $fecha, $cod);
        }
        $this->instalarBuzon($sano);

        $segunda = $this->sincronizar('2026-08-25', limite: 2);

        $this->assertSame(2, $segunda->nuevos, 'solo faltaban dos');
        $this->assertSame(2, $segunda->correos, 'no relee los cuatro que ya había procesado');
        $this->assertSame(6, DocumentoRecibido::count());
        $this->assertTrue($this->filaDe('2026-08-25')->estaCompleto());

        // Sin duplicados: seis correos, seis identidades distintas.
        $this->assertCount(6, DocumentoRecibido::pluck('identidad')->unique());
        $this->assertSame(9006, $this->filaDe('2026-08-25')->ultimo_uid, 'el cursor terminó en el último UID del día');
    }

    // ---------------------------------------------------------------- errores visibles

    /** Un buzón inaccesible NO es una corrida sin novedades. */
    public function test_un_error_de_imap_no_se_reporta_como_exito(): void
    {
        $this->instalarBuzon((new BuzonFalso)->queFalla(new BuzonInaccesibleException('Connection refused')));

        $r = $this->sincronizar('2026-08-28');

        $this->assertSame(ResumenSincronizacion::BUZON_INACCESIBLE, $r->desenlace);
        $this->assertTrue($r->fallo());
        $this->assertFalse($r->exitosa());
        $this->assertStringContainsString('Connection refused', (string) $r->error);
        $this->assertStringNotContainsString('Sin novedades', $r->mensaje());
    }

    /** Credenciales rechazadas se distinguen de "no llegué al servidor". */
    public function test_la_autenticacion_fallida_se_distingue_del_buzon_inaccesible(): void
    {
        $this->instalarBuzon((new BuzonFalso)->queFalla(new AutenticacionBuzonException('AUTHENTICATIONFAILED')));

        $r = $this->sincronizar('2026-08-28');

        $this->assertSame(ResumenSincronizacion::AUTENTICACION_FALLIDA, $r->desenlace);
        $this->assertTrue($r->fallo());
    }

    /** Sin buzón configurado, el desenlace lo dice; no simula una corrida vacía. */
    public function test_sin_configurar_lo_dice(): void
    {
        $this->instalarBuzon(new BuzonFalso(disponible: false));

        $r = $this->sincronizar('2026-08-28');

        $this->assertSame(ResumenSincronizacion::SIN_CONFIGURAR, $r->desenlace);
    }

    /** Un día sin correos es "sin novedades", y eso SÍ deja el día como cubierto. */
    public function test_un_dia_sin_correos_queda_cubierto_y_sin_novedades(): void
    {
        $this->instalarBuzon(new BuzonFalso);

        $r = $this->sincronizar('2026-08-29');

        $this->assertSame(ResumenSincronizacion::SIN_NOVEDADES, $r->desenlace);
        $this->assertTrue($r->exitosa());
        $this->assertTrue($this->filaDe('2026-08-29')->estaCompleto());
    }

    // ---------------------------------------------------------------- UIDVALIDITY

    /**
     * Si la carpeta se reconstruyó, los cursores guardados apuntan a otros correos.
     * Seguir usándolos saltearía documentos reales, así que la corrida se detiene.
     */
    public function test_un_cambio_de_uid_validity_detiene_la_corrida(): void
    {
        $buzon = (new BuzonFalso(uidValidity: 5001))->conDte(1, '2026-08-30 09:00:00', 'COD-UV');
        $this->instalarBuzon($buzon);
        $this->sincronizar('2026-08-30');

        // El servidor reconstruye la carpeta.
        $buzon->conUidValidity(9999);
        $r = $this->sincronizar('2026-08-30');

        $this->assertSame(ResumenSincronizacion::UID_VALIDITY_CAMBIADO, $r->desenlace);
        $this->assertTrue($r->fallo());
        $this->assertStringContainsString('5001', (string) $r->error);
        $this->assertStringContainsString('9999', (string) $r->error);
    }

    /** Soltar los cursores permite volver a recorrer, y releer no duplica. */
    public function test_reiniciar_el_uid_validity_permite_recorrer_de_nuevo_sin_duplicar(): void
    {
        $buzon = (new BuzonFalso(uidValidity: 5001))->conDte(1, '2026-08-30 09:00:00', 'COD-UV');
        $this->instalarBuzon($buzon);
        $this->sincronizar('2026-08-30');
        $this->assertSame(1, DocumentoRecibido::count());

        $buzon->conUidValidity(9999)->moverA('INBOX', desplazamientoUid: 500);
        $this->progreso()->reiniciarPorUidValidity('INBOX', 9999);

        $r = $this->sincronizar('2026-08-30');

        $this->assertFalse($r->fallo());
        $this->assertSame(0, $r->nuevos, 'la identidad por Message-ID impide el duplicado');
        $this->assertSame(1, DocumentoRecibido::count());
    }

    // ---------------------------------------------------------------- dry-run

    public function test_sin_aplicar_no_escribe_nada(): void
    {
        $this->instalarBuzon((new BuzonFalso)->conDte(1, '2026-08-31 09:00:00', 'COD-DRY'));

        $r = $this->sincronizar('2026-08-31', aplicar: false);

        $this->assertSame(1, $r->nuevos, 'el informe dice lo que haría');
        $this->assertSame(0, DocumentoRecibido::count(), 'pero no guarda el documento');
        $this->assertNull($this->filaDe('2026-08-31'), 'ni el progreso');
    }
}
