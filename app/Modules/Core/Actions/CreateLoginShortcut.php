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
 * Da de alta un atajo de la pantalla de acceso (CF-S6, LG-02): un enlace http(s) o la descarga de un pdf de hasta 10 MB,
 * que se guarda en `login/shortcuts` del disco público. Valida con LoginContentRules, nace activo y al final del orden, y
 * deja bitácora. Si algo falla después de guardar el archivo, lo borra.
 */
final class CreateLoginShortcut
{
    public function __construct(private readonly LoginContentRules $rules) {}

    /**
     * @param  array<string, mixed>  $data  `kind`, `title`, `description?`, `url?`, `starts_at?`, `ends_at?`, `file?` (UploadedFile)
     *
     * @throws ValidationException
     */
    public function handle(array $data): LoginShortcut
    {
        $valid = $this->rules->shortcut($data);
        $path = $valid['file'] instanceof UploadedFile ? $valid['file']->store('login/shortcuts', 'public') : null;

        try {
            return DB::transaction(function () use ($valid, $path): LoginShortcut {
                $shortcut = LoginShortcut::create([
                    'kind' => $valid['kind'],
                    'title' => $valid['title'],
                    'description' => $valid['description'],
                    'url' => $valid['kind'] === LoginShortcutKind::Link ? $valid['url'] : null,
                    'file_path' => $valid['kind'] === LoginShortcutKind::Download ? ($path ?: null) : null,
                    'starts_at' => $valid['starts_at'],
                    'ends_at' => $valid['ends_at'],
                    'sort_order' => ((int) LoginShortcut::query()->max('sort_order')) + 1,
                    'is_active' => true,
                ]);

                activity()
                    ->performedOn($shortcut)
                    ->event(ActivityEvent::Created)
                    ->withChanges(['attributes' => $shortcut->only(['kind', 'title', 'description', 'url', 'file_path', 'starts_at', 'ends_at', 'is_active'])])
                    ->log("Dio de alta el atajo «{$shortcut->title}»");

                return $shortcut;
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }
    }
}
