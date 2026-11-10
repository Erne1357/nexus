<?php

namespace App\Modules\Imports;

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Imports\Console\ExportDemoFiles;
use App\Modules\Imports\Console\MigrateSii;
use App\Modules\Imports\Enums\ImportsPermission;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\Steps\ArchiveStep;
use App\Modules\Imports\Sii\Steps\CareersStep;
use App\Modules\Imports\Sii\Steps\KardexStep;
use App\Modules\Imports\Sii\Steps\OrgChartStep;
use App\Modules\Imports\Sii\Steps\PositionsStep;
use App\Modules\Imports\Sii\Steps\RoomsStep;
use App\Modules\Imports\Sii\Steps\SeriationStep;
use App\Modules\Imports\Sii\Steps\StudentsStep;
use App\Modules\Imports\Sii\Steps\SubjectsStep;
use App\Modules\Imports\Sii\Steps\TeacherHistoryStep;
use App\Modules\Imports\Sii\Steps\TeachersStep;
use App\Modules\Imports\Sii\Steps\VerificationStep;
use App\Modules\Imports\Templates\CareersTemplate;
use App\Modules\Imports\Templates\DepartmentsTemplate;
use App\Modules\Imports\Templates\EvaluationsTemplate;
use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\KardexTemplate;
use App\Modules\Imports\Templates\OrgUnitsTemplate;
use App\Modules\Imports\Templates\PendingPlacementsTemplate;
use App\Modules\Imports\Templates\PendingPrerequisitesTemplate;
use App\Modules\Imports\Templates\PositionsTemplate;
use App\Modules\Imports\Templates\PrerequisitesTemplate;
use App\Modules\Imports\Templates\RoomsTemplate;
use App\Modules\Imports\Templates\StudentsTemplate;
use App\Modules\Imports\Templates\StudyPlansTemplate;
use App\Modules\Imports\Templates\SubjectAliasesTemplate;
use App\Modules\Imports\Templates\SubjectsTemplate;
use App\Modules\Imports\Templates\TeacherHistoryTemplate;
use App\Modules\Imports\Templates\TeachersTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Contracts\Foundation\Application;

final class ImportsServiceProvider extends ModuleServiceProvider
{
    /**
     * Plantillas de importación, una por catálogo, todas en Templates/. La tarea que escribe una
     * plantilla la agrega aquí.
     *
     * @var list<class-string<ImportTemplate>>
     */
    private const array TEMPLATES = [
        DepartmentsTemplate::class,
        OrgUnitsTemplate::class,
        CareersTemplate::class,
        StudyPlansTemplate::class,
        SubjectsTemplate::class,
        PendingPlacementsTemplate::class,
        SubjectAliasesTemplate::class,
        PrerequisitesTemplate::class,
        PendingPrerequisitesTemplate::class,
        StudentsTemplate::class,
        KardexTemplate::class,
        TeachersTemplate::class,
        PositionsTemplate::class,
        TeacherHistoryTemplate::class,
        EvaluationsTemplate::class,
        RoomsTemplate::class,
    ];

    /**
     * Pasos de la migración del SII, en el orden en que corren. Cada tarea que escribe un paso lo agrega aquí;
     * las pruebas los sustituyen ligando `sii.steps`. El archivo del SII original va primero, para que quede guardado
     * aunque un paso posterior se detenga, y la verificación contra ese original va al final, cuando ya se aplicó todo.
     *
     * @var list<class-string<SiiStep>>
     */
    public const array SII_STEPS = [
        ArchiveStep::class,
        OrgChartStep::class,
        CareersStep::class,
        SubjectsStep::class,
        SeriationStep::class,
        RoomsStep::class,
        TeachersStep::class,
        PositionsStep::class,
        TeacherHistoryStep::class,
        StudentsStep::class,
        KardexStep::class,
        VerificationStep::class,
    ];

    public function register(): void
    {
        // Singleton porque es inmutable: guarda clases, y cada get() crea una plantilla nueva.
        $this->app->singleton(TemplateRegistry::class, fn (): TemplateRegistry => new TemplateRegistry(self::TEMPLATES));
    }

    public function permissions(): array
    {
        return [ImportsPermission::class];
    }

    public function navigation(): array
    {
        return [
            new NavigationItem('Importaciones', 'imports.wizard', 'o-arrow-up-tray', NavigationArea::Datos,
                ability: 'viewAny', arguments: [ImportBatch::class]),
        ];
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ExportDemoFiles::class, MigrateSii::class]);
        }

        // Como Navigation: se arma al arrancar, antes de la primera petición, para que Octane lo cree
        // una vez por worker y una plantilla mal declarada falle al arrancar y no al subir un lote.
        $this->app->booted(fn (Application $app): TemplateRegistry => $app->make(TemplateRegistry::class));
    }
}
