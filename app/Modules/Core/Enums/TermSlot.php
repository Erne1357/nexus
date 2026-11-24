<?php

namespace App\Modules\Core\Enums;

/**
 * Cuál de los dos periodos de la credencial (TP-04): el de consulta, que siguen el mapa, Inicio, Mi carga en curso y las
 * fichas, o el de carga, que siguen las pantallas que arman la carga académica.
 */
enum TermSlot: string
{
    case View = 'view';
    case Load = 'load';
}
