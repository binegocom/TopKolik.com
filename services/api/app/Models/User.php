<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function readerPreference(): HasOne
    {
        return $this->hasOne(ReaderPreference::class);
    }

    public function favoriteSports(): BelongsToMany
    {
        return $this->belongsToMany(Sport::class, 'reader_favorite_sports')->withTimestamps();
    }

    public function favoriteTeams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'reader_favorite_teams')->withTimestamps();
    }

    public function follows(): HasMany
    {
        return $this->hasMany(UserFollow::class);
    }

    public function savedItems(): HasMany
    {
        return $this->hasMany(UserSavedItem::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
