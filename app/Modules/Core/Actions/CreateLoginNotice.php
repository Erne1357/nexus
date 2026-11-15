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
 * Da de alta un aviso del carrusel de la pantalla de acceso (CF-S6, LG-01). Valida con LoginContentRules (título,
 * enlace http(s), fin después del inicio, imagen jpg, png o webp de hasta 5 MB), guarda la imagen en `login/notices` del
 * disco público y deja bitácora. Nace activo y al final del orden. Si algo falla después de guardar la imagen, la borra.
 */
final class CreateLoginNotice
{
    public function __construct(private readonly LoginContentRules $rules) {}

    /**
     * @param  array<string, mixed>  $data  `title`, `link_url?`, `starts_at?`, `ends_at?`, `image?` (UploadedFile)
     *
     * @throws ValidationException
     */
    public function handle(array $data): LoginNotice
    {
        $valid = $this->rules->notice($data);
        $path = $valid['image'] instanceof UploadedFile ? $valid['image']->store('login/notices', 'public') : null;

        try {
            return DB::transaction(function () use ($valid, $path): LoginNotice {
                $notice = LoginNotice::create([
                    'title' => $valid['title'],
                    'image_path' => $path ?: null,
                    'link_url' => $valid['link_url'],
                    'starts_at' => $valid['starts_at'],
                    'ends_at' => $valid['ends_at'],
                    'sort_order' => ((int) LoginNotice::query()->max('sort_order')) + 1,
                    'is_active' => true,
                ]);

                activity()
                    ->performedOn($notice)
                    ->event(ActivityEvent::Created)
                    ->withChanges(['attributes' => $notice->only(['title', 'image_path', 'link_url', 'starts_at', 'ends_at', 'is_active'])])
                    ->log("Dio de alta el aviso «{$notice->title}»");

                return $notice;
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }
    }
}
