<?php

namespace App\Enums;

enum EstadoPedidoWeb: string
{
    case Importado = 'importado';
    case ParaRevisar = 'para_revisar';
}
