<?php

use App\Modules\Communication\Actions\StorePhotoBatch;
use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Queries\StudentPhotosQuery;
use App\Modules\Students\Actions\SetStudentPhoto;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Support\StudentPhotos;
use App\Modules\Students\Support\StudentPhotoUrl;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * «Fotos de alumnos» de Comunicación y Difusión (CO-06, `/comunicacion/fotos-alumnos`): los alumnos en una tabla con su
 * foto, buscados por número de control o nombre y filtrados a «Sin foto» o «Con foto» y a «Vigentes». «Acciones» de cada
 * renglón abre un drawer con la foto actual, la subida y la vista previa del recorte 3:4; «Subir ZIP» abre otro drawer
 * para un lote de hasta 19 MB cuyos archivos se llaman `<número de control>.<ext>`. Una foto reemplazada se conserva en
 * `_anteriores/` y no se quita ninguna. El lote lo procesa la cola (ProcessPhotoBatch) y la pantalla sondea cada 2
 * segundos mientras está en cola o procesando.
 *
 * Escriben SetStudentPhoto y StorePhotoBatch. Autoriza en mount() y en cada método con StudentPolicy::managePhotos
 * (`students.photo.manage`): la jefa y el personal de Comunicación y el admin.
 */
new #[Title('Fotos de alumnos')] class extends Component
{
    use Toast, WithFileUploads, WithPagination;

    #[Session]
    public string $search = '';

    /** '' (con y sin foto), 'sin' o 'con'. */
    #[Session]
    public string $photoFilter = '';

    /** Solo los alumnos con credencial vigente (activos y egresados). */
    #[Session]
    public bool $onlyCurrent = false;

    public bool $showPhoto = false;

    /** El alumno del drawer de la foto. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $studentId = null;

    public $photo = null;

    public bool $showZip = false;

    public $zip = null;

    /** El lote que muestra el drawer del ZIP; null es el formulario de subida. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $batchId = null;

    public function mount(): void
    {
        $this->authorizeManage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(StudentPhotosQuery $query, StudentPhotos $photos): array
    {
        $this->authorizeManage();

        $students = $query->page($this->search, $this->photoFilter, $this->onlyCurrent);
        $student = $this->showPhoto && $this->studentId !== null ? $query->findOrFail($this->studentId) : null;
        $batch = $this->showZip && $this->batchId !== null ? $query->batch($this->batchId) : null;

        return [
            'students' => $students,
            'photoUrls' => $students->getCollection()->mapWithKeys(fn (Student $row): array => [$row->id => StudentPhotoUrl::for($row)])->all(),
            'updatedAt' => $students->getCollection()->mapWithKeys(fn (Student $row): array => [$row->id => $photos->modifiedAt($row->control_number)])->all(),
            'student' => $student,
            'studentPhoto' => $student === null ? null : StudentPhotoUrl::for($student),
            'preview' => $this->showPhoto && $this->photo !== null && $this->photo->isPreviewable() ? $this->photo->temporaryUrl() : null,
            'batch' => $batch,
            'recentBatches' => $this->showZip && $batch === null ? $query->recentBatches() : collect(),
            'photoFilters' => [['id' => 'sin', 'name' => 'Sin foto'], ['id' => 'con', 'name' => 'Con foto']],
        ];
    }

    public function updated(string $property): void
    {
        $this->authorizeManage();

        if (in_array($property, ['search', 'photoFilter', 'onlyCurrent'], true)) {
            $this->resetPage();
        } elseif ($property === 'showPhoto' && ! $this->showPhoto) {
            $this->forgetPhoto();
        } elseif ($property === 'showZip' && ! $this->showZip) {
            $this->forgetZip();
        }
    }

    public function openPhoto(string $id, StudentPhotosQuery $query): void
    {
        $this->authorizeManage();

        $this->forgetPhoto();
        $this->studentId = $query->findOrFail($id)->id;
        $this->showPhoto = true;
    }

    public function closePhoto(): void
    {
        $this->authorizeManage();

        $this->forgetPhoto();
    }

    public function savePhoto(SetStudentPhoto $setStudentPhoto, StudentPhotosQuery $query): void
    {
        $this->authorizeManage();

        $this->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240']], [
            'photo.required' => 'Elige la foto.',
            'photo.mimes' => 'Solo jpg, png o webp.',
            'photo.max' => 'La foto pesa más de 10 MB.',
            'photo.uploaded' => 'No se pudo subir la foto. Revisa que pese menos de 10 MB.',
        ]);

        $student = $query->findOrFail((string) $this->studentId);
        $setStudentPhoto->handle($student, $this->photo, auth()->user());

        $this->success('Foto guardada', e($student->name));
        $this->forgetPhoto();
    }

    public function openZip(): void
    {
        $this->authorizeManage();

        $this->forgetZip();
        $this->showZip = true;
    }

    public function closeZip(): void
    {
        $this->authorizeManage();

        $this->forgetZip();
    }

    public function uploadZip(StorePhotoBatch $storePhotoBatch): void
    {
        $this->authorizeManage();

        $this->validate(['zip' => ['required', 'file', 'mimes:zip', 'max:19000']], [
            'zip.required' => 'Elige el ZIP.',
            'zip.mimes' => 'Solo un archivo ZIP.',
            'zip.max' => 'El ZIP pesa más de 19 MB. Súbelo en varios lotes.',
            'zip.uploaded' => 'No se pudo subir el ZIP. Revisa que pese menos de 19 MB.',
        ]);

        $batch = $storePhotoBatch->handle(PhotoBatchKind::Alumnos, $this->zip, auth()->user());

        $this->batchId = $batch->id;
        $this->reset('zip');
    }

    public function showBatch(string $id, StudentPhotosQuery $query): void
    {
        $this->authorizeManage();

        $this->batchId = $query->batch($id)->id;
    }

    public function anotherZip(): void
    {
        $this->authorizeManage();

        $this->forgetZip();
        $this->showZip = true;
    }

    private function forgetPhoto(): void
    {
        $this->resetValidation();
        $this->reset('showPhoto', 'studentId', 'photo');
    }

    private function forgetZip(): void
    {
        $this->resetValidation();
        $this->reset('showZip', 'zip', 'batchId');
    }

    /**
     * CO-06: quien sube las fotos de los alumnos; 403 si no.
     */
    private function authorizeManage(): void
    {
        $this->authorize('managePhotos', Student::class);
    }
};
