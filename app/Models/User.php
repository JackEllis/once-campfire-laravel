<?php

namespace App\Models;

use App\Support\RailsCrypto;
use Illuminate\Database\Eloquent\Collection;
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
     * Visible memberships split into direct rooms (most recent first) and shared rooms (by name).
     *
     * @return array{0: Collection<int, Membership>, 1: Collection<int, Membership>}
     */
    public function sidebarMemberships(): array
    {
        [$directs, $shared] = $this->memberships()->where('involvement', '!=', 'invisible')->with('room')->get()->partition(fn ($membership) => $membership->room->isDirect());
        $directs->load('room.users:id,name');

        return [
            $directs->sortByDesc(fn ($membership) => $membership->room->updated_at),
            $shared->sortBy(fn ($membership) => mb_strtolower($membership->room->name ?? '')),
        ];
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
