<?php

use Tests\TestCase;

/*
| Feature corre con la aplicación de Laravel arrancada; Unit y Arch, sin ella.
*/

pest()->extend(TestCase::class)->in('Feature');
