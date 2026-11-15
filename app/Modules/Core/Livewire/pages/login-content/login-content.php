<?php

use App\Modules\Core\Actions\CreateLoginNotice;
use App\Modules\Core\Actions\CreateLoginShortcut;
use App\Modules\Core\Actions\DeleteLoginNotice;
use App\Modules\Core\Actions\DeleteLoginShortcut;
use App\Modules\Core\Actions\MoveLoginNotice;
use App\Modules\Core\Actions\MoveLoginShortcut;
use App\Modules\Core\Actions\SetLoginNoticeActive;
use App\Modules\Core\Actions\SetLoginShortcutActive;
use App\Modules\Core\Actions\UpdateLoginNotice;
use App\Modules\Core\Actions\UpdateLoginShortcut;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Queries\LoginContentQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

/**
 * «Avisos y atajos» de la pantalla de acceso (CF-S6): dos pestañas, Avisos (el carrusel, LG-01) y Atajos (el botón sobre la
 * foto, LG-02), cada una una lista de catálogo con alta, edición en un drawer, orden, activar o desactivar y borrar
 * (borrado lógico). Cada fila dice su vigencia y si hoy se ve en la pantalla de acceso, con la misma regla de LG-01 y LG-02.
 *
 * El componente lee con LoginContentQuery, autoriza con LoginNoticePolicy y LoginShortcutPolicy en mount() y en cada
 * método público, y llama a las Actions, que validan (enlaces solo http(s), fin después del inicio, imagen jpg, png o webp
 * de hasta 5 MB y pdf de hasta 10 MB). Un solo drawer abre a la vez, así que el formulario comparte propiedades con los
 * nombres que las Actions usan en sus errores.
 */
