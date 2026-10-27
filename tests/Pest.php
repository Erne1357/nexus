<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/*
| Feature y Browser corren con la aplicación de Laravel arrancada y la base de
| pruebas refrescada; Unit y Arch, sin ella.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser');

/**
 * El último toast que el componente mandó mostrar: MaryUI lo evalúa en el navegador como `toast({...})`.
 *
 * @return array{type: string, title: string, description: ?string}|null
 */
function lastToastOf(Testable $component): ?array
{
    $expression = collect($component->effects['xjs'] ?? [])
        ->pluck('expression')
        ->last(fn (string $expression): bool => str_starts_with($expression, 'toast('));

    return $expression === null ? null : json_decode(substr($expression, 6, -1), true)['toast'];
}

/**
 * Un uuid fijo para un registro que la prueba no crea: `fakeKey(10)` siempre da el mismo valor. Lo
 * usan los departamentos y carreras de los alcances mientras Academic no tenga sus tablas (fase 1).
 */
function fakeKey(int $number): string
{
    return sprintf('00000000-0000-7000-8000-%012d', $number);
}
