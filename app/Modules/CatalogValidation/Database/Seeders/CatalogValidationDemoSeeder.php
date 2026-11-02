<?php

namespace App\Modules\CatalogValidation\Database\Seeders;

use App\Modules\Academic\Actions\CreateSubject;
use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\FlagRecord;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Actions\ResolveFlag;
use App\Modules\CatalogValidation\Actions\StartReview;
use App\Modules\CatalogValidation\Actions\ValidateReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Facilities\Actions\CreateRoom;
use App\Modules\Facilities\Actions\UpdateRoom;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Actions\CreateTeacher;
use App\Modules\Faculty\Actions\SetTeacherActive;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * Validación de catálogos demo y masiva (tareas 2.4 a 2.6), para revisar sus pantallas con volumen. Trabaja sobre los
 * periodos abiertos (term_openings) en orden cronológico, después de que OpenTerm creó sus revisiones; sin periodos
 * abiertos o sin revisiones, no hace nada.
 *
 * - Los periodos abiertos anteriores al último se validan completos con ValidateReview y el jefe de cada departamento,
 *   para que quede su foto. Luego se simulan los cambios hacia el último con las Actions de cada módulo (docentes y
 *   salones desactivados, reactivados y nuevos, materias nuevas): son las altas y bajas de `summary()`.
 * - El último periodo queda con estados fijos por código de departamento, de los que depende el seeder de propuestas:
 *   CB, SC e II todo validado; EE con las generales validadas y los salones de C2 en revisión (los de los demás campus
 *   validados), así que HC-14 bloquea solo en C2; CEA con docentes y materias validados y lo demás pendiente; MM con
 *   docentes y carreras validados y reabiertos (en revisión), materias validadas y lo demás pendiente. Cualquier otro
 *   departamento queda pendiente.
 * - Observaciones con FlagRecord de todos los tipos que admite cada catálogo, y algunas resueltas con ResolveFlag;
 *   recordatorios a EE, CEA y MM para el «último recordatorio» del tablero.
 *
 * `$profile`: `demo` (pocos cambios y observaciones) o `massive`. Sin cola: los avisos se mandan en línea. Idempotente:
 * un departamento cuyas revisiones del último periodo ya no están todas pendientes no se vuelve a tocar, las fotos
 * solo se toman una vez y un recordatorio por departamento y periodo. Determinista, sin azar. No corre en producción.
 */
class CatalogValidationDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** @var array<string, array{deactivate: int, create: int, reactivate: int, subjects: int, rooms: int, flags: int, calm: int}> */
    private const array PROFILES = [
        'demo' => ['deactivate' => 2, 'create' => 2, 'reactivate' => 1, 'subjects' => 2, 'rooms' => 1, 'flags' => 4, 'calm' => 1],
        'massive' => ['deactivate' => 6, 'create' => 5, 'reactivate' => 2, 'subjects' => 4, 'rooms' => 3, 'flags' => 24, 'calm' => 3],
    ];

    /** Departamentos que reciben recordatorio, donde algo falta. */
    private const array REMINDED = ['CEA', 'EE', 'MM'];

    private const array COMMENTS = [
        'inactive' => ['Ya no imparte en el departamento desde el semestre pasado.', 'Está dado de baja en el SII; debe desactivarse.', 'Dejó de usarse tras la remodelación del edificio.', 'La materia salió del plan vigente.'],
        'wrong_capacity' => ['Caben 40 con las bancas actuales, no 30.', 'La capacidad real es de 25 por el equipo instalado.', 'Se retiraron mesas; la capacidad bajó a 20.'],
        'duplicate' => ['Aparece dos veces con otra clave.', 'Es el mismo registro que otro del catálogo.', 'Duplicado por la importación del SII.'],
        'missing_data' => ['Falta el correo institucional.', 'No tiene horas de práctica capturadas.', 'Falta el grado académico.', 'Sin tipo ni descripción.'],
        'other' => ['Confirmar el nombre con Servicios Escolares.', 'Revisar con la coordinación de la carrera.', 'La clave no sigue el formato del plan.', 'Pendiente de acuerdo con el jefe de proyecto docente.'],
    ];

    /** @var array<string, User> */
    private array $heads = [];

    public function __construct(
        private readonly CatalogContentsQuery $contents,
        private readonly ValidateReview $validate,
        private readonly ReopenReview $reopen,
        private readonly StartReview $start,
        private readonly FlagRecord $flag,
        private readonly ResolveFlag $resolve,
        private readonly RemindPendingReviews $remind,
        private readonly RecipientsQuery $recipients,
    ) {}

    /**
     * @param  string  $profile  `demo` o `massive`
     *
     * @throws RuntimeException en producción o con un perfil que no existe
     */
    public function run(string $profile = 'demo'): void
    {
        $this->refuseProduction();

        $config = self::PROFILES[$profile] ?? throw new RuntimeException("Perfil desconocido: {$profile}.");
        $terms = Term::query()->whereIn('id', DB::table('term_openings')->select('term_id'))->orderBy('starts_on')->orderBy('code')->get();

        if ($terms->isEmpty()) {
            return;
        }

        $queue = config('queue.default');
        config(['queue.default' => 'sync']);
        $started = hrtime(true);

        try {
            $current = $terms->last();
            $changed = false;

            foreach ($terms->slice(0, -1) as $previous) {
                $changed = $this->validateAll($previous) || $changed;
            }

            if ($changed) {
                $this->simulateChanges($current, $config);
            }

            $this->seedCurrent($current, $config);
            $this->remindDepartments($current);
        } finally {
            config(['queue.default' => $queue]);
        }

        $this->command?->info(sprintf('Validación de catálogos (%s): %.1f s.', $profile, (hrtime(true) - $started) / 1e9));
    }

    /**
     * Valida las revisiones que falten de un periodo anterior. Da si validó alguna.
     */
    private function validateAll(Term $term): bool
    {
        $any = false;

        foreach (CatalogReview::query()->where('term_id', $term->getKey())->where('status', '!=', ReviewStatus::Validated)->with(['department', 'campus'])->get() as $review) {
            $this->validate->handle($review, $this->headOf($review));
            $any = true;
        }

        return $any;
    }

    /**
     * Los cambios entre periodos, con las Actions de cada módulo.
     *
     * @param  array{deactivate: int, create: int, reactivate: int, subjects: int, rooms: int, flags: int, calm: int}  $config
     */
    private function simulateChanges(Term $term, array $config): void
    {
        $campuses = $this->openedCampuses($term);
        $departments = Department::query()->where('is_active', true)->orderBy('code')->get();

        foreach ($departments as $index => $department) {
            $teachers = Teacher::query()->whereHas('adscriptions', fn ($query) => $query->where('department_id', $department->getKey()))->orderBy('employee_number');

            foreach ((clone $teachers)->where('is_active', true)->limit($config['deactivate'])->get() as $teacher) {
                app(SetTeacherActive::class)->handle($teacher, false);
            }

            foreach ((clone $teachers)->where('is_active', false)->limit($config['reactivate'])->get() as $teacher) {
                app(SetTeacherActive::class)->handle($teacher, true);
            }

            for ($n = 1; $n <= $config['create']; $n++) {
                app(CreateTeacher::class)->handle([
                    'employee_number' => $this->freeCode(fn (string $code): bool => Teacher::withTrashed()->where('employee_number', $code)->exists(), sprintf('DN%02d%02d', $index + 1, $n)),
                    'name' => "Docente Nuevo {$department->code} {$n}",
                    'email' => strtolower("dn{$department->code}{$n}").'@example.test',
                ], $department);
            }

            for ($n = 1; $n <= $config['subjects']; $n++) {
                app(CreateSubject::class)->handle([
                    'code' => $this->freeCode(fn (string $code): bool => DB::table('subjects')->where('code', $code)->exists(), "NV{$department->code}{$n}"),
                    'name' => "Temas selectos de {$department->name} {$n}",
                    'department_id' => $department->getKey(),
                    'theory_hours' => 3,
                    'practice_hours' => 2,
                    'credits' => 5,
                ]);
            }

            foreach ($campuses as $campus) {
                $this->changeRooms($campus, $department, $config);
            }
        }
    }

    /**
     * @param  array{deactivate: int, create: int, reactivate: int, subjects: int, rooms: int, flags: int, calm: int}  $config
     */
    private function changeRooms(Campus $campus, Department $department, array $config): void
    {
        $rooms = Room::query()->where('campus_id', $campus->getKey())->where('department_id', $department->getKey())->orderBy('code');

        foreach ((clone $rooms)->where('is_active', true)->limit($config['rooms'])->get() as $room) {
            app(UpdateRoom::class)->handle($room, ['is_active' => false]);
        }

        foreach ((clone $rooms)->where('is_active', false)->limit(1)->get() as $room) {
            app(UpdateRoom::class)->handle($room, ['is_active' => true]);
        }

        $building = Building::query()->where('campus_id', $campus->getKey())->where('is_active', true)->orderBy('code')->first();

        if ($building === null) {
            return;
        }

        for ($n = 1; $n <= $config['rooms']; $n++) {
            app(CreateRoom::class)->handle($campus, [
                'building_id' => $building->getKey(),
                'code' => $this->freeCode(fn (string $code): bool => Room::withTrashed()->where('campus_id', $campus->getKey())->where('code', $code)->exists(), "N{$department->code}{$n}"),
                'type' => RoomType::Aula->value,
                'capacity' => 28 + $n,
                'department_id' => $department->getKey(),
            ]);
        }
    }

    /**
     * @param  callable(string): bool  $taken
     */
    private function freeCode(callable $taken, string $code): string
    {
        $candidate = $code;

        for ($suffix = 2; $taken($candidate); $suffix++) {
            $candidate = $code.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * Los estados fijos del periodo actual, por departamento.
     *
     * @param  array{deactivate: int, create: int, reactivate: int, subjects: int, rooms: int, flags: int, calm: int}  $config
     */
    private function seedCurrent(Term $term, array $config): void
    {
        $reviews = CatalogReview::query()->where('term_id', $term->getKey())->with(['department', 'campus'])->get()->groupBy('department_id');

        foreach ($reviews as $group) {
            if ($group->contains(fn (CatalogReview $review): bool => $review->status !== ReviewStatus::Pending)) {
                continue;
            }

            $code = $group->first()->department->code;
            $order = array_flip(array_map(fn (Catalog $catalog): string => $catalog->value, Catalog::cases()));
            $flags = [];

            foreach ($group->sortBy(fn (CatalogReview $review): string => ($order[$review->catalog->value] ?? 9).$review->campus?->code) as $review) {
                $target = $this->target($code, $review->catalog, $review->campus?->code);
                $actor = $this->headOf($review);

                foreach ($this->flagRecords($review, $target, $config, $actor) as $created) {
                    $flags[] = $created;
                }

                match ($target) {
                    'validated' => $this->validate->handle($review, $actor),
                    'reopened' => $this->reopen->handle($this->validate->handle($review, $actor), $actor),
                    'review' => $this->start->handle($review, $actor),
                    default => null,
                };
            }

            foreach ($flags as $position => $created) {
                if ($position % 4 === 0) {
                    $this->resolve->handle($created->review, $created, $this->headOf($created->review));
                }
            }
        }
    }

    /**
     * El estado final de una revisión: `validated`, `reopened` (validada y reabierta), `review` (en revisión) o
     * `pending`. Fijo por departamento: AcademicLoad depende de él.
     */
    private function target(string $department, Catalog $catalog, ?string $campus): string
    {
        return match ($department) {
            'CB', 'SC', 'II' => 'validated',
            'EE' => $catalog === Catalog::Rooms && $campus === 'C2' ? 'review' : 'validated',
            'CEA' => in_array($catalog, [Catalog::Teachers, Catalog::Subjects], true) ? 'validated' : 'pending',
            'MM' => match ($catalog) {
                Catalog::Teachers, Catalog::Careers => 'reopened',
                Catalog::Subjects => 'validated',
                default => 'pending',
            },
            default => 'pending',
        };
    }

    /**
     * Pone las observaciones de una revisión: muchas donde algo falta, pocas en las validadas, ninguna en las pendientes.
     *
     * @param  array{deactivate: int, create: int, reactivate: int, subjects: int, rooms: int, flags: int, calm: int}  $config
     * @return list<CatalogReviewFlag>
     */
    private function flagRecords(CatalogReview $review, string $target, array $config, User $actor): array
    {
        $count = match ($target) {
            'review', 'reopened' => $config['flags'],
            'validated' => $review->catalog === Catalog::Subjects ? $config['calm'] : 0,
            default => 0,
        };

        if ($review->department->code === 'CEA' && $target === 'validated') {
            $count = intdiv($config['flags'], 2);
        }

        if ($count === 0) {
            return [];
        }

        $records = $this->contents->query($review)->get()->values();
        $types = array_values(array_filter(ReviewFlag::cases(), fn (ReviewFlag $flag): bool => $flag->appliesTo($review->catalog)));
        $created = [];

        for ($i = 0; $i < min($count, $records->count()); $i++) {
            $flag = $types[$i % count($types)];
            $comments = self::COMMENTS[$flag->value];
            $comment = $i % 3 === 2 && $flag !== ReviewFlag::Other ? null : $comments[$i % count($comments)];

            $created[] = $this->flag->handle($review, $records[($i * 7) % $records->count()], $flag, $comment, $actor);
        }

        return $created;
    }

    private function remindDepartments(Term $term): void
    {
        $campuses = $this->openedCampuses($term);
        $actor = User::query()->where('username', 'admin')->first();

        if ($actor === null) {
            return;
        }

        foreach (Department::query()->whereIn('code', self::REMINDED)->where('is_active', true)->orderBy('code')->get() as $department) {
            $done = Activity::query()->where('event', 'reminded')->where('subject_id', $department->getKey())->where('properties->term_id', $term->getKey())->exists();

            if (! $done) {
                $this->remind->handle($term, $campuses, $actor, [$department->getKey()]);
            }
        }
    }

    /**
     * @return Collection<int, Campus>
     */
    private function openedCampuses(Term $term): Collection
    {
        return Campus::query()->whereIn('id', DB::table('term_openings')->where('term_id', $term->getKey())->select('campus_id'))->orderBy('code')->get();
    }

    /**
     * El jefe del departamento de la revisión (en el campus de salones, o en cualquier campus abierto); si no hay,
     * el administrador.
     */
    private function headOf(CatalogReview $review): User
    {
        $key = $review->department_id.'/'.($review->campus_id ?? '');

        return $this->heads[$key] ??= $this->findHead($review);
    }

    private function findHead(CatalogReview $review): User
    {
        $campuses = $review->campus_id === null ? $this->openedCampuses($review->term) : collect([$review->campus]);

        foreach ($campuses as $campus) {
            $head = $this->recipients->for(Role::JefeDepartamento, $campus, $review->department_id)->first();

            if ($head !== null) {
                return $head;
            }
        }

        return User::query()->where('username', 'admin')->firstOrFail();
    }
}
