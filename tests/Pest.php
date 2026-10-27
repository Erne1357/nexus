<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature corre con la aplicación de Laravel arrancada y la base de pruebas
| refrescada; Unit y Arch, sin ella.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
