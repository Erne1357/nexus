<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una carga cambió de estado. Se despacha después del commit: una transición que se revierte no avisa. Lleva el
 * comentario de esa transición (el motivo de una reapertura), para que quien escucha en cola no tenga que releer el
 * historial, donde otra transición pudo entrar después.
 */
final class LoadTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public AcademicLoad $load,
        public LoadStatus $from,
        public LoadStatus $to,
        public ?string $comment = null,
    ) {}
}
