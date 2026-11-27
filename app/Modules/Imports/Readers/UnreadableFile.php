<?php

namespace App\Modules\Imports\Readers;

use RuntimeException;

/**
 * El archivo no se puede importar tal como está (formato, encabezado). El mensaje, en español, es el
 * que ve el admin en el lote fallido.
 */
final class UnreadableFile extends RuntimeException {}
