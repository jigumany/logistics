<?php

namespace App\Domain\Fleet;

use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];
}
