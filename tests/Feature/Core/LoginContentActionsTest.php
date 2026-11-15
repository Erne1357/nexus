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
use App\Modules\Core\Enums\LoginShortcutKind;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de los avisos y los atajos de la pantalla de acceso (CF-S6, LG-01, LG-02): alta, edición, activar o desactivar,
| borrar (borrado lógico) y mover el orden, con bitácora; imagen jpg, png o webp de hasta 5 MB, pdf de hasta 10 MB,
| enlaces solo http(s) y fin después del inicio.
*/

beforeEach(function () {
    Storage::fake('public');
});

function failsOn(Closure $call, string $field): bool
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_key_exists($field, $e->errors());
    }

    return false;
}

it('CF-S6: da de alta un aviso activo, al final del orden, con su foto en login/notices y bitácora', function () {
    LoginNotice::factory()->create(['sort_order' => 4]);

    $notice = app(CreateLoginNotice::class)->handle([
        'title' => '  Inscripciones abiertas ',
        'link_url' => 'https://www.tecnm.mx',
        'starts_at' => '2027-01-10T08:00',
        'ends_at' => '2027-01-20T18:00',
        'image' => UploadedFile::fake()->create('foto.webp', 50, 'image/webp'),
    ]);

    expect($notice->title)->toBe('Inscripciones abiertas')
        ->and($notice->is_active)->toBeTrue()
        ->and($notice->sort_order)->toBe(5)
        ->and($notice->image_path)->toStartWith('login/notices/')
        ->and(Storage::disk('public')->exists($notice->image_path))->toBeTrue()
        ->and(Activity::query()->where('subject_id', $notice->id)->where('event', 'created')->exists())->toBeTrue();
});

it('CF-S6: un aviso sin foto, sin enlace y sin fechas es válido', function () {
    $notice = app(CreateLoginNotice::class)->handle(['title' => 'Hola', 'link_url' => '', 'starts_at' => '', 'ends_at' => '']);

    expect($notice->image_path)->toBeNull()->and($notice->link_url)->toBeNull()->and($notice->starts_at)->toBeNull();
});

it('CF-S6: el aviso rechaza un enlace que no es http(s), un fin antes del inicio y una foto fuera de lo permitido', function (array $data, string $field) {
    expect(failsOn(fn () => app(CreateLoginNotice::class)->handle(['title' => 'X', ...value($data)]), $field))->toBeTrue()
        ->and(LoginNotice::query()->count())->toBe(0);
})->with([
    'javascript' => [['link_url' => 'javascript:alert(1)'], 'link_url'],
    'sin protocolo' => [['link_url' => 'www.tecnm.mx'], 'link_url'],
    'fin antes del inicio' => [['starts_at' => '2027-01-20T08:00', 'ends_at' => '2027-01-10T08:00'], 'ends_at'],
    'fin igual al inicio' => [['starts_at' => '2027-01-20T08:00', 'ends_at' => '2027-01-20T08:00'], 'ends_at'],
    'foto gif' => [fn () => ['image' => UploadedFile::fake()->create('a.gif', 10, 'image/gif')], 'image'],
    'foto de más de 5 MB' => [fn () => ['image' => UploadedFile::fake()->create('a.png', 5121, 'image/png')], 'image'],
    'sin título' => [['title' => ' '], 'title'],
]);

it('CF-S6: la foto de justo 5 MB sí se acepta', function () {
    $notice = app(CreateLoginNotice::class)->handle(['title' => 'X', 'image' => UploadedFile::fake()->create('a.jpg', 5120, 'image/jpeg')]);

    expect($notice->image_path)->not->toBeNull();
});

it('CF-S6: editar un aviso cambia sus datos, reemplaza la foto y borra la vieja del disco', function () {
    $notice = app(CreateLoginNotice::class)->handle(['title' => 'Uno', 'image' => UploadedFile::fake()->create('a.png', 50, 'image/png')]);
    $old = $notice->image_path;

    $updated = app(UpdateLoginNotice::class)->handle($notice, ['title' => 'Dos', 'link_url' => 'http://itcj.edu.mx', 'image' => UploadedFile::fake()->create('b.png', 50, 'image/png')]);

    expect($updated->title)->toBe('Dos')
        ->and($updated->image_path)->not->toBe($old)
        ->and(Storage::disk('public')->exists($old))->toBeFalse()
        ->and(Storage::disk('public')->exists($updated->image_path))->toBeTrue()
        ->and(Activity::query()->where('subject_id', $notice->id)->where('event', 'updated')->exists())->toBeTrue();
});

