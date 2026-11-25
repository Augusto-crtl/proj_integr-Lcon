<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reading extends Model
{
    protected $fillable = [
        'source',
        'recorded_at',
        'variables',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'variables' => 'encrypted:array',
        ];
    }
}