new #[Title('Avisos y atajos')] class extends Component
{
    use Toast, WithFileUploads;

    /** 'notices' o 'shortcuts'. */
    #[Session]
    public string $tab = 'notices';

    #[Session]
    public string $search = '';

    /** '' (todos), 'active' o 'inactive'. */
    #[Session]
    public string $status = '';

    public bool $showDrawer = false;

    /** El aviso o atajo que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $title = '';

    public string $link_url = '';

    public string $starts_at = '';

    public string $ends_at = '';

    public $image = null;

    public bool $remove_image = false;

    public string $kind = 'enlace';

    public string $description = '';

    public string $url = '';

    public $file = null;

    /** La foto o el archivo que ya tiene lo que se edita, para mostrarlo (solo lectura). */
    #[Locked]
    public ?string $currentFile = null;

    public function mount(): void
    {
        $this->ensureTab();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(LoginContentQuery $content): array
    {
        $this->ensureTab();

        $active = match ($this->status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
        $now = now();

        return [
            'tabs' => array_values(array_filter([
                $this->canSeeNotices() ? ['key' => 'notices', 'label' => 'Avisos'] : null,
                $this->canSeeShortcuts() ? ['key' => 'shortcuts', 'label' => 'Atajos'] : null,
            ])),
            'items' => $this->tab === 'notices' ? $content->notices($this->search, $active) : $content->shortcuts($this->search, $active),
            'total' => $this->tab === 'notices' ? $content->noticeCount() : $content->shortcutCount(),
            'canManage' => $this->canManage(),
            'now' => $now,
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            'kinds' => [['id' => 'enlace', 'name' => 'Enlace'], ['id' => 'descarga', 'name' => 'Descarga de un pdf']],
        ];
    }

    public function updatedTab(): void
    {
        $this->ensureTab();
        $this->reset('search', 'status');
    }

    public function create(): void
    {
        $this->ensureTab();
        abort_unless($this->canManage(), 403);

        $this->resetForm();
        $this->showDrawer = true;
    }

    public function edit(string $id, LoginContentQuery $content): void
    {
        $this->ensureTab();

        if ($this->tab === 'notices') {
            $notice = $content->findNotice($id);
            $this->authorize('update', $notice);

            $this->resetForm();
            $this->editingId = $notice->id;
            $this->title = $notice->title;
            $this->link_url = (string) $notice->link_url;
            $this->starts_at = $this->forInput($notice->starts_at);
            $this->ends_at = $this->forInput($notice->ends_at);
            $this->currentFile = $notice->image_path;
        } else {
            $shortcut = $content->findShortcut($id);
            $this->authorize('update', $shortcut);

            $this->resetForm();
            $this->editingId = $shortcut->id;
            $this->kind = $shortcut->kind->value;
            $this->title = $shortcut->title;
            $this->description = (string) $shortcut->description;
            $this->url = (string) $shortcut->url;
            $this->starts_at = $this->forInput($shortcut->starts_at);
            $this->ends_at = $this->forInput($shortcut->ends_at);
            $this->currentFile = $shortcut->file_path;
        }

        $this->showDrawer = true;
    }

    public function save(
        CreateLoginNotice $createNotice,
        UpdateLoginNotice $updateNotice,
        CreateLoginShortcut $createShortcut,
        UpdateLoginShortcut $updateShortcut,
        LoginContentQuery $content,
    ): void {
        $this->ensureTab();

        if ($this->tab === 'notices') {
            $data = [
                'title' => $this->title,
                'link_url' => $this->link_url,
                'starts_at' => $this->starts_at,
                'ends_at' => $this->ends_at,
                'image' => $this->image,
                'remove_image' => $this->remove_image,
            ];

            if ($this->editingId === null) {
                $this->authorize('create', LoginNotice::class);
                $notice = $createNotice->handle($data);
                $this->success('Aviso creado', e($notice->title));
            } else {
                $notice = $content->findNotice($this->editingId);
                $this->authorize('update', $notice);
                $notice = $updateNotice->handle($notice, $data);
                $this->success('Aviso actualizado', e($notice->title));
            }
        } else {
            $data = [
                'kind' => $this->kind,
                'title' => $this->title,
                'description' => $this->description,
                'url' => $this->url,
                'starts_at' => $this->starts_at,
                'ends_at' => $this->ends_at,
                'file' => $this->file,
            ];

            if ($this->editingId === null) {
                $this->authorize('create', LoginShortcut::class);
                $shortcut = $createShortcut->handle($data);
                $this->success('Atajo creado', e($shortcut->title));
            } else {
                $shortcut = $content->findShortcut($this->editingId);
                $this->authorize('update', $shortcut);
                $shortcut = $updateShortcut->handle($shortcut, $data);
                $this->success('Atajo actualizado', e($shortcut->title));
            }
        }

        $this->showDrawer = false;
    }

    public function toggleActive(string $id, LoginContentQuery $content, SetLoginNoticeActive $notices, SetLoginShortcutActive $shortcuts): void
    {
        $this->ensureTab();

        if ($this->tab === 'notices') {
            $notice = $content->findNotice($id);
            $this->authorize('update', $notice);
            $notice = $notices->handle($notice, ! $notice->is_active);
            $this->success($notice->is_active ? 'Aviso activado' : 'Aviso desactivado', e($notice->title));
        } else {
            $shortcut = $content->findShortcut($id);
            $this->authorize('update', $shortcut);
            $shortcut = $shortcuts->handle($shortcut, ! $shortcut->is_active);
            $this->success($shortcut->is_active ? 'Atajo activado' : 'Atajo desactivado', e($shortcut->title));
        }
    }

    /**
     * @param  int  $direction  -1 sube un lugar, 1 baja uno
     */
    public function move(string $id, int $direction, LoginContentQuery $content, MoveLoginNotice $notices, MoveLoginShortcut $shortcuts): void
    {
        $this->ensureTab();

        if ($this->tab === 'notices') {
            $notice = $content->findNotice($id);
            $this->authorize('update', $notice);
            $notices->handle($notice, $direction);
        } else {
            $shortcut = $content->findShortcut($id);
            $this->authorize('update', $shortcut);
            $shortcuts->handle($shortcut, $direction);
        }
    }

    public function delete(string $id, LoginContentQuery $content, DeleteLoginNotice $notices, DeleteLoginShortcut $shortcuts): void
    {
        $this->ensureTab();

        if ($this->tab === 'notices') {
            $notice = $content->findNotice($id);
            $this->authorize('delete', $notice);
            $notices->handle($notice);
            $this->success('Aviso borrado', e($notice->title));
        } else {
            $shortcut = $content->findShortcut($id);
            $this->authorize('delete', $shortcut);
            $shortcuts->handle($shortcut);
            $this->success('Atajo borrado', e($shortcut->title));
        }
    }

    /**
     * La URL pública de la foto o el archivo que ya tiene lo que se edita.
     */
    public function currentUrl(): ?string
    {
        return $this->currentFile === null ? null : Storage::disk('public')->url($this->currentFile);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'title' => 'título', 'link_url' => 'enlace', 'url' => 'enlace', 'starts_at' => 'inicio', 'ends_at' => 'fin',
            'image' => 'imagen', 'file' => 'archivo', 'description' => 'descripción', 'kind' => 'tipo',
        ];
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'title', 'link_url', 'starts_at', 'ends_at', 'image', 'remove_image', 'kind', 'description', 'url', 'file', 'currentFile');
    }

    private function forInput(?DateTimeInterface $moment): string
    {
        return $moment === null ? '' : $moment->format('Y-m-d\TH:i');
    }

    private function canSeeNotices(): bool
    {
        return Gate::allows('viewAny', LoginNotice::class);
    }

    private function canSeeShortcuts(): bool
    {
        return Gate::allows('viewAny', LoginShortcut::class);
    }

    private function tabAllowed(string $tab): bool
    {
        return match ($tab) {
            'notices' => $this->canSeeNotices(),
            'shortcuts' => $this->canSeeShortcuts(),
            default => false,
        };
    }

    /**
     * Una petición puede llegar con una pestaña a la que el usuario ya no entra: vuelve a la que sí.
     */
    private function ensureTab(): void
    {
        abort_unless($this->canSeeNotices() || $this->canSeeShortcuts(), 403);

        if (! $this->tabAllowed($this->tab)) {
            $this->tab = $this->canSeeNotices() ? 'notices' : 'shortcuts';
        }
    }

    private function canManage(): bool
    {
        return $this->tab === 'notices'
            ? Gate::allows('create', LoginNotice::class)
            : Gate::allows('create', LoginShortcut::class);
    }
};
