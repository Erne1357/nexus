<?php

namespace App\Modules\Imports\Actions;

use LogicException;

/**
 * El lote no está en el estado que la Action necesita para empezar: otra corrida ya lo tomó o ya
 * terminó. El job de la cola que la recibe termina sin tocar el lote.
 */
final class BatchNotReady extends LogicException {}
