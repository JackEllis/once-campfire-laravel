<?php

namespace App\Models;

final class Boost extends Record
{
    public function booster()
    {
        return $this->belongsTo(User::class, 'booster_id');
    }
}