it('CF-S6: editar un aviso sin foto nueva conserva la que tiene, y «quitar la foto» la borra', function () {
    $notice = app(CreateLoginNotice::class)->handle(['title' => 'Uno', 'image' => UploadedFile::fake()->create('a.png', 50, 'image/png')]);
    $path = $notice->image_path;

    $kept = app(UpdateLoginNotice::class)->handle($notice, ['title' => 'Uno bis']);
    expect($kept->image_path)->toBe($path)->and(Storage::disk('public')->exists($path))->toBeTrue();

    $removed = app(UpdateLoginNotice::class)->handle($kept, ['title' => 'Uno bis', 'remove_image' => true]);
    expect($removed->image_path)->toBeNull()->and(Storage::disk('public')->exists($path))->toBeFalse();
});

it('CF-S6: editar un aviso con datos inválidos no cambia nada ni borra la foto', function () {
    $notice = app(CreateLoginNotice::class)->handle(['title' => 'Uno', 'image' => UploadedFile::fake()->create('a.png', 50, 'image/png')]);

    expect(failsOn(fn () => app(UpdateLoginNotice::class)->handle($notice, ['title' => 'Dos', 'link_url' => 'ftp://x', 'remove_image' => true]), 'link_url'))->toBeTrue()
        ->and($notice->fresh()->title)->toBe('Uno')
        ->and(Storage::disk('public')->exists($notice->image_path))->toBeTrue();
});

it('CF-S6: desactiva y activa un aviso con bitácora, y no registra si el estado no cambia', function () {
    $notice = LoginNotice::factory()->create();

    app(SetLoginNoticeActive::class)->handle($notice, false);
    app(SetLoginNoticeActive::class)->handle($notice, false);

    expect($notice->fresh()->is_active)->toBeFalse()
        ->and(Activity::query()->where('subject_id', $notice->id)->count())->toBe(1);

    app(SetLoginNoticeActive::class)->handle($notice, true);
    expect($notice->fresh()->is_active)->toBeTrue();
});

it('CF-S6: borrar un aviso es lógico, conserva la foto y deja bitácora', function () {
    $notice = app(CreateLoginNotice::class)->handle(['title' => 'Uno', 'image' => UploadedFile::fake()->create('a.png', 50, 'image/png')]);

    app(DeleteLoginNotice::class)->handle($notice);

    expect(LoginNotice::query()->find($notice->id))->toBeNull()
        ->and(LoginNotice::withTrashed()->find($notice->id))->not->toBeNull()
        ->and(Storage::disk('public')->exists($notice->image_path))->toBeTrue()
        ->and(Activity::query()->where('subject_id', $notice->id)->where('event', 'deleted')->exists())->toBeTrue();
});

it('CF-S6: sube y baja un aviso, también cuando todos empatan en el orden, y no se sale de los extremos', function () {
    $a = LoginNotice::factory()->create(['title' => 'A']);
    $b = LoginNotice::factory()->create(['title' => 'B']);
    $c = LoginNotice::factory()->create(['title' => 'C']);
    $titles = fn (): array => LoginNotice::query()->orderBy('sort_order')->orderBy('created_at')->pluck('title')->all();

    app(MoveLoginNotice::class)->handle($c, -1);
    expect($titles())->toBe(['A', 'C', 'B']);

    app(MoveLoginNotice::class)->handle($a, -1);
    app(MoveLoginNotice::class)->handle($b, 1);
    expect($titles())->toBe(['A', 'C', 'B']);

    app(MoveLoginNotice::class)->handle($a, 1);
    expect($titles())->toBe(['C', 'A', 'B']);
});

