<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Students\Actions\SetStudentPhoto;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\CredentialRequestQuery;
use App\Modules\Students\Queries\StudentCredentialQuery;
use App\Modules\Students\Queries\StudentQuery;
use App\Modules\Students\Queries\StudentRecordQuery;
use App\Modules\Students\Support\StudentPhotoUrl;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

/**
 * Ficha del alumno (tarea 1.6): sus datos y cifras, su resumen en tablero (1.67, pestaña por omisión), su retícula por semestre con el estado de cada materia como
 * lo nombra el SII y su kárdex por periodo. Solo lectura. Carga al alumno con StudentQuery (el id llega de la
 * ruta), autoriza `view` con StudentPolicy, arma la ficha con StudentRecordQuery y acomoda su retícula en la
 * cuadrícula de Core (SemesterGrid), con el tronco común arriba y una banda por especialidad. La pestaña «Credencial»
 * (CO-10, tarea 1.37) muestra su foto, el frente y el reverso sin el QR vivo (solo el alumno lo genera) y el historial de
 * solicitudes; quien tiene `students.photo.manage` (Comunicación) cambia la foto desde ahí con SetStudentPhoto.
 */
new #[Title('Ficha del alumno')] class extends Component
{
    use Toast, WithFileUploads;

    /** El alumno de la ficha. El navegador no lo puede cambiar. */
    #[Locked]
    public string $studentId;

    /** 'resumen' (por omisión), 'reticula', 'kardex' o 'credencial'; va en la URL para volver a la misma pestaña, y otro valor muestra el resumen. */
    #[Url]
    public mixed $tab = 'resumen';

    public function mount(string $student, StudentQuery $students): void
    {
        $model = $students->findOrFail($student);
        $this->authorize('view', $model);
        $this->studentId = $model->id;
        $this->updatedTab();
    }

    /**
     * Una pestaña que no existe (un enlace viejo o mal escrito) muestra el resumen.
     */
    public function updatedTab(): void
    {
        $this->tab = is_string($this->tab) && in_array($this->tab, ['reticula', 'kardex', 'credencial'], true) ? $this->tab : 'resumen';
    }

    /**
     * Los enlaces del tablero «Resumen» (students::components.student-summary) abren otra pestaña de la ficha.
     */
    #[On('student-tab')]
    public function openTab(string $tab): void
    {
        $this->tab = $tab;
        $this->updatedTab();
    }

    public bool $showPhoto = false;

    public $photo = null;

    /**
     * CO-06: abre el drawer de la foto; solo con `students.photo.manage`.
     */
    public function openPhoto(): void
    {
        $this->authorize('managePhotos', Student::class);

        $this->resetValidation();
        $this->reset('photo');
        $this->showPhoto = true;
    }

    public function closePhoto(): void
    {
        $this->reset('showPhoto', 'photo');
        $this->resetValidation();
    }

    public function savePhoto(SetStudentPhoto $setStudentPhoto, StudentQuery $students): void
    {
        $this->authorize('managePhotos', Student::class);

        $this->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240']], [
            'photo.required' => 'Elige la foto.',
            'photo.mimes' => 'Solo jpg, png o webp.',
            'photo.max' => 'La foto pesa más de 10 MB.',
            'photo.uploaded' => 'No se pudo subir la foto. Revisa que pese menos de 10 MB.',
        ]);

        $student = $students->findOrFail($this->studentId);
        $setStudentPhoto->handle($student, $this->photo, auth()->user());

        $this->success('Foto guardada', e($student->name));
        $this->closePhoto();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, StudentQuery $students, StudentRecordQuery $records, StudentCredentialQuery $credentials, CredentialRequestQuery $requests): array
    {
        $student = $students->findOrFail($this->studentId);
        $this->authorize('view', $student);
        $credential = $this->tab === 'credencial' ? $credentials->forStaff($context, $student) : null;

        return [
            'record' => $records->forStudent($student),
            'credential' => $credential,
            'requests' => $credential === null ? null : $requests->history($student),
            'photoUrl' => $credential['photo_url'] ?? StudentPhotoUrl::for($student),
            'canManagePhoto' => auth()->user()?->can('managePhotos', Student::class) ?? false,
            'preview' => $this->showPhoto && $this->photo !== null && $this->photo->isPreviewable() ? $this->photo->temporaryUrl() : null,
        ];
    }
};
