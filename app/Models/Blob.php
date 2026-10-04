<?php

namespace App\Models;

final class Blob extends Record
{
    protected $table = 'active_storage_blobs';

    public $timestamps = false;
}
