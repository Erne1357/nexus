<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Rules\LoginContentRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Throwable;

/**
 * Edita un aviso (CF-S6, LG-01): título, enlace, vigencia y foto. Una imagen nueva reemplaza a la anterior; `remove_image`
 * la quita. La foto vieja se borra del disco solo después del commit. Deja bitácora con lo anterior y lo nuevo.
 */
final class UpdateLoginNotice
{
    public function __construct(private readonly LoginContentRules $rules) {}

    /**
     * @param  array<string, mixed>  $data  `title`, `link_url?`, `starts_at?`, `ends_at?`, `image?` (UploadedFile), `remove_image?` (bool)
     *
     * @throws ValidationException
     */
    public function handle(LoginNotice $notice, array $data): LoginNotice
    {
        $valid = $this->rules->notice($data);
        $newPath = $valid['image'] instanceof UploadedFile ? $valid['image']->store('login/notices', 'public') : null;
        $oldPath = null;

        try {
            $notice = DB::transaction(function () use ($notice, $valid, $data, $newPath, &$oldPath): LoginNotice {
                $notice = LoginNotice::query()->lockForUpdate()->findOrFail($notice->getKey());
                $before = $notice->only(['title', 'image_path', 'link_url', 'starts_at', 'ends_at']);

                $imagePath = $notice->image_path;

                if ($newPath || ! empty($data['remove_image'])) {
                    $oldPath = $imagePath;
                    $imagePath = $newPath ?: null;
                }

                $notice->fill([
                    'title' => $valid['title'],
                    'image_path' => $imagePath,
                    'link_url' => $valid['link_url'],
                    'starts_at' => $valid['starts_at'],
                    'ends_at' => $valid['ends_at'],
                ])->save();

                if ($notice->wasChanged()) {
                    activity()
                        ->performedOn($notice)
                        ->event(ActivityEvent::Updated)
                        ->withChanges(['attributes' => $notice->only(array_keys($before)), 'old' => $before])
                        ->log("Editó el aviso «{$notice->title}»");
                }

                return $notice;
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

        return $notice;
    }
}
