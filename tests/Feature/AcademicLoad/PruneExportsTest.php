<?php

use App\Modules\AcademicLoad\Exports\PublishedExports;
use Illuminate\Support\Facades\Storage;

/*
| academic-load:prune-exports (tarea 4.5): borra los exportes viejos, que una versión nueva dejó sin uso, y los
| temporales a medias; deja los recientes. Lo borrado se vuelve a armar si alguien lo pide.
*/

it('borra los exportes viejos y los temporales a medias, y deja los recientes', function () {
    $disk = Storage::fake(PublishedExports::DISK);
    $disk->put('published/viejo.pdf', 'x');
    $disk->put('published/nuevo.pdf', 'x');
    $disk->put('published/nuevo.pdf.abc.part', 'x');
    $disk->put('published/viejo.pdf.def.part', 'x');
    touch($disk->path('published/viejo.pdf'), now()->subDays(31)->getTimestamp());
    touch($disk->path('published/viejo.pdf.def.part'), now()->subHours(2)->getTimestamp());

    $this->artisan('academic-load:prune-exports')->expectsOutput('Se borraron 2 archivos de exportes.')->assertSuccessful();

    expect($disk->allFiles('published'))->toEqualCanonicalizing(['published/nuevo.pdf', 'published/nuevo.pdf.abc.part']);
});

it('sin exportes no hace nada', function () {
    Storage::fake(PublishedExports::DISK);

    $this->artisan('academic-load:prune-exports')->expectsOutput('Se borraron 0 archivos de exportes.')->assertSuccessful();
});
