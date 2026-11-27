<?php

namespace App\Modules\Imports\Sii;

/**
 * Lo que `StudentsStep` descarta o corrige, solo en conteos.
 */
final class StudentTally
{
    /** @var array<string, int> */
    public array $unknownPlans = [];

    /** @var array<string, int> */
    public array $unknownCampuses = [];

    /** @var array<string, int> */
    public array $unknownStatuses = [];

    public int $badSemesters = 0;

    public int $nameless = 0;
}
