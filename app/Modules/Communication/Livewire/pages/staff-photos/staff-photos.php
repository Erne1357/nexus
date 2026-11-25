<?php

use App\Modules\Communication\Actions\StorePhotoBatch;
use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Queries\StaffPhotosQuery;
use App\Modules\Core\Actions\RemoveStaffPhoto;
use App\Modules\Core\Actions\SetStaffPhoto;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\StaffPhotoUrl;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * «Fotos del personal» de Comunicación y Difusión (CO-03, `/comunicacion/fotos-personal`): el personal en una tabla con
 * su foto, buscado por nombre o usuario y filtrado a «Sin foto» o «Con foto». «Acciones» de cada renglón abre un drawer
 * con la foto actual, la subida, la vista previa del recorte 3:4 (centrado y pegado arriba, el mismo del servidor) y
 * «Quitar foto»; «Subir ZIP» abre otro drawer para un lote de hasta 19 MB cuyos archivos se llaman `<usuario>.<ext>`.
 * El lote lo procesa la cola (ProcessPhotoBatch) y la pantalla sondea cada 2 segundos mientras está en cola o procesando.
 *
 * Escriben SetStaffPhoto, RemoveStaffPhoto y StorePhotoBatch. Autoriza en mount() y en cada método con
 * UserPolicy::managePhotos (`core.staff_photo.manage`): la jefa y el personal de Comunicación y el admin.
 */
new #[Title('Fotos del personal')] class extends Component
{
    use Toast, WithFileUploads, WithPagination;

    #[Session]
    public string $search = '';

    /** '' (con y sin foto), 'sin' o 'con'. */
    #[Session]
    public string $photoFilter = '';

    public bool $showPhoto = false;

    /** La persona del drawer de la foto. El navegador no la puede cambiar. */
    #[Locked]
    public ?string $personId = null;

    public $photo = null;

    /** Pidió «Quitar foto» y falta que lo confirme. */
    public bool $confirmRemove = false;

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
    public function with(StaffPhotosQuery $query): array
    {
        $this->authorizeManage();

        $people = $query->page($this->search, $this->photoFilter);
        $person = $this->showPhoto && $this->personId !== null ? $query->findOrFail($this->personId) : null;
        $batch = $this->showZip && $this->batchId !== null ? $query->batch($this->batchId) : null;

        return [
            'people' => $people,
            'photoUrls' => $people->getCollection()->mapWithKeys(fn (User $user): array => [$user->id => StaffPhotoUrl::for($user, mini: true)])->all(),
            'person' => $person,
            'personPhoto' => $person === null ? null : StaffPhotoUrl::for($person),
            'preview' => $this->showPhoto && $this->photo !== null && $this->photo->isPreviewable() ? $this->photo->temporaryUrl() : null,
            'batch' => $batch,
            'recentBatches' => $this->showZip && $batch === null ? $query->recentBatches() : collect(),
            'photoFilters' => [['id' => 'sin', 'name' => 'Sin foto'], ['id' => 'con', 'name' => 'Con foto']],
        ];
    }

    public function updated(string $property): void
    {
        $this->authorizeManage();

        if (in_array($property, ['search', 'photoFilter'], true)) {
            $this->resetPage();
        } elseif ($property === 'showPhoto' && ! $this->showPhoto) {
            $this->forgetPhoto();
        } elseif ($property === 'showZip' && ! $this->showZip) {
            $this->forgetZip();
        }
    }

    public function openPhoto(string $id, StaffPhotosQuery $query): void
    {
        $this->authorizeManage();

        $this->forgetPhoto();
        $this->personId = $query->findOrFail($id)->id;
        $this->showPhoto = true;
    }

    /**
     * «Quitar foto» del menú de un renglón: abre el drawer de la persona ya pidiendo la confirmación.
     */
    public function openRemove(string $id, StaffPhotosQuery $query): void
    {
        $this->openPhoto($id, $query);
        $this->confirmRemove = true;
    }

    public function closePhoto(): void
    {
        $this->authorizeManage();

        $this->forgetPhoto();
    }

    public function savePhoto(SetStaffPhoto $setStaffPhoto, StaffPhotosQuery $query): void
    {
        $this->authorizeManage();

        $this->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240']], [
            'photo.required' => 'Elige la foto.',
            'photo.mimes' => 'Solo jpg, png o webp.',
            'photo.max' => 'La foto pesa más de 10 MB.',
            'photo.uploaded' => 'No se pudo subir la foto. Revisa que pese menos de 10 MB.',
        ]);

        $person = $query->findOrFail((string) $this->personId);
        $setStaffPhoto->handle($person, $this->photo, auth()->user());

        $this->success('Foto guardada', e($person->name));
        $this->forgetPhoto();
    }

    public function askRemove(): void
    {
        $this->authorizeManage();

        $this->confirmRemove = true;
    }

    public function removePhoto(RemoveStaffPhoto $removeStaffPhoto, StaffPhotosQuery $query): void
    {
        $this->authorizeManage();

        $person = $query->findOrFail((string) $this->personId);
        $removeStaffPhoto->handle($person, auth()->user());

        $this->success('Foto quitada', e("{$person->name}: el archivo se conserva."));
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

        $this->validate(['zip' => ['required', 'file', 'mimes:zip', 'max:19456']], [
            'zip.required' => 'Elige el ZIP.',
            'zip.mimes' => 'Solo un archivo ZIP.',
            'zip.max' => 'El ZIP pesa más de 19 MB. Súbelo en varios lotes.',
            'zip.uploaded' => 'No se pudo subir el ZIP. Revisa que pese menos de 19 MB.',
        ]);

        $batch = $storePhotoBatch->handle(PhotoBatchKind::Personal, $this->zip, auth()->user());

        $this->batchId = $batch->id;
        $this->reset('zip');
    }

    public function showBatch(string $id, StaffPhotosQuery $query): void
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
        $this->reset('showPhoto', 'personId', 'photo', 'confirmRemove');
    }

    private function forgetZip(): void
    {
        $this->resetValidation();
        $this->reset('showZip', 'zip', 'batchId');
    }

    /**
     * CO-03: quien sube y quita las fotos del personal; 403 si no.
     */
    private function authorizeManage(): void
    {
        $this->authorize('managePhotos', User::class);
    }
};
