<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\PositionMailbox;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se capturó, cambió o reactivó un buzón de puesto (SavePositionMailbox, CE-02). Sale después del commit. Lo escucha
 * Core para liberar los correos que esperaban un buzón (ReleaseHeldPositionMailsJob).
 */
final class PositionMailboxSaved implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public PositionMailbox $mailbox) {}
}
