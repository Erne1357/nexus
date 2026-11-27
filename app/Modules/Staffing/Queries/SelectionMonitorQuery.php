<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Support\AccessInvitationLink;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Rules\TeachingHours;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Estado en vivo de una selección publicada para el monitor del admin (diseño 6.8): turno vigente, avance por docente
 * (horas y grupos que lleva), ronda extra, renglones sin resolver y, por SEL-13, el acceso a Nexus de cada docente de
 * la cola: con acceso, invitado (invitación vigente), pendiente (con su motivo) o sin correo. Una consulta por cada
 * cosa, sin importar la cola.
 *
 * @phpstan-type Row array{entry: SelectionEntry, name: string, hours: int, target: int, groups: list<string>, current: bool, access: string, confirmation: ?string}
 * @phpstan-type Access array{counts: array{access: int, invited: int, pending: int, no_email: int}, missing: list<array{teacher_id: ?string, name: string, state: string, reason: string, resendable: bool}>}
 */
final class SelectionMonitorQuery
{
    /** El motivo del monitor para quien no tiene ningún correo (SEL-13). */
    public const string NO_EMAIL = 'Sin acceso: sin correo';

    /** El motivo de quien se puede invitar pero su invitación no salió o venció: el monitor ofrece reenviarla (SEL-13). */
    public const string NOT_SENT = 'Su invitación todavía no sale o ya venció.';

    public function __construct(
        private readonly SelectionTurnQuery $turns,
        private readonly SelectionGroupsQuery $groups,
        private readonly RoleAssignmentRules $rules,
        private readonly PriorChoiceHoursQuery $priorHours,
        private readonly TeachingTargetQuery $targets,
    ) {}

    /**
     * @return array{current: ?SelectionEntry, current_name: ?string, rows: list<Row>, prior: list<Row>, done: int, total: int, groups_taken: int, groups_total: int, hours_taken: int, hours_total: int, waiting: int, extra: list<Row>, unresolved: list<Row>, access: Access}
     */
    public function for(Selection $selection, CarbonImmutable $now): array
    {
        $entries = $selection->entries()->with('teacher:id,name')->orderBy('position')->orderBy('id')->get();
        $current = $this->turns->currentEntry($selection, $now);

        $groups = $this->groups->of($selection)
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->get(['groups.id', 'groups.code', 'groups.teacher_id', DB::raw('subjects.theory_hours + subjects.practice_hours as hours')]);

        $byTeacher = $groups->whereNotNull('teacher_id')->groupBy('teacher_id');
        $access = $this->accessOf($entries);
        $measured = $this->priorHours->forSelection($selection, $entries);
        $targets = $this->targets->forTerm(Term::query()->whereKey($selection->term_id)->firstOrFail(), $entries->whereNotNull('teacher_id')->pluck('base_hours', 'teacher_id')->map(fn ($csv): int => (int) $csv)->all());

        $rows = $entries->map(function (SelectionEntry $entry) use ($byTeacher, $current, $access, $measured, $targets): array {
            $taken = $byTeacher->get($entry->teacher_id, collect());

            return [
                'entry' => $entry,
                'name' => $entry->teacher?->name ?? $entry->csv_name,
                'hours' => (int) $taken->sum('hours'),
                'target' => $targets[$entry->teacher_id] ?? TeachingHours::fromCsvHours((int) $entry->base_hours),
                'groups' => $taken->pluck('code')->sort()->values()->all(),
                'current' => $current?->getKey() === $entry->getKey(),
                'access' => $access[$entry->teacher_id]['state'] ?? 'pending',
                // SEL-16 ampliada: «pending» (falta confirmar sus horas de más), «confirmed» o nulo.
                'confirmation' => match (true) {
                    ! isset($measured[$entry->getKey()]) => null,
                    $measured[$entry->getKey()]['confirmed_at'] !== null => 'confirmed',
                    $measured[$entry->getKey()]['needs_confirmation'] => 'pending',
                    default => null,
                },
            ];
        })->values();
        $missing = $rows->filter(fn (array $row): bool => ! in_array($row['access'], ['access', 'invited'], true))
            ->map(fn (array $row): array => [
                'teacher_id' => $row['entry']->teacher_id,
                'name' => (string) $row['name'],
                'state' => $row['access'],
                'reason' => $access[$row['entry']->teacher_id]['reason'] ?? 'El renglón no tiene docente.',
                'resendable' => ($access[$row['entry']->teacher_id]['reason'] ?? null) === self::NOT_SENT,
            ])
            ->values();

        $taken = $groups->whereNotNull('teacher_id');

        return [
            'current' => $current,
            'current_name' => $rows->firstWhere('current', true)['name'] ?? null,
            'rows' => $rows->reject(fn (array $row): bool => $row['entry']->isPriorChoice())->values()->all(),
            // SEL-16: quien ya trae sus grupos no tiene turno; sale aparte y no cuenta en el avance de los turnos.
            'prior' => $rows->filter(fn (array $row): bool => $row['entry']->isPriorChoice())->values()->all(),
            'done' => $entries->where('status', SelectionEntryStatus::Done)->whereNull('prior_choice_at')->count(),
            'total' => $entries->whereNull('prior_choice_at')->count(),
            'groups_taken' => $taken->count(),
            'groups_total' => $groups->count(),
            'hours_taken' => (int) $taken->sum('hours'),
            'hours_total' => (int) $groups->sum('hours'),
            'waiting' => $entries->where('status', SelectionEntryStatus::Waiting)->count(),
            'extra' => $rows->filter(fn (array $row): bool => $row['entry']->status === SelectionEntryStatus::PendingExtra)->values()->all(),
            'unresolved' => $rows->filter(fn (array $row): bool => $row['entry']->status === SelectionEntryStatus::Unresolved)->values()->all(),
            'access' => [
                'counts' => array_map(fn (string $state): int => $rows->where('access', $state)->count(), ['access' => 'access', 'invited' => 'invited', 'pending' => 'pending', 'no_email' => 'no_email']),
                'missing' => $missing->all(),
            ],
        ];
    }

