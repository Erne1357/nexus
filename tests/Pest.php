<?php

use Tests\TestCase;

/*
| Feature y Browser corren con la aplicación de Laravel arrancada; Unit y
| Arch, sin ella.
*/

pest()->extend(TestCase::class)->in('Feature', 'Browser');
