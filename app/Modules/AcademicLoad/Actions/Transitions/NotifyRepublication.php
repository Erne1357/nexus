<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\RepublicationAudience;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Core\Rules\PasswordResetRules;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa la republicación de una carga a quien le cambió algo (RA-05 y RA-06, confirmados el 17 de noviembre de 2026;
 * tarea 8.4): lee el diff guardado de la versión (SnapshotDiff) y solo si la versión es la 2 o posterior y cambió algo.
 * Cada destinatario recibe un LoadRepublished con sus líneas:
 *
 * - cada docente afectado (SnapshotDiff::teachers(): el de antes y el de ahora de un grupo nuevo, cancelado o cambiado),
 *   con exactamente lo que cambió para él. El docente se resuelve con su ficha (Faculty): si tiene usuario, al usuario
 *   (campana y correo); si no tiene usuario pero sí correo institucional, solo el correo, por ruta; sin ninguno de los
 *   dos, a nadie. Una ficha borrada o dada de baja (`is_active = false`) no recibe nada, ni campana ni correo, aunque
 *   tenga usuario o correo institucional (supuesto por confirmar). Quien es docente y también coordinador o jefe recibe un
 *   aviso por cada papel, con las líneas de ese papel;
 * - los coordinadores de cada carrera afectada (SnapshotDiff::careers()): los titulares de enviar la propuesta de la
 *   carrera en la modalidad de la carga, de cualquier campus, como NotifyProposalReturned (M6, MS18). El aviso abre el
 *   semestre publicado de la carrera con la retícula y el semestre del primer grupo cambiado que la lleva (el de su primera
 *   línea; de la versión anterior si se canceló), no el primero de la retícula (ensayo 8.11, E-15);
 * - los jefes de cada otro departamento afectado (SnapshotDiff::departments(): préstamos de salón y grupos compartidos):
 *   los titulares de decidir acuerdos de ese departamento en la modalidad, como los avisos de los acuerdos (M12).
 *
 * No avisa a Servicios Escolares ni a Subdirección: ninguno es docente, coordinador ni titular de decidir acuerdos
 * (RA-05). El jefe del departamento de la carga tampoco recibe, salvo como docente. Da a cuántos destinatarios avisó.
 */
final class NotifyRepublication
{
    public function __construct(
        private readonly RecipientsQuery $recipients,
        private readonly NotifyHolders $notifyHolders,
        private readonly PasswordResetRules $emails,
    ) {}

    public function handle(AcademicLoad $load, int $version): int
    {
        $snapshot = $version < 2 ? null : LoadSnapshot::query()->where('academic_load_id', $load->getKey())->where('version', $version)->first();
        $diff = $snapshot?->diff;

        if ($diff === null || SnapshotDiff::isEmpty($diff)) {
            return 0;
        }

        $load->loadMissing(['term', 'modality', 'department']);
        $payload = SnapshotPayload::normalize($snapshot->payload);
        $notify = fn (RepublicationAudience $audience, string $label, array $entries, string $route, array $parameters): LoadRepublished => new LoadRepublished($load, $version, $snapshot->reason, $audience, $label, $entries, $route, $parameters);

        return $this->teachers($load, $diff, $payload, $notify)
            + $this->coordinators($load, $version, $diff, $payload, $notify)
            + $this->departments($load, $diff, $notify);
    }

