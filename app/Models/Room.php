<?php

namespace App\Models;

final class Room extends Record
{
    public function messages()
    {
        return $this->hasMany(Message::class)->chaperone('room');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'memberships');
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function isDirect(): bool
    {
        return $this->type === 'Rooms::Direct';
    }

    public function displayName(?User $viewer = null): string
    {
        return $this->isDirect() ? $this->users->where('id', '!=', $viewer?->id)->pluck('name')->join(', ') : ($this->name ?? '');
    }
}