    /**
     * SEL-13: el acceso a Nexus de cada docente de la cola, por id del docente: `access` (ya tiene contraseña), `invited`
     * (invitación vigente), `no_email` (sin correo institucional ni personal) o `pending`, con el motivo (sin usuario,
     * usuario inactivo, otro puesto o la invitación todavía no sale o ya venció). Tres consultas.
     *
     * @param  Collection<int, SelectionEntry>  $entries
     * @return array<string, array{state: string, reason: ?string}>
     */
    private function accessOf(Collection $entries): array
    {
        $teachers = Teacher::query()->withTrashed()->whereIn('id', $entries->pluck('teacher_id')->filter()->all())->get(['id', 'user_id', 'email', 'institutional_email']);
        $users = User::query()->whereIn('id', $teachers->pluck('user_id')->filter()->all())
            ->get(['id', 'is_active', 'access_invited_at', DB::raw('password is not null as has_access')])
            ->keyBy('id');
        $staff = array_flip($this->rules->staffUserIds()->whereIn('user_id', $users->keys()->all())->pluck('user_id')->all());
        $validUntil = CarbonImmutable::now()->subDays(AccessInvitationLink::DAYS);
        $hasEmail = fn (?string $email): bool => filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL) !== false;

        return $teachers->mapWithKeys(function (Teacher $teacher) use ($users, $staff, $validUntil, $hasEmail): array {
            $user = $teacher->user_id === null ? null : $users->get($teacher->user_id);

            return [$teacher->getKey() => match (true) {
                $user !== null && (bool) $user->has_access => ['state' => 'access', 'reason' => null],
                $user !== null && $user->access_invited_at !== null && $user->access_invited_at->greaterThan($validUntil) => ['state' => 'invited', 'reason' => null],
                ! $hasEmail($teacher->email) && ! $hasEmail($teacher->institutional_email) => ['state' => 'no_email', 'reason' => self::NO_EMAIL],
                $user === null => ['state' => 'pending', 'reason' => 'El docente no tiene usuario.'],
                ! $user->is_active => ['state' => 'pending', 'reason' => 'Su usuario está inactivo.'],
                isset($staff[$user->getKey()]) => ['state' => 'pending', 'reason' => SendAccessInvitation::STAFF],
                default => ['state' => 'pending', 'reason' => self::NOT_SENT],
            }];
        })->all();
    }
}
