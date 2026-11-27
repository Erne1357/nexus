<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->separateFakeDisksByDatabase();
    }

    /**
     * Storage::fake() guarda cada disco falso en storage/framework/testing/disks/<disco>, una carpeta por
     * worktree, y la vacía cada vez; Livewire hace lo mismo con sus copias temporales (tmp-for-tests) en
     * la primera subida de cada prueba. Dos corridas a la vez en un worktree, cada una con su base
     * (DB_DATABASE, CONTRIBUTING.md), se borraban los archivos y veían los de la otra. Con la base como
     * token, Storage::fake() usa <disco>_test_<base>; con --parallel sigue mandando el token de cada proceso.
     */
    private function separateFakeDisksByDatabase(): void
    {
        ParallelTesting::resolveTokenUsing(fn (): string => $_SERVER['TEST_TOKEN'] ?? DB::connection()->getDatabaseName());
    }
}
