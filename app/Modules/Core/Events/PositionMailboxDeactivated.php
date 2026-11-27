<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\PositionMailbox;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se desactivó un buzón de puesto (DeactivatePositionMailbox, CE-02): los avisos nuevos de su puesto vuelven a esperar,
 * salvo que otro buzón activo lo cubra. Sale después del commit; por ahora nadie lo escucha.
 */
final class PositionMailboxDeactivated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public PositionMailbox $mailbox) {}
}
