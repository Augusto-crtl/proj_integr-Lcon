<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ControlCommand extends Model
{
    protected $fillable = [
        'name',
        'value',
        'status',
        'reason',
        'created_by',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'acknowledged_at' => 'datetime',
        ];
    }
}
