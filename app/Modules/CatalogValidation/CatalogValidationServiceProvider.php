<?php

namespace App\Modules\CatalogValidation;

use App\Modules\CatalogValidation\Database\Seeders\CatalogValidationDemoSeeder;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewValidated;
use App\Modules\CatalogValidation\Listeners\CreateReviewsOnTermOpened;
use App\Modules\CatalogValidation\Listeners\CreateRoomsReviewsOnCampusUpdated;
use App\Modules\CatalogValidation\Listeners\InviteTeacherOnInstitutionalEmail;
use App\Modules\CatalogValidation\Listeners\InviteTeachersOnReviewValidated;
use App\Modules\CatalogValidation\Listeners\NotifyPendingReviewsOnTermOpened;
use App\Modules\CatalogValidation\Listeners\StartTeachersReviewOnFacultyCapture;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use Illuminate\Support\Facades\Event;

/**
 * Módulo CatalogValidation: revisión de los catálogos de cada departamento por periodo
 * (docs/dominio/modelo-de-datos.md, "CatalogValidation"). Depende de Imports y de lo que está a su izquierda.
 *
 * Escucha a Core, que no depende de CatalogValidation: al abrir un periodo en un campus crea las revisiones
 * que falten y avisa a los jefes que tienen catálogos por validar, y si un campus empieza a usar salones, las de
 * salones de los periodos abiertos en él. Escucha a Faculty: la captura del jefe sobre un docente suyo inicia la
 * revisión de docentes (CV-S4), y su correo institucional capturado después de validar lo invita a activar su acceso.
 * Validar la revisión de docentes invita a los docentes del departamento (AC-09).
 */
final class CatalogValidationServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, CreateReviewsOnTermOpened::class);
        Event::listen(TermOpened::class, NotifyPendingReviewsOnTermOpened::class);
        Event::listen(CampusUpdated::class, CreateRoomsReviewsOnCampusUpdated::class);

        Event::listen(CatalogReviewValidated::class, InviteTeachersOnReviewValidated::class);
        Event::listen(TeacherUpdated::class, InviteTeacherOnInstitutionalEmail::class);

        foreach ([AvailabilityChanged::class, QualificationChanged::class, ExternalLoadChanged::class, TeacherUpdated::class, AdscriptionChanged::class, PositionChanged::class] as $event) {
            Event::listen($event, StartTeachersReviewOnFacultyCapture::class);
        }
    }

    /**
     * «Validar catálogos», en Académico, para quien revisa catálogos: el jefe de departamento y Subdirección (que, igual
     * que el admin, la abre en lectura). Que el departamento sea el suyo lo decide la pantalla.
     * «Avance de validación», para Subdirección y el admin (tarea 2.5; `has()` incluye al admin).
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Validar catálogos', 'catalog-validation.review', 'o-clipboard-document-check', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(CatalogValidationPermission::ReviewView)),
            new NavigationItem('Avance de validación', 'catalog-validation.progress', 'o-chart-bar-square', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(CatalogValidationPermission::ProgressView)),
        ];
    }

    public function permissions(): array
    {
        return [CatalogValidationPermission::class];
    }

    /**
     * Subdirección: el avance, recordar, y ver y resolver observaciones en todo; el jefe de departamento, ver, resolver
     * y, de su departamento, corregir, validar, reabrir y observar. La condición del campus para corregir salones
     * (CV2) la pone la Policy.
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                CatalogValidationPermission::ProgressView,
                CatalogValidationPermission::ProgressRemind,
                CatalogValidationPermission::ReviewView,
                CatalogValidationPermission::ReviewResolveFlag,
            ],
            Role::JefeDepartamento->value => [
                CatalogValidationPermission::ReviewView,
                CatalogValidationPermission::ReviewResolveFlag,
                CatalogValidationPermission::ReviewCorrect,
                CatalogValidationPermission::ReviewValidate,
                CatalogValidationPermission::ReviewReopen,
                CatalogValidationPermission::ReviewFlag,
            ],
        ];
    }

    /**
     * Corregir, validar, reabrir y observar son solo del jefe que cubre el departamento: ni el admin ni
     * Subdirección (CV-S1).
     */
    public function adminDenies(): array
    {
        return [
            CatalogValidationPermission::ReviewCorrect,
            CatalogValidationPermission::ReviewValidate,
            CatalogValidationPermission::ReviewReopen,
            CatalogValidationPermission::ReviewFlag,
        ];
    }

    /**
     * Demo por puntos:
     * - a «validación», en el masivo, Ago-Dic 2026 validado como foto anterior y las altas y bajas hacia Ene-Jun 2027
     *   (sin dar de baja más que aulas); Ene-Jun 2027 queda todo pendiente;
     * - a «propuesta», cada revisión de Ene-Jun 2027 empezada y validada por el jefe de su departamento, sin observaciones.
     */
    public function demoSteps(): array
    {
        $profile = fn (DemoContext $demo): string => $demo->volume === DemoVolume::Massive ? 'massive' : 'demo';

        return [
            new DemoStep(DemoCheckpoint::CatalogValidation, 'Foto del periodo anterior y altas y bajas', fn (DemoContext $demo) => $demo->runSeeder(CatalogValidationDemoSeeder::class, ['profile' => $profile($demo), 'mode' => 'previous'])),
            new DemoStep(DemoCheckpoint::Proposal, 'Catálogos de Ene-Jun 2027 validados por cada jefe', fn (DemoContext $demo) => $demo->runSeeder(CatalogValidationDemoSeeder::class, ['profile' => $profile($demo), 'mode' => 'validated'])),
        ];
    }

    public function demoChecks(): array
    {
        $reviews = fn (string $termCode) => CatalogReview::query()->whereRelation('term', 'code', $termCode);
        $allIn = function (string $termCode, ReviewStatus $status) use ($reviews): true|string {
            $total = $reviews($termCode)->count();
            $other = $reviews($termCode)->where('status', '!=', $status)->count();

            return $total > 0 && $other === 0 ? true : "{$other} de {$total} revisiones de {$termCode} no están en «{$status->label()}».";
        };

        return [
            new DemoCheck([DemoCheckpoint::CatalogValidation], 'Toda revisión de Ene-Jun 2027 pendiente', fn (DemoContext $demo): true|string => $allIn($demo->termCode, ReviewStatus::Pending)),
            new DemoCheck([DemoCheckpoint::CatalogValidation], 'En el masivo, Ago-Dic 2026 validado como foto anterior', fn (DemoContext $demo): true|string => $demo->volume === DemoVolume::Massive
                ? $allIn('20263', ReviewStatus::Validated)
                : true),
            new DemoCheck(DemoCheckpoint::since(DemoCheckpoint::Proposal), 'Toda revisión de Ene-Jun 2027 validada (HC-14)', fn (DemoContext $demo): true|string => $allIn($demo->termCode, ReviewStatus::Validated)),
            new DemoCheck([DemoCheckpoint::Proposal], 'Cada catálogo lo validó un jefe, no el admin (CV-S1)', fn (DemoContext $demo): true|string => ($byAdmin = $reviews($demo->termCode)->where('validated_by', $demo->admin()->getKey())->count()) === 0
                ? true
                : "{$byAdmin} revisiones las validó el admin: a su departamento le falta jefe."),
        ];
    }
}
