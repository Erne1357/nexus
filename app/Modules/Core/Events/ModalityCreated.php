<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\Modality;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta una modalidad (CreateModality). Sale después del commit.
 */
final class ModalityCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Modality $modality) {}
}
