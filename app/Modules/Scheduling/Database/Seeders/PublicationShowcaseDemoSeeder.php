<?php

namespace App\Modules\Scheduling\Database\Seeders;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReturnToStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\JustifyGroup;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomSuggestionQuery;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo mezclada (`todo`, tarea 4.3): una carga con tres versiones publicadas para ver la línea de versiones, lo que
 * cambió entre ellas y la consulta pública con «Actualizado el». Pasa por el proceso real: justifica lo que le falte
 * (JustifyGroup), cierra (CloseLoad) y publica la versión 1; después, dos veces, Subdirección la reabre con motivo,
 * el jefe la regresa a asignación, cambia el salón de algunas sesiones por otro libre del departamento
 * (RoomSuggestionQuery y AssignSessionRoom), la cierra y la publica de nuevo. Con el cambio de salones, cada versión
 * muestra «N grupos cambiaron de salón» contra la anterior.
 *
 * Corre después del cierre de la demo mezclada (LoadClosingDemoSeeder en modo `mixed`, Riel C), sobre la carga que ese
 * paso deja cerrada: con el volumen demo, CEA de Campus II en tres versiones; con el masivo, CB de Campus II en dos
 * versiones y reabierta al final (`reopenAtEnd`), el caso «reabierta después de publicar»: el público sigue viendo la
 * versión 2 y la pantalla de cierre la ve en ajustes con su historial. Una carga en asignación también sirve (la
 * justifica y la cierra antes). Si un veto lo detiene, la demo se detiene con su motivo. Corre una vez sobre la base
 * recién sembrada (`demo:seed` hace `migrate:fresh`): una segunda corrida se niega, porque la carga ya no está en
 * asignación ni cerrada.
 */
final class PublicationShowcaseDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** Las sesiones que cambian de salón en cada versión nueva. */
    private const int MOVES_PER_VERSION = 4;

    /** El motivo de la reapertura que deja la carga en ajustes con su última versión publicada. */
    private const string LAST_REOPENING = 'Revisión de grupos compartidos con otra carrera';

    /** @var list<string> los motivos de cada reapertura, en orden */
    private const array REOPENINGS = [
        'Ajuste de salones de laboratorio tras la revisión de Subdirección',
        'Cambio de salones por el mantenimiento del edificio',
    ];

    public function run(string $campus = 'C2', string $department = 'CEA', string $term = '20271', int $versions = 3, bool $reopenAtEnd = false): void
    {
        $this->refuseProduction();

        $load = AcademicLoad::query()
            ->where('term_id', Term::query()->where('code', $term)->firstOrFail()->id)
            ->where('campus_id', Campus::query()->where('code', $campus)->firstOrFail()->id)
            ->where('department_id', Department::query()->where('code', $department)->firstOrFail()->id)
            ->firstOrFail();

        if (! in_array($load->status, [LoadStatus::Staffing, LoadStatus::Closed], true)) {
            throw new RuntimeException("La carga de {$department} en {$campus} está en {$load->status->label()}: la demo de versiones empieza en asignación o cerrada.");
        }

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $head = User::query()->where('username', 'jefe.'.Str::lower($department))->first() ?? $admin;
        $subdireccion = User::query()->where('username', 'subdir')->first() ?? $admin;

        if ($load->status === LoadStatus::Staffing) {
            $this->justifyMissing($load, $head);
            $this->closeAndPublish($load, $head, 'Carga lista', 'Primera publicación');
        } else {
            app(PublishLoad::class)->handle($load, $head, 'Primera publicación');
        }

        foreach (array_slice(self::REOPENINGS, 0, max(0, $versions - 1)) as $index => $reason) {
            app(ReopenLoad::class)->handle($load->fresh(), $subdireccion, $reason);
            app(ReturnToStaffing::class)->handle($load->fresh(), $head, 'Se corrigen los salones');
            $this->moveRooms($load, $head, $index);
            $this->justifyMissing($load, $head);
            $this->closeAndPublish($load, $head, null, $reason);
        }

        if ($reopenAtEnd) {
            app(ReopenLoad::class)->handle($load->fresh(), $subdireccion, self::LAST_REOPENING);
        }

        $this->command?->info("Carga de {$department} en {$campus} publicada en ".$load->fresh()->current_version.' versiones'.($reopenAtEnd ? ' y reabierta.' : '.'));
    }

    private function closeAndPublish(AcademicLoad $load, User $head, ?string $closing, string $publishing): void
    {
        app(ScheduleValidator::class)->forget();
        app(CloseLoad::class)->handle($load->fresh(), $head, $closing);
        app(ScheduleValidator::class)->forget();
        app(PublishLoad::class)->handle($load->fresh(), $head, $publishing);
    }

    /**
     * Justifica cada grupo activo al que le falte docente o salón, como lo haría el jefe antes de cerrar (HC-15).
     */
    private function justifyMissing(AcademicLoad $load, User $head): void
    {
        $this->groups($load)
            ->filter(fn (Group $group): bool => $group->teacher_id === null || $group->sessions->contains(
                fn (GroupSession $session): bool => $session->room_id === null && $session->kind !== SessionKind::Virtual,
            ))
            ->each(fn (Group $group) => app(JustifyGroup::class)->handle($group, 'Se asigna antes del inicio de clases.', $head));
    }

    /**
     * Cambia de salón unas sesiones presenciales, distintas en cada vuelta, al primer salón sugerido libre, del propio
     * departamento o sin dueño, donde cabe el grupo. Cada vuelta empieza más adelante en la lista y da la vuelta al
     * final, así que una carga con pocas sesiones también cambia.
     */
    private function moveRooms(AcademicLoad $load, User $head, int $round): void
    {
        $placed = $this->groups($load)
            ->flatMap(fn (Group $group) => $group->sessions)
            ->filter(fn (GroupSession $session): bool => $session->room_id !== null)
            ->sortBy('id')
            ->values();
        $offset = ($round * self::MOVES_PER_VERSION * 3) % max(1, $placed->count());
        $sessions = $placed->slice($offset)->concat($placed->take($offset));

        $moved = 0;
        foreach ($sessions as $session) {
            if ($moved === self::MOVES_PER_VERSION) {
                break;
            }

            $target = collect(app(RoomSuggestionQuery::class)->for($session->fresh(), 20)['rooms'])->first(fn (array $row): bool => $row['id'] !== $session->room_id
                && $row['state']['free'] && ! $row['state']['needsLoan'] && ! $row['state']['tooSmall'] && ! $row['state']['wrongType'] && $row['state']['blocked'] === null);

            if ($target !== null) {
                app(AssignSessionRoom::class)->handle($session->fresh(), Room::query()->findOrFail($target['id']), $head);
                $moved++;
            }
        }

        if ($moved === 0) {
            throw new RuntimeException("Ninguna sesión de la carga de {$load->department->code} tiene otro salón libre para la versión siguiente.");
        }
    }

    /**
     * @return Collection<int, Group>
     */
    private function groups(AcademicLoad $load): Collection
    {
        return Group::query()
            ->where('term_id', $load->term_id)->where('campus_id', $load->campus_id)->where('department_id', $load->department_id)
            ->where('status', '!=', GroupStatus::Cancelled->value)
            ->with('sessions')
            ->orderBy('id')
            ->get();
    }
}
