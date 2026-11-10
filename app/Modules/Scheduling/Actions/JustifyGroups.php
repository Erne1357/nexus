<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Justifica varios grupos con un mismo motivo (HC-15; justificación en lote, confirmado el 10 de noviembre de 2026):
 * quien cierra la carga elige los grupos sin docente o sin salón y escribe un motivo para todos. Cada grupo pasa por
 * JustifyGroup, así que queda con su justificación, quién y cuándo, y su bitácora, con sus reglas (la carga se edita;
 * solo un grupo al que le falta docente o salón), y después se edita uno por uno. La policy `justify` va por grupo:
 * uno que el usuario no justifica no se toca.
 *
 * Todo corre en una transacción; lo que no aplica no detiene a los demás y se devuelve con su motivo, en el orden en
 * que llegó. Un id que no existe se ignora. Hasta MAX grupos por vez.
 */
final class JustifyGroups
{
    public const int MAX = 200;

    public function __construct(private readonly JustifyGroup $justify) {}

    /**
     * @param  list<string>  $groupIds
     * @return array{justified: list<string>, skipped: list<array{code: string, reason: string}>}
     *
     * @throws ValidationException
     */
    public function handle(array $groupIds, string $justification, User $actor): array
    {
        $justification = trim($justification);
        $groupIds = array_values(array_unique(array_filter($groupIds, fn (mixed $id): bool => is_string($id) && Str::isUuid($id))));

        if ($justification === '') {
            throw ValidationException::withMessages(['justification' => 'Escribe la justificación.']);
        }

        if (count($groupIds) > self::MAX) {
            throw ValidationException::withMessages(['groups' => 'Justifica hasta '.self::MAX.' grupos por vez.']);
        }

        $groups = Group::query()->with('campus')->whereKey($groupIds)->get()->keyBy('id');

        return DB::transaction(function () use ($groupIds, $groups, $justification, $actor): array {
            $justified = [];
            $skipped = [];

            foreach ($groupIds as $id) {
                $group = $groups[$id] ?? null;

                if ($group === null) {
                    continue;
                }

                if (! Gate::forUser($actor)->allows('justify', $group)) {
                    $skipped[] = ['code' => $group->code, 'reason' => 'Lo justifica quien cierra la carga de '.Department::withTrashed()->whereKey($group->department_id)->value('name').'.'];

                    continue;
                }

                try {
                    $this->justify->handle($group, $justification, $actor);
                    $justified[] = $group->id;
                } catch (ValidationException $e) {
                    $skipped[] = ['code' => $group->code, 'reason' => (string) collect($e->errors())->flatten()->first()];
                }
            }

            return ['justified' => $justified, 'skipped' => $skipped];
        });
    }
}
