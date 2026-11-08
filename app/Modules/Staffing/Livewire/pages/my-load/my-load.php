<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Queries\MyLoadQuery;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Mi carga (tarea 3.10), maqueta C «Columnas por día»: la cuenta de horas del docente como una ecuación y sus grupos del
 * periodo por día, de todos los campus, con huecos y traslados. Lo ve quien tiene el permiso personal
 * `staffing.my_load.view` y una ficha de docente con su usuario. Más abajo queda un espacio vacío para «Mis solicitudes»
 * (tarea 3.11).
 */
new #[Title('Mi carga')] class extends Component
{
    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(StaffingPermission::MyLoadView), 403);
    }

    public function with(Actor $context, MyLoadQuery $query): array
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StaffingPermission::MyLoadView, Target::ownedBy((string) $user->getKey())), 403);

        $term = $context->activeTerm();
        $teacher = Teacher::query()->where('user_id', $user->getKey())->where('is_active', true)->first();

        return [
            'term' => $term,
            'teacher' => $teacher,
            'load' => $term === null || $teacher === null ? null : $query->forTeacher($teacher, $term),
        ];
    }

    /**
     * «2 h» o «1 h 30 min».
     */
    public static function span(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours > 0 ? $hours.' h' : '').($rest > 0 ? ' '.$rest.' min' : ''));
    }
};
