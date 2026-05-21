<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'balance',
    ];

    protected $casts = [
        'balance' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public static function ensureExists(): self
    {
        return static::firstOrCreate([], ['balance' => 0]);
    }
}
