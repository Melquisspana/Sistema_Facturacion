<?php

namespace App\Models\Cobros;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hasta dónde se ha barrido el buzón para una consulta. Ver la migración para el porqué.
 *
 * Igual que en Compras: **solo `barrido_completo` significa cubierto**. Su ausencia no
 * quiere decir «no hay nada más»; quiere decir «todavía no lo sé», y esas dos cosas no
 * pueden mostrarse igual en pantalla.
 */
class CobroCorreoProgreso extends Model
{
    use HasFactory;

    protected $table = 'cobro_correo_progresos';

    protected $fillable = [
        'cliente_id',
        'consulta',
        'consulta_hash',
        'ultimo_mensaje_en',
        'barrido_hasta',
        'barrido_completo',
        'ultima_corrida_en',
        'mensajes_leidos',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'barrido_completo' => false,
        'mensajes_leidos' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ultimo_mensaje_en' => 'datetime',
            'barrido_hasta' => 'datetime',
            'barrido_completo' => 'boolean',
            'ultima_corrida_en' => 'datetime',
            'mensajes_leidos' => 'integer',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * El progreso de esta consulta para este cliente, creándolo si es la primera vez.
     *
     * La consulta forma parte de la identidad: cambiarla cambia el universo barrido, y
     * heredar el progreso de otra búsqueda daría por cubierto lo que nunca se miró.
     */
    public static function para(Cliente $cliente, string $consulta): self
    {
        return self::firstOrCreate(
            ['cliente_id' => $cliente->id, 'consulta_hash' => self::huella($consulta)],
            ['consulta' => $consulta],
        );
    }

    /** El progreso SIN crearlo. Para leer estado sin escribir (ensayo en seco). */
    public static function existentePara(Cliente $cliente, string $consulta): ?self
    {
        return self::where('cliente_id', $cliente->id)
            ->where('consulta_hash', self::huella($consulta))
            ->first();
    }

    public static function huella(string $consulta): string
    {
        return hash('sha256', trim($consulta));
    }

    /**
     * ¿Queda buzón por recorrer hacia atrás?
     *
     * Es la pregunta que responde la pantalla: mientras esto sea true, que un mensaje no
     * esté registrado NO significa que no exista, solo que el barrido no llegó todavía.
     */
    public function quedaBacklog(): bool
    {
        return ! $this->barrido_completo;
    }

    /** Frase para el operador: dónde va el barrido y si falta. */
    public function resumen(): string
    {
        if ($this->barrido_completo) {
            return 'Buzón recorrido entero. Las corridas siguientes solo miran lo nuevo.';
        }

        if ($this->barrido_hasta === null) {
            return 'El barrido del buzón todavía no ha empezado: quedan mensajes por leer.';
        }

        return 'Quedan mensajes anteriores al '.$this->barrido_hasta->format('d/m/Y H:i')
            .' por recorrer. Cada corrida avanza una tanda hacia atrás.';
    }

    /** Marca el avance de la CABEZA: el mensaje más nuevo ya visto. */
    public function avanzarCabeza(?Carbon $masNuevo): void
    {
        if ($masNuevo === null) {
            return;
        }

        if ($this->ultimo_mensaje_en === null || $masNuevo->gt($this->ultimo_mensaje_en)) {
            $this->ultimo_mensaje_en = $masNuevo;
        }
    }

    /**
     * Marca el avance de la COLA: el mensaje más viejo ya visto.
     *
     * Solo retrocede, nunca avanza hacia adelante: si una corrida devuelve algo más nuevo
     * que la marca —por el solape de seguridad—, la marca se queda donde estaba. Moverla
     * hacia adelante volvería a dejar fuera lo que ya se había recorrido.
     */
    public function avanzarCola(?Carbon $masViejo): void
    {
        if ($masViejo === null) {
            return;
        }

        if ($this->barrido_hasta === null || $masViejo->lt($this->barrido_hasta)) {
            $this->barrido_hasta = $masViejo;
        }
    }
}
