<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\LoginShortcutKind;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que muestra la pantalla de acceso además del formulario: los avisos del carrusel (LG-01) y los
 * atajos (LG-02), activos y vigentes ahora y en su orden, listos para la vista. Las fotos y las
 * descargas viven en el disco público.
 */
final class LoginScreenQuery
{
    /**
     * @return list<array{title: string, image_url: ?string, link_url: ?string}>
     */
    public function notices(): array
    {
        $public = Storage::disk('public');

        return LoginNotice::query()->visibleAt(now())->get()
            ->map(fn (LoginNotice $notice): array => [
                'title' => $notice->title,
                'image_url' => $notice->image_path === null ? null : $public->url($notice->image_path),
                'link_url' => $notice->link_url,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string, description: ?string, href: string, download: bool}>
     */
    public function shortcuts(): array
    {
        $public = Storage::disk('public');

        return LoginShortcut::query()->visibleAt(now())->get()
            ->map(fn (LoginShortcut $shortcut): array => [
                'title' => $shortcut->title,
                'description' => $shortcut->description,
                'href' => $shortcut->kind === LoginShortcutKind::Download
                    ? $public->url((string) $shortcut->file_path)
                    : (string) $shortcut->url,
                'download' => $shortcut->kind === LoginShortcutKind::Download,
            ])
            ->values()
            ->all();
    }
}
