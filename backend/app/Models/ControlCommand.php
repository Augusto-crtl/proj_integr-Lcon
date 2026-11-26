<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo; // Tipifica o relacionamento com o usuário autor do comando.
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

    public function creator(): BelongsTo // Expõe a conta que enviou o parâmetro de controle.
    {
        return $this->belongsTo(User::class, 'created_by'); // Resolve o usuário associado ao comando.
    }
}
