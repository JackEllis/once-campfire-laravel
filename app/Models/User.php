<?php

namespace App\Models;

use App\Support\RailsCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class User extends Record
{
    protected $hidden = ['password_digest', 'bot_token'];

    private ?string $avatarToken = null;

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

    /**
     * Visible rooms for the sidebar, split into direct rooms (most recent first) and shared rooms
     * (by name). Rows carry `room_id`, `unread_at` and the display `name` the viewer should see.
     *
     * @return array{0: list<object>, 1: list<object>}
     */
    public function sidebar(): array
    {
        $directs = $shared = [];
        $rows = DB::select("SELECT m.room_id, m.unread_at, r.name, r.type, r.updated_at FROM memberships m JOIN rooms r ON r.id = m.room_id WHERE m.user_id = ? AND m.involvement != 'invisible'", [$this->id]);
        foreach ($rows as $row) {
            if ($row->type === 'Rooms::Direct') {
                $directs[] = $row;
            } else {
                $shared[] = $row;
            }
        }
        if ($directs !== []) {
            $names = [];
            $ids = array_column($directs, 'room_id');
            $others = DB::select('SELECT m.room_id, u.name FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.room_id IN ('.implode(',', array_fill(0, count($ids), '?')).') AND m.user_id != ?', [...$ids, $this->id]);
            foreach ($others as $other) {
                $names[$other->room_id][] = $other->name;
            }
            foreach ($directs as $direct) {
                $direct->name = implode(', ', $names[$direct->room_id] ?? []);
            }
            usort($directs, fn ($a, $b) => strcmp($b->updated_at, $a->updated_at));
        }
        usort($shared, fn ($a, $b) => mb_strtolower($a->name ?? '') <=> mb_strtolower($b->name ?? ''));

        return [$directs, $shared];
    }

    public function avatarToken(): string
    {
        return $this->avatarToken ??= app(RailsCrypto::class)->signedId($this->id, 'User', 'avatar');
    }

    public function avatarUrl(): string
    {
        return '/users/'.$this->avatarToken().'/avatar?v='.$this->updated_at->format('YmdHis');
    }

    public function deactivate(): void
    {
        DB::transaction(function () {
            $this->memberships()->whereHas('room', fn ($query) => $query->where('type', '!=', 'Rooms::Direct'))->delete();
            foreach (['sessions', 'push_subscriptions', 'searches'] as $table) {
                DB::table($table)->where('user_id', $this->id)->delete();
            }
            $values = ['status' => 1];
            if ($this->email_address) {
                $values['email_address'] = str_replace('@', '-deactivated-'.Str::uuid().'@', $this->email_address);
            }
            $this->update($values);
        });
    }

    protected function casts(): array
    {
        return ['role' => 'integer', 'status' => 'integer'];
    }
}
