<?php

namespace Tests\Fixtures\Modules\DemoCatalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Elemento de un departamento y una carrera en un campus, como lo será un grupo.
 */
#[Fillable(['name', 'campus_id', 'department_id', 'career_id'])]
class Item extends Model
{
    protected $table = 'demo_catalog_items';
}