    /**
     * @param  array<string, mixed>  $diff
     * @param  array<string, mixed>  $payload
     */
    private function teachers(AcademicLoad $load, array $diff, array $payload, callable $notify): int
    {
        $affected = SnapshotDiff::teachers($diff);
        $records = Teacher::query()->with('user')->whereKey(array_keys($affected))->get()->keyBy('id');
        $teaching = array_flip(array_filter(array_map(fn (array $group): ?string => $group['teacher']['id'] ?? null, $payload['groups'])));
        $sent = 0;

        foreach ($affected as $id => $teacher) {
            $record = $records[$id] ?? null;

            if ($record === null || ! $record->is_active) {
                continue;
            }

            [$route, $parameters] = isset($teaching[$id])
                ? ['published.teacher', [...$this->published($load), 'docente' => (string) $id]]
                : ['published.index', $this->published($load)];
            $notice = $notify(RepublicationAudience::Teacher, $teacher['teacher']['name'], $teacher['entries'], $route, $parameters);

            if ($record->user !== null) {
                $record->user->notify($notice);
                $sent++;
            } elseif ($this->emails->isInstitutionalEmail($record->institutional_email)) {
                Notification::route('mail', mb_strtolower((string) $record->institutional_email))->notify($notice);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $diff
     * @param  array<string, mixed>  $payload
     */
    private function coordinators(AcademicLoad $load, int $version, array $diff, array $payload, callable $notify): int
    {
        $published = array_flip(SnapshotDiff::careerIds($payload));
        $placements = self::placements($payload);
        $previous = null;
        $sent = 0;

        foreach (SnapshotDiff::careers($diff) as $id => $career) {
            $semester = [];

            if (isset($published[$id])) {
                $semester = $this->semesterOf($career['entries'], (string) $id, $placements);

                if ($semester === null) {
                    $previous ??= self::placements(SnapshotPayload::normalize(LoadSnapshot::query()->where('academic_load_id', $load->getKey())->where('version', $version - 1)->value('payload') ?? ['groups' => []]));
                    $semester = $this->semesterOf($career['entries'], (string) $id, $placements, $previous) ?? [];
                }
            }

            [$route, $parameters] = isset($published[$id])
                ? ['published.semester', [...$this->published($load), 'carrera' => $career['career']['code'], ...$semester]]
                : ['published.index', $this->published($load)];
            $holders = $this->recipients->holders(AcademicLoadPermission::ProposalSubmit, Target::of(modality: $load->modality_id, career: (string) $id));

            $sent += $this->notifyHolders->handle($holders, $notify(RepublicationAudience::Career, $career['career']['code'], $career['entries'], $route, $parameters))->count();
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function departments(AcademicLoad $load, array $diff, callable $notify): int
    {
        $affected = SnapshotDiff::departments($diff);
        $names = Department::withTrashed()->whereKey(array_keys($affected))->pluck('name', 'id');
        $sent = 0;

        foreach ($affected as $id => $department) {
            $holders = $this->recipients->holders(AcademicLoadPermission::AgreementDecide, Target::of(modality: $load->modality_id, unit: (string) $id));

            $sent += $this->notifyHolders->handle($holders, $notify(RepublicationAudience::Department, (string) ($names[$id] ?? 'otro departamento'), $department['entries'], 'published.index', $this->published($load)))->count();
        }

        return $sent;
    }

    /**
     * La retícula y el semestre (`?reticula=&semestre=` del semestre publicado) del primer grupo de las líneas de una
     * carrera que la lleva con semestre: el grupo como quedó o, si se canceló, como estaba en la versión anterior
     * (`$previous`). Null si para encontrarlo hace falta la versión anterior y no se dio; [] si ningún grupo lo dice.
     *
     * @param  list<array{kind: string, group: array<string, mixed>}>  $entries
     * @param  array<string, array<string, array<string, mixed>>>  $placements  los lugares de la versión nueva, por grupo y carrera
     * @param  array<string, array<string, array<string, mixed>>>|null  $previous  los de la versión anterior
     * @return array{reticula?: string, semestre?: string}|null
     */
    private function semesterOf(array $entries, string $career, array $placements, ?array $previous = null): ?array
    {
        foreach (self::visibleFirst($entries) as $entry) {
            $group = (string) $entry['group']['id'];

            if (! isset($placements[$group]) && $previous === null) {
                return null;
            }

            $placement = ($placements[$group] ?? $previous[$group] ?? [])[$career] ?? null;

            if (($placement['semester'] ?? null) !== null && ($placement['study_plan']['code'] ?? null) !== null) {
                return ['reticula' => $placement['study_plan']['code'], 'semestre' => (string) $placement['semester']];
            }
        }

        return [];
    }

    /**
     * Las líneas con lo que muestra la consulta pública primero (grupos nuevos, cancelados y cambios de horario, salón o
     * docente) y al final las que solo cambian cupos, que la consulta no enseña (SnapshotDiffText::publicEntries), así el
     * enlace del coordinador abre un semestre con la marca «Cambió» (ensayo 8.11, G-06). Conserva el orden dentro de cada
     * parte.
     *
     * @param  list<array{kind: string, group: array<string, mixed>, changes?: array<string, mixed>}>  $entries
     * @return list<array{kind: string, group: array<string, mixed>, changes?: array<string, mixed>}>
     */
    private static function visibleFirst(array $entries): array
    {
        $quotaOnly = static fn (array $entry): bool => $entry['kind'] === 'changed'
            && array_diff_key($entry['changes'] ?? [], ['quota' => true, 'career_quotas' => true]) === [];

        return [
            ...array_values(array_filter($entries, static fn (array $entry): bool => ! $quotaOnly($entry))),
            ...array_values(array_filter($entries, $quotaOnly)),
        ];
    }

    /**
     * Los lugares de cada grupo de un payload, por id del grupo y de la carrera (el primero de cada carrera).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function placements(array $payload): array
    {
        $placements = [];

        foreach ($payload['groups'] as $group) {
            foreach ($group['placements'] as $placement) {
                $placements[(string) $group['id']][(string) $placement['career']['id']] ??= $placement;
            }
        }

        return $placements;
    }

    /**
     * El periodo y la modalidad de la consulta publicada de la carga (`/horarios/{periodo}/{modalidad}`).
     *
     * @return array{periodo: string, modalidad: string}
     */
    private function published(AcademicLoad $load): array
    {
        return ['periodo' => $load->term->code, 'modalidad' => PublishedContextQuery::modalityKey($load->modality)];
    }
}
