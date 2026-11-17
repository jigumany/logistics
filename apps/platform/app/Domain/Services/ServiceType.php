<?php

namespace App\Domain\Services;

use Illuminate\Database\Eloquent\Model;

final class ServiceType extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];
}
