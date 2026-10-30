<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * SyncTeacherAccess cambió el acceso de un docente: le creó o le ligó usuario, o cambió su estado, su rol, sus
 * alcances o sus jefaturas (`$result`: `created`, `linked` o `updated`). No sale si no cambió nada ni con
 * `conflict`. Sale después del commit.
 */
final class TeacherAccessChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Teacher $teacher,
        public AccessSync $result,
    ) {}
}