it('CF-S6: da de alta un atajo de enlace y uno de descarga con su pdf en login/shortcuts', function () {
    $link = app(CreateLoginShortcut::class)->handle(['kind' => 'enlace', 'title' => 'Sitio', 'description' => 'El sitio', 'url' => 'https://www.tecnm.mx']);
    $download = app(CreateLoginShortcut::class)->handle(['kind' => 'descarga', 'title' => 'Guía', 'url' => 'https://ignorada.mx', 'file' => UploadedFile::fake()->create('guia.pdf', 100, 'application/pdf')]);

    expect($link->kind)->toBe(LoginShortcutKind::Link)->and($link->url)->toBe('https://www.tecnm.mx')->and($link->file_path)->toBeNull()
        ->and($download->kind)->toBe(LoginShortcutKind::Download)->and($download->url)->toBeNull()
        ->and($download->file_path)->toStartWith('login/shortcuts/')
        ->and(Storage::disk('public')->exists($download->file_path))->toBeTrue()
        ->and($download->sort_order)->toBe($link->sort_order + 1)
        ->and(Activity::query()->where('event', 'created')->count())->toBe(2);
});

it('CF-S6: el atajo rechaza enlace inválido o vacío, descarga sin archivo, archivo que no es pdf o de más de 10 MB y fin antes del inicio', function (array $data, string $field) {
    expect(failsOn(fn () => app(CreateLoginShortcut::class)->handle(['kind' => 'enlace', 'title' => 'X', 'url' => 'https://a.mx', ...value($data)]), $field))->toBeTrue()
        ->and(LoginShortcut::query()->count())->toBe(0);
})->with([
    'enlace javascript' => [['url' => 'javascript:alert(1)'], 'url'],
    'enlace vacío' => [['url' => ''], 'url'],
    'descarga sin archivo' => [['kind' => 'descarga'], 'file'],
    'archivo no pdf' => [fn () => ['kind' => 'descarga', 'file' => UploadedFile::fake()->create('a.docx', 10)], 'file'],
    'archivo de más de 10 MB' => [fn () => ['kind' => 'descarga', 'file' => UploadedFile::fake()->create('a.pdf', 10241, 'application/pdf')], 'file'],
    'fin antes del inicio' => [['starts_at' => '2027-02-01', 'ends_at' => '2027-01-01'], 'ends_at'],
    'tipo desconocido' => [['kind' => 'oferta'], 'kind'],
]);

it('CF-S6: editar una descarga sin archivo nuevo conserva el pdf; con uno nuevo borra el viejo; pasar a enlace lo borra', function () {
    $shortcut = app(CreateLoginShortcut::class)->handle(['kind' => 'descarga', 'title' => 'Guía', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
    $first = $shortcut->file_path;

    $kept = app(UpdateLoginShortcut::class)->handle($shortcut, ['kind' => 'descarga', 'title' => 'Guía 2']);
    expect($kept->file_path)->toBe($first)->and(Storage::disk('public')->exists($first))->toBeTrue();

    $replaced = app(UpdateLoginShortcut::class)->handle($kept, ['kind' => 'descarga', 'title' => 'Guía 2', 'file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')]);
    expect($replaced->file_path)->not->toBe($first)->and(Storage::disk('public')->exists($first))->toBeFalse();

    $link = app(UpdateLoginShortcut::class)->handle($replaced, ['kind' => 'enlace', 'title' => 'Guía 2', 'url' => 'https://a.mx']);
    expect($link->kind)->toBe(LoginShortcutKind::Link)->and($link->file_path)->toBeNull()
        ->and(Storage::disk('public')->exists($replaced->file_path))->toBeFalse();
});

it('CF-S6: pasar un enlace a descarga exige el archivo', function () {
    $shortcut = LoginShortcut::factory()->create();

    expect(failsOn(fn () => app(UpdateLoginShortcut::class)->handle($shortcut, ['kind' => 'descarga', 'title' => 'X']), 'file'))->toBeTrue()
        ->and($shortcut->fresh()->kind)->toBe(LoginShortcutKind::Link);
});

it('CF-S6: activa, desactiva, borra y mueve atajos con bitácora', function () {
    $a = LoginShortcut::factory()->create(['title' => 'A']);
    $b = LoginShortcut::factory()->create(['title' => 'B']);

    app(SetLoginShortcutActive::class)->handle($a, false);
    app(MoveLoginShortcut::class)->handle($b, -1);
    app(DeleteLoginShortcut::class)->handle($a);

    expect($a->fresh()->is_active)->toBeFalse()
        ->and(LoginShortcut::query()->pluck('title')->all())->toBe(['B'])
        ->and(LoginShortcut::withTrashed()->count())->toBe(2)
        ->and(Activity::query()->count())->toBe(3);
});
