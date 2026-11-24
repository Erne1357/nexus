<?php

namespace App\Modules\Staffing\Jobs;

use App\Modules\Core\Models\User;
use App\Modules\Staffing\Actions\Selection\InviteSelectionTeachers;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Las invitaciones de acceso de la cola al publicar una selección (InviteSelectionTeachers, SEL-13): un departamento
 * tiene decenas de docentes, así que va a la cola (CLAUDE.md, §4.7) y se despacha después del commit de la publicación.
 * Lleva solo ids. Si la selección ya no está publicada ni viva (se canceló), no hace nada. Reintentar es seguro: a
 * quien ya tiene una invitación vigente no se le manda otra.
 */
final class InviteSelectionTeachersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $selectionId,
        public readonly ?string $actorId = null,
    ) {
        $this->afterCommit();
    }

    public function handle(InviteSelectionTeachers $invite): void
    {
        $selection = Selection::query()->find($this->selectionId);

        if ($selection === null || ! $selection->status->isLive()) {
            return;
        }

        $invite->handle($selection, $this->actorId === null ? null : User::query()->find($this->actorId));
    }
}
