<?php

namespace App\Models;

use App\Support\RailsCrypto;

final class User extends Record
{
    protected $hidden = ['password_digest', 'bot_token'];

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'memberships');
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function scopeActive($q)
    {
        return $q->where('status', 0);
    }

    public function canAdminister($record = null): bool
    {
        return $this->role === 1 || ($record && $record->creator_id === $this->id);
    }

    public function avatarToken(): string
    {
        return app(RailsCrypto::class)->signedId($this->id, 'User', 'avatar');
    }

    protected function casts(): array
    {
        return ['role' => 'integer', 'status' => 'integer'];
    }
}
