<?php

namespace App\Modules\Staffing\Database\Seeders;

use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Actions\Transitions\PublishLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReturnToStaffing;
use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\ReanalysisChangesQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisCycleQuery;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\JustifyGroup;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Database\Seeders\ReanalysisChangesDemoSeeder;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use App\Modules\Staffing\Actions\UnassignTeacher;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Demo del re-análisis (fase 8, #614), la parte de Staffing, que llama a las Actions de Scheduling y AcademicLoad (están a
 * su izquierda): termina cada caso del plan de ReanalysisDemoSeeder que cambia la carga, después de los ajustes de
 * Scheduling (ReanalysisChangesDemoSeeder):
 *
 * 1. **Docentes** en cada carga en re-análisis que todavía no se cerró: si Scheduling no cerró ningún grupo (CancelGroup no
 *    cancela uno con docente y todos los que sobran lo tienen), el jefe le quita el docente a uno (UnassignTeacher) y lo
 *    cancela (ReanalysisChangesDemoSeeder::cancelGuarded()); da docente a los grupos abiertos (con los candidatos de los
 *    grupos de la misma materia) y les quita la justificación; y pasa 2 o 3 grupos (`teacher` del diff; cada asignación
 *    vuelve a leer la foto del validador, que con el masivo cuesta) al siguiente de su ranking que pasa HC-01, HC-08 y
 *    HC-09 (AssignTeacher, con su motivo, HC-16).
 * 2. **Cierre** (CloseLoad) de las cargas «con cambios», «cerrada», «republicada» y «corregida».
 * 3. **Devolución** (RA-08): Subdirección regresa la carga «con cambios» con su motivo y vuelve a re-análisis.
 * 4. **Versión 2** por re-análisis (PublishLoad) de la «republicada» y la «corregida», con su diff y los avisos a docentes,
 *    coordinadores y jefes de otros departamentos (NotifyAffectedOnRepublication).
 * 5. La **reimportación** del kárdex sin calificaciones nuevas (ReanalysisDemoSeeder en modo `reimport`), si hay una
 *    «republicada».
 * 6. **Versión 3 por corrección** de la «corregida»: Subdirección la reabre a ajustes con su motivo, el jefe la regresa a
 *    asignación, cambia unos salones y un docente, la cierra y la publica.
 *
 * Cada parte revisa el estado de la carga antes de escribir (una carga que ya salió de re-análisis o ya va en su versión
 * 3 no se toca), así que se puede volver a correr, y una corrida que se cayó a la mitad sigue desde donde se quedó: la
 * devolución tiene su pasada, la corrección sigue desde el estado de la carga y los cambios de docente cuentan los que ya
 * hizo (por su motivo, desde el inicio del re-análisis o la reapertura). Mientras cambia docentes y salones descarta los eventos de recálculo y
 * al final recalcula una vez los choques y `teacher_term_loads`. Un veto detiene la demo con su motivo.
 */
