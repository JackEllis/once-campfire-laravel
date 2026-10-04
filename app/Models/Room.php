<?php

namespace App\Models;

final class Room extends Record
{
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'memberships');
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function displayName(?User $viewer = null): string
    {
        return $this->type === 'Rooms::Direct' ? $this->users->where('id', '!=', $viewer?->id)->pluck('name')->join(', ') : ($this->name ?? '');
    }
}
