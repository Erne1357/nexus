<?php

namespace App\Modules\Students;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\PlanCareerChanging;
use App\Modules\Academic\Events\SubjectDeactivating;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Credentials\CredentialPhotos;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Exceptions\InvalidPhoto;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Notifications\PersonMailAddresses;
use App\Modules\Core\Support\HomePanels;
use App\Modules\Core\Support\PhotoNormalizer;
use App\Modules\Students\Console\SyncSiiSpecialtiesCommand;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Events\StudentPhotoChanged;
use App\Modules\Students\Listeners\BlockPlanCareerChangeWithStudents;
use App\Modules\Students\Listeners\BlockSubjectDeactivationWhileNeeded;
use App\Modules\Students\Listeners\RecomputeProgressOnCurriculumChange;
use App\Modules\Students\Listeners\TouchPhotoRequestsOnPhotoChange;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Rules\CredentialRequestSettings;
use App\Modules\Students\Rules\InstitutionalEmail;
use App\Modules\Students\Support\StudentPhotos;
use App\Modules\Students\Support\StudentPhotoUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Módulo Students: alumnos, su kardex y su avance reticular (docs/dominio/modelo-de-datos.md, "Students").
 * Depende de Academic y Core. Se llena desde Imports: en R1 y R2 es de solo lectura.
 *
 * Escucha a Academic, que no depende de Students: veta desactivar una materia que todavía necesitan
 * alumnos y recalcula el avance cuando cambia la retícula o una materia.
 */
final class StudentsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([SyncSiiSpecialtiesCommand::class]);
        }

        Event::listen(SubjectDeactivating::class, BlockSubjectDeactivationWhileNeeded::class);
        Event::listen(PlanCareerChanging::class, BlockPlanCareerChangeWithStudents::class);
        Event::listen(CurriculumChanged::class, RecomputeProgressOnCurriculumChange::class);
        Event::listen(StudentPhotoChanged::class, TouchPhotoRequestsOnPhotoChange::class);

        // CO-05: la miniatura del alumno para la credencial del login (Core no conoce a Students). Es la foto de su número
        // de control, recortada con GD a la miniatura de 150×200; sin foto o con un archivo ilegible, null.
        $this->app->make(CredentialPhotos::class)->extend(static function (User $user): ?string {
            $control = Student::query()->where('user_id', $user->getKey())->value('control_number');
            $photo = $control === null ? null : app(StudentPhotos::class)->read($control);

            if ($photo === null) {
                return null;
            }

            try {
                return 'data:image/jpeg;base64,'.base64_encode(app(PhotoNormalizer::class)->normalizeBytes($photo['bytes'])['thumb']);
            } catch (InvalidPhoto) {
                return null;
            }
        });

        // CO-04: la foto propia del alumno en la banda (su ruta propia, sin id); sin foto, null y salen las iniciales.
        $this->app->make(CredentialPhotos::class)->extendUrl(static function (User $user): ?string {
            $student = Student::query()->where('user_id', $user->getKey())->first();

            return $student === null ? null : StudentPhotoUrl::own($student);
        });

        // AC-15 (1.67): el Resumen del alumno es el tablero de Students (Core no lo conoce: lo pinta con HomePanels).
        $this->app->make(HomePanels::class)->register('alumno', 'students::components.student-summary');

        // CE-01: el correo institucional del alumno que no lo tiene en su usuario es el que sale de su número de control
        // (AC-13). Core no conoce a Students.
        $this->app->make(PersonMailAddresses::class)->extend(static function (User $user): ?string {
            $control = Student::query()->where('user_id', $user->getKey())->value('control_number');

            return $control === null ? null : InstitutionalEmail::of((string) $control);
        });

        // AC-20: la verificación pública del QR de la credencial. 30 por minuto por dirección y código, y 300 por dirección:
        // detrás del NAT del campus muchos teléfonos comparten dirección.
        RateLimiter::for('credential-verify', fn (Request $request): array => [
            Limit::perMinute(30)->by('code|'.$request->ip().'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute(300)->by('ip|'.$request->ip()),
        ]);
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Mis datos', 'students.my-record', 'o-identification', NavigationArea::MiEspacio,
                visible: static fn (Actor $context): bool => $context->allowsAny(StudentsPermission::MyRecordView)),
            new NavigationItem('Alumnos', 'students.index', 'o-academic-cap', NavigationArea::ServiciosEscolares,
                ability: 'viewAny', arguments: [Student::class], childRoutes: ['students.show']),
        ];
    }

    public function permissions(): array
    {
        return [StudentsPermission::class];
    }

    /**
     * Consultan alumnos la Subdirección, el directivo (AZ-03), los jefes y los coordinadores; el alumno, solo sus datos
     * (AC-15). Las fotos de los alumnos (CO-06) son de la jefa y el personal de Comunicación y Difusión; solo les valen sobre
     * esa unidad (StudentsPermission::appliesTo()).
     */
    public function grants(): array
    {
        return [
            Role::Alumno->value => [StudentsPermission::MyRecordView, StudentsPermission::CredentialRequestCreate],
            Role::Directivo->value => [StudentsPermission::StudentView],
            Role::Subdireccion->value => [StudentsPermission::StudentView],
            Role::JefeDepartamento->value => [StudentsPermission::StudentView, StudentsPermission::CredentialRequestManage, StudentsPermission::PhotoManage],
            Role::Personal->value => [StudentsPermission::CredentialRequestManage, StudentsPermission::PhotoManage],
            Role::Coordinador->value => [StudentsPermission::StudentView],
        ];
    }

    /**
     * Los plazos de las solicitudes de credencial (CO-07), que Comunicación y Difusión fija.
     */
    public function settings(): array
    {
        return CredentialRequestSettings::definitions();
    }

    /**
     * «Mis datos» es del alumno sobre lo suyo: el admin no es alumno, así que no lo ve en el menú.
     */
    public function adminDenies(): array
    {
        return [StudentsPermission::MyRecordView, StudentsPermission::CredentialRequestCreate];
    }

    /**
     * Demo por puntos: los alumnos de cada oferta de carrera, su kárdex y su avance llevan a «catálogos», con los
     * mismos números que DemoSeeder y MassiveSeeder.
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Alumnos, kárdex y avance', fn (DemoContext $demo) => $demo->runSeeder(StudentsDemoSeeder::class, [
                'studentsPerOffering' => $demo->volume === DemoVolume::Massive ? ['C1' => 670, 'C2' => 660] : ['C2' => 35],
                'seed' => $demo->seed,
            ])),
        ];
    }

    public function demoChecks(): array
    {
        return [
            new DemoCheck(DemoCheckpoint::staged(), 'Hay alumnos con kárdex', fn (): true|string => KardexRecord::query()->exists()
                ? true
                : 'No hay renglones de kárdex.'),
        ];
    }

    public function activityAttributes(): array
    {
        return [
            Student::class => ['control_number' => 'Número de control', 'status' => ['Estatus', StudentStatus::class], 'status_reason' => 'Motivo del estatus', 'current_semester' => 'Semestre actual'],
        ];
    }
}