final class ReanalysisRepublicationDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const string TEACHER_REASON = 'Re-análisis: se reparten los grupos del departamento con la demanda nueva.';

    private const string OPENED_REASON = 'Grupo abierto en el re-análisis: no tiene ranking propio, se elige entre los de la misma materia.';

    /** Cuántos grupos prueba por cada cambio que busca, para que el masivo no recorra la carga entera. */
    private const int MAX_ATTEMPTS = 5;

    private const string CANCEL_REASON = 'Se cierra el grupo en el re-análisis: sobran lugares con las calificaciones nuevas.';

    private const string RETURN_REASON = 'Revisa los cupos de los grupos compartidos antes de publicar: dos carreras siguen cortas.';

    private const string CORRECTION_REASON = 'Corrección: cambian salones por el mantenimiento del edificio.';

    public function __construct(private readonly ScheduleValidator $validator) {}

    public function run(string $term = '20271', string $modality = 'PRES'): void
    {
        $this->refuseProduction();

        $termModel = Term::query()->where('code', $term)->firstOrFail();
        $modalityModel = Modality::query()->where('code', $modality)->firstOrFail();
        $plan = ReanalysisDemoSeeder::plan($termModel->getKey(), $modalityModel->getKey());
        $loads = ReanalysisDemoSeeder::loadsIn($termModel->getKey(), $modalityModel->getKey(), ReanalysisDemoSeeder::CHANGING);
        $open = $loads->filter(fn (AcademicLoad $load): bool => $load->status === LoadStatus::Reanalysis && ! app(ReanalysisCycleQuery::class)->closedFromReanalysis($load))->values();
        $started = hrtime(true);
        $lap = function () use (&$started): string {
            $seconds = (hrtime(true) - $started) / 1e9;
            $started = hrtime(true);

            return sprintf('%.1f s', $seconds);
        };

        $this->changeTeachers($open, $termModel, $modalityModel);
        $times = ['docentes' => $lap()];

        foreach ($open as $load) {
            $this->validator->forget();
            app(CloseLoad::class)->handle($load->fresh(), ReanalysisDemoSeeder::headOf($load), 'Re-análisis terminado: grupos y cupos ajustados a la demanda nueva.');
        }

        // La devolución va en una pasada aparte: una corrida que se cayó entre el cierre y la devolución la retoma aquí.
        foreach ($loads as $load) {
            $load->refresh();

            if ($plan[$load->getKey()] === ReanalysisDemoSeeder::CASE_CHANGED && $load->status === LoadStatus::Closed && app(ReanalysisCycleQuery::class)->closedFromReanalysis($load)) {
                app(ReopenLoad::class)->handle($load, ReanalysisDemoSeeder::subdireccion(), self::RETURN_REASON);
            }
        }

        $times['cierres'] = $lap();
        $republished = [];

        foreach ($loads as $load) {
            $load->refresh();

            if (in_array($plan[$load->getKey()], [ReanalysisDemoSeeder::CASE_REPUBLISHED, ReanalysisDemoSeeder::CASE_CORRECTED], true)
                && $load->status === LoadStatus::Closed && $load->current_version === 1) {
                $this->validator->forget();
                app(PublishLoad::class)->handle($load, ReanalysisDemoSeeder::headOf($load), 'Versión 2 con el re-análisis de las calificaciones.');
                $republished[] = $load->department->code;
            }
        }

        $times['versiones 2'] = $lap();
        $this->callWith(ReanalysisDemoSeeder::class, ['mode' => 'reimport', 'term' => $term, 'modality' => $modality]);
        $times['reimportación'] = $lap();
        $corrected = [];

        foreach ($loads as $load) {
            $load->refresh();

            if ($plan[$load->getKey()] === ReanalysisDemoSeeder::CASE_CORRECTED && $load->current_version === 2
                && in_array($load->status, [LoadStatus::Published, LoadStatus::Adjusting, LoadStatus::Staffing, LoadStatus::Closed], true)) {
                $this->correct($load, $termModel, $modalityModel);
                $corrected[] = $load->department->code;
            }
        }

        $times['corrección'] = $lap();

        $this->command?->info(sprintf(
            'Re-análisis: cerradas %s; republicadas en v2 %s; corregidas en v3 %s (%s).',
            $open->isEmpty() ? 'ninguna nueva' : $open->map(fn (AcademicLoad $load): string => $load->department->code)->implode(', '),
            $republished === [] ? 'ninguna nueva' : implode(', ', $republished),
            $corrected === [] ? 'ninguna nueva' : implode(', ', $corrected),
            implode(', ', array_map(fn (string $part, string $time): string => "{$part} {$time}", array_keys($times), $times)),
        ));
    }

    /**
     * Cambia unos docentes y da docente a los grupos abiertos de cada carga, con los eventos de recálculo descartados, y
     * recalcula una vez los choques y la carga de los docentes.
     *
     * @param  Collection<int, AcademicLoad>  $loads
     */
    private function changeTeachers(Collection $loads, Term $term, Modality $modality): void
    {
        if ($loads->isEmpty()) {
            return;
        }

        Event::fakeFor(function () use ($loads): void {
            foreach ($loads as $load) {
                $head = ReanalysisDemoSeeder::headOf($load);
                $this->cancelTaught($load, $head);
                $this->staffOpened($load, $head);
                $this->reassign($load, $head, min(3, max(2, intdiv($this->groups($load)->count(), 40))));
            }
        }, [...ReanalysisChangesDemoSeeder::FAKED_EVENTS, GroupTeacherChanged::class]);

        $this->recompute($term, $modality);
    }

    /**
     * Si Scheduling no pudo cerrar ningún grupo de la carga (CancelGroup no cancela uno con docente y todos los que sobran
     * lo tienen), el jefe le quita el docente a uno de los que sobran (UnassignTeacher) y lo cancela; las dos cosas en una
     * transacción que se revierte si el grupo no se puede cerrar (HC-03).
     */
    private function cancelTaught(AcademicLoad $load, User $head): void
    {
        $changes = app(ReanalysisChangesDemoSeeder::class);

        if (app(ReanalysisChangesQuery::class)->since($load)['summary']['cancelled'] > 0) {
            return;
        }

        foreach ($changes->surplusCandidates($load)->whereNotNull('teacher_id')->take(self::MAX_ATTEMPTS) as $group) {
            DB::beginTransaction();

            try {
                app(UnassignTeacher::class)->handle($group, $head, self::CANCEL_REASON);
                $cancelled = $changes->cancelGuarded($group, $head);
                $cancelled ? DB::commit() : DB::rollBack();
            } catch (Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            if ($cancelled) {
                return;
            }
        }
    }

    /**
     * Versión 3 por corrección, desde el estado en que esté la carga en su versión 2 (una corrida que se cayó a la mitad
     * sigue desde ahí): publicada, Subdirección la reabre a ajustes; en ajustes, el jefe la regresa a asignación; en
     * asignación, si todavía no cambió nada desde la versión 2, cambia unos salones y un docente, justifica lo que falte y
     * la cierra; cerrada, la publica. La versión 3 sale después de la reimportación del kárdex, así que la pantalla dice
     * «Sin kárdex nuevo».
     */
    private function correct(AcademicLoad $load, Term $term, Modality $modality): void
    {
        $head = ReanalysisDemoSeeder::headOf($load);

        if ($load->fresh()->status === LoadStatus::Published) {
            app(ReopenLoad::class)->handle($load->fresh(), ReanalysisDemoSeeder::subdireccion(), self::CORRECTION_REASON);
        }

        if ($load->fresh()->status === LoadStatus::Adjusting) {
            app(ReturnToStaffing::class)->handle($load->fresh(), $head, 'Se corrigen los salones');
        }

        if ($load->fresh()->status === LoadStatus::Staffing) {
            if (! app(ReanalysisChangesQuery::class)->hasChanges($load->fresh())) {
                Event::fakeFor(function () use ($load, $head): void {
                    app(ReanalysisChangesDemoSeeder::class)->change($load->fresh(), $head, ['rooms'], round: 1);
                    $this->reassign($load, $head, 1);
                }, [...ReanalysisChangesDemoSeeder::FAKED_EVENTS, GroupTeacherChanged::class]);

                $this->recompute($term, $modality);
            }

            $this->justifyMissing($load, $head);
            $this->validator->forget();
            app(CloseLoad::class)->handle($load->fresh(), $head, 'Salones corregidos');
        }

        if ($load->fresh()->status === LoadStatus::Closed) {
            $this->validator->forget();
            app(PublishLoad::class)->handle($load->fresh(), $head, self::CORRECTION_REASON);
        }
    }

    /**
     * Pasa `$wanted` grupos con docente al siguiente de su ranking que pasa las reglas duras del docente, empezando por
     * los últimos grupos de la carga.
     */
    private function reassign(AcademicLoad $load, User $head, int $wanted): int
    {
        $groups = $this->groups($load);
        $done = GroupTeacherAssignment::query()->whereIn('group_id', $groups->modelKeys())->where('reason', self::TEACHER_REASON)
            ->where('assigned_at', '>=', $this->since($load))->count();
        $attempts = 0;

        foreach ($groups->whereNotNull('teacher_id')->sortByDesc('id') as $group) {
            if ($done >= $wanted || $attempts === self::MAX_ATTEMPTS * $wanted) {
                break;
            }

            $attempts++;
            $candidates = StaffingRanking::query()->where('group_id', $group->getKey())->where('teacher_id', '!=', $group->teacher_id)->orderBy('rank')->limit(15)->pluck('teacher_id')->all();

            if ($this->assignFirstFitting($group, $candidates, $head, self::TEACHER_REASON)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Desde cuándo cuentan los cambios de docente de esta vuelta: el último inicio del re-análisis o, en la corrección, la
     * última reapertura.
     */
    private function since(AcademicLoad $load): string
    {
        return (string) $load->transitions()->whereIn('to_status', [LoadStatus::Reanalysis->value, LoadStatus::Adjusting->value])->max('created_at');
    }

    /**
     * Da docente a los grupos abiertos en el re-análisis (sin docente y con su justificación): el primero que pasa de los
     * rankings de los grupos de la misma materia; con docente y salones ya no necesitan la justificación.
     */
    private function staffOpened(AcademicLoad $load, User $head): void
    {
        $opened = $this->groups($load)->whereNull('teacher_id')->where('close_justification', ReanalysisChangesDemoSeeder::OPENED_JUSTIFICATION);

        foreach ($opened as $group) {
            $candidates = StaffingRanking::query()
                ->whereIn('group_id', Group::query()->where('term_id', $group->term_id)->where('subject_id', $group->subject_id)->select('id'))
                ->orderBy('rank')
                ->pluck('teacher_id')->unique()->take(25)->values()->all();

            if ($this->assignFirstFitting($group, $candidates, $head, self::OPENED_REASON)) {
                app(JustifyGroup::class)->handle($group->fresh(), null, $head);
            }
        }
    }

    /**
     * @param  list<string>  $teacherIds
     */
    private function assignFirstFitting(Group $group, array $teacherIds, User $head, string $reason): bool
    {
        $teachers = Teacher::query()->whereKey($teacherIds)->where('is_active', true)->get()->sortBy(fn (Teacher $teacher): int => (int) array_search($teacher->getKey(), $teacherIds, true));

        if ($teachers->isEmpty()) {
            return false;
        }

        $this->validator->forget();
        $results = $this->validator->checkTeachers($group, $teachers);

        foreach ($teachers as $teacher) {
            if ($results[$teacher->getKey()]->hasHard()) {
                continue;
            }

            try {
                app(AssignTeacher::class)->handle($group->fresh(), $teacher, $head, $reason);

                return true;
            } catch (ValidationException) {
                continue;
            } finally {
                $this->validator->forget();
            }
        }

        return false;
    }

    /**
     * Justifica cada grupo activo al que le falte docente o salón, como el jefe antes de cerrar (HC-15).
     */
    private function justifyMissing(AcademicLoad $load, User $head): void
    {
        $this->groups($load)
            ->filter(fn (Group $group): bool => $group->close_justification === null && ($group->teacher_id === null || $group->sessions->contains(
                fn (GroupSession $session): bool => $session->room_id === null && $session->kind !== SessionKind::Virtual,
            )))
            ->each(fn (Group $group) => app(JustifyGroup::class)->handle($group, 'Se asigna antes del inicio de clases.', $head));
    }

    private function recompute(Term $term, Modality $modality): void
    {
        $this->validator->forget();
        app(SyncScheduleConflicts::class)->handle($term, $modality);
        app(RefreshTeacherTermLoads::class)->handle($term);
        $this->validator->forget();
    }

    /**
     * Los grupos activos de la carga, con sus sesiones, por id.
     *
     * @return Collection<int, Group>
     */
    private function groups(AcademicLoad $load): Collection
    {
        return Group::query()
            ->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)->where('department_id', $load->department_id)
            ->active()
            ->with('sessions')
            ->orderBy('id')
            ->get();
    }
}
