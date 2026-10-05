<?php

namespace App\Models;

use App\Support\RichTextRenderer;

final class Message extends Record
{
    /** Relations needed to render a message partial or its JSON. */
    public const PRESENTATION = ['creator', 'room', 'richText', 'boosts.booster', 'attachment.blob'];

    /** Like Rails' `belongs_to :room, touch: true`: every message or boost change bumps the room's version. */
    protected $touches = ['room'];

    private static ?array $sounds = null;

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
        return $q->with(self::PRESENTATION);
    }

    public function plainText(): string
    {
        $plain = app(RichTextRenderer::class)->plain($this->richText?->body ?? '');

        return trim($plain) !== '' ? $plain : ($this->attachment?->blob?->filename ?? '');
    }

    /**
     * The sound for a `/play <name>` message, if any.
     */
    public function sound(): ?array
    {
        $body = $this->richText?->body ?? '';
        if (! str_contains($body, 'play') && ! str_contains($body, '<action-text-attachment')) {
            return null;
        }
        self::$sounds ??= json_decode(file_get_contents(resource_path('sounds.json')), true);

        return preg_match('/^\/play (\w+)$/', $this->plainText(), $match) ? self::$sounds[$match[1]] ?? null : null;
    }
}
