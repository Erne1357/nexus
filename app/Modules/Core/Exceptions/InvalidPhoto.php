<?php

namespace App\Modules\Core\Exceptions;

use RuntimeException;

/**
 * Una foto que no se puede normalizar (CO-03): no es una imagen jpg, png o webp legible o pesa más de lo permitido. El
 * mensaje va en español y se le muestra a quien la subió.
 */
final class InvalidPhoto extends RuntimeException {}
