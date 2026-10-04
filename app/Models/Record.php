<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class Record extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
