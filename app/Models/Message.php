<?php

namespace App\Models;

use App\Support\RichTextRenderer;

final class Message extends Record
{
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function richText()
    {
        return $this->hasOne(RichText::class, 'record_id')->where('record_type', 'Message')->where('name', 'body');
    }

    public function boosts()
    {
        return $this->hasMany(Boost::class);
    }

    public function attachment()
    {
        return $this->hasOne(Attachment::class, 'record_id')->where('record_type', 'Message')->where('name', 'attachment');
    }

    public function scopePresentation($q)
    {
        return $q->with(['creator', 'room.users', 'richText', 'boosts.booster', 'attachment.blob']);
    }

    public function plainText(): string
    {
        return app(RichTextRenderer::class)->plain($this->richText?->body ?? '');
    }
}
