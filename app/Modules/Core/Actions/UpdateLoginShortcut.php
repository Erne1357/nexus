<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\LoginShortcutKind;
use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Rules\LoginContentRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Throwable;

/**
 * Edita un atajo (CF-S6, LG-02): tipo, título, descripción, destino y vigencia. Una descarga sin archivo nuevo conserva el
 * que tiene; un archivo nuevo, o pasar a enlace, borra el anterior del disco, pero solo después del commit. Deja bitácora con
 * lo anterior y lo nuevo.
 */
final class UpdateLoginShortcut
{
    public function __construct(private readonly LoginContentRules $rules) {}

    /**
     * @param  array<string, mixed>  $data  `kind`, `title`, `description?`, `url?`, `starts_at?`, `ends_at?`, `file?` (UploadedFile)
     *
     * @throws ValidationException
     */
    public function handle(LoginShortcut $shortcut, array $data): LoginShortcut
    {
        $valid = $this->rules->shortcut($data, hasFile: $shortcut->file_path !== null);
        $newPath = $valid['file'] instanceof UploadedFile ? $valid['file']->store('login/shortcuts', 'public') : null;
        $oldPath = null;

        try {
            $shortcut = DB::transaction(function () use ($shortcut, $valid, $newPath, &$oldPath): LoginShortcut {
                $shortcut = LoginShortcut::query()->lockForUpdate()->findOrFail($shortcut->getKey());
                $columns = ['kind', 'title', 'description', 'url', 'file_path', 'starts_at', 'ends_at'];
                $before = $shortcut->only($columns);

                $filePath = $valid['kind'] === LoginShortcutKind::Download ? ($newPath ?: $shortcut->file_path) : null;

                if ($filePath !== $shortcut->file_path) {
                    $oldPath = $shortcut->file_path;
                }

                $shortcut->fill([
                    'kind' => $valid['kind'],
                    'title' => $valid['title'],
                    'description' => $valid['description'],
                    'url' => $valid['url'],
                    'file_path' => $filePath,
                    'starts_at' => $valid['starts_at'],
                    'ends_at' => $valid['ends_at'],
                ])->save();

                if ($shortcut->wasChanged()) {
                    activity()
                        ->performedOn($shortcut)
                        ->event(ActivityEvent::Updated)
                        ->withChanges(['attributes' => $shortcut->only($columns), 'old' => $before])
                        ->log("Editó el atajo «{$shortcut->title}»");
                }

                return $shortcut;
            });
        } catch (Throwable $e) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $e;
        }

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $shortcut;
    }
}
