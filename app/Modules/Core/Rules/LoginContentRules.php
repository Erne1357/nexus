<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Enums\LoginShortcutKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de los avisos y los atajos de la pantalla de acceso que comparten sus Actions (CF-S6, LG-01, LG-02): título,
 * enlaces solo http(s) (la pantalla es pública y la base ya lo exige con un CHECK; aquí se dice con un mensaje claro), fin
 * después del inicio y archivos acotados. La imagen del aviso es jpg, png o webp de hasta 5 MB; el archivo del atajo, pdf
 * de hasta 10 MB. Cada regla rota es una ValidationException sobre su campo.
 */
final class LoginContentRules
{
    public const IMAGE_MAX_KB = 5120;

    public const FILE_MAX_KB = 10240;

    private const LINK = ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/\S+$/i'];

    /**
     * @param  array<string, mixed>  $data  `title`, `link_url?`, `starts_at?`, `ends_at?`, `image?`
     * @return array{title: string, link_url: ?string, starts_at: mixed, ends_at: mixed, image: ?UploadedFile}
     *
     * @throws ValidationException
     */
    public function notice(array $data): array
    {
        $validated = $this->validate($data, [
            'title' => ['required', 'string', 'max:120'],
            'link_url' => self::LINK,
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::IMAGE_MAX_KB],
        ], [
            'title' => 'título', 'link_url' => 'enlace', 'image' => 'imagen', 'starts_at' => 'inicio', 'ends_at' => 'fin',
        ], [
            'image.max' => 'La imagen pesa más de 5 MB.',
            'image.mimes' => 'La imagen debe ser jpg, png o webp.',
        ]);

        return [
            'title' => trim($validated['title']),
            'link_url' => $this->blankToNull($validated['link_url'] ?? null),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'image' => $validated['image'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  `kind`, `title`, `description?`, `url?`, `starts_at?`, `ends_at?`, `file?`
     * @param  bool  $hasFile  si el atajo ya tiene un archivo guardado (al editar una descarga sin subir otro)
     * @return array{kind: LoginShortcutKind, title: string, description: ?string, url: ?string, starts_at: mixed, ends_at: mixed, file: ?UploadedFile}
     *
     * @throws ValidationException
     */
    public function shortcut(array $data, bool $hasFile = false): array
    {
        $kind = $data['kind'] ?? null;
        $isDownload = ($kind instanceof LoginShortcutKind ? $kind->value : $kind) === LoginShortcutKind::Download->value;

        $validated = $this->validate($data, [
            'kind' => ['required', Rule::enum(LoginShortcutKind::class)],
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:160'],
            'url' => $isDownload ? self::LINK : [...self::LINK, 'required'],
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:'.self::FILE_MAX_KB, $isDownload && ! $hasFile ? 'required' : 'nullable'],
        ], [
            'kind' => 'tipo', 'title' => 'título', 'description' => 'descripción', 'url' => 'enlace', 'file' => 'archivo', 'starts_at' => 'inicio', 'ends_at' => 'fin',
        ], [
            'file.max' => 'El archivo pesa más de 10 MB.',
            'file.mimes' => 'El archivo debe ser un pdf.',
            'file.required' => 'Sube el archivo que se descarga.',
        ]);

        return [
            'kind' => LoginShortcutKind::from($validated['kind'] instanceof LoginShortcutKind ? $validated['kind']->value : $validated['kind']),
            'title' => trim($validated['title']),
            'description' => $this->blankToNull($validated['description'] ?? null),
            'url' => $isDownload ? null : $this->blankToNull($validated['url'] ?? null),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'file' => $isDownload ? ($validated['file'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $data, array $rules, array $attributes, array $messages): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = $this->blankToNull(is_string($data[$field] ?? null) ? $data[$field] : null);
        }

        $rules += [
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...($data['starts_at'] === null ? [] : ['after:starts_at'])],
        ];

        return Validator::make($data, $rules, [
            'link_url.regex' => 'El enlace debe empezar con http:// o https://.',
            'url.regex' => 'El enlace debe empezar con http:// o https://.',
            'url.required' => 'Escribe el enlace.',
            'ends_at.after' => 'El fin debe ser después del inicio.',
            ...$messages,
        ], $attributes)->validate();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
