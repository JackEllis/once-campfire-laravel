<?php

namespace App\Models;

final class Attachment extends Record
{
    protected $table = 'active_storage_attachments';

    public $timestamps = false;

    public function blob()
    {
        return $this->belongsTo(Blob::class);
    }
}
