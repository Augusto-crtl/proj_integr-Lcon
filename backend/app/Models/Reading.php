<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo; // Tipifica o relacionamento entre leitura e usuário.
use Illuminate\Database\Eloquent\Model;

class Reading extends Model
{
    protected $fillable = [
        'source',
        'user_id', // Permite persistir a associação autenticada da aquisição.
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

    public function user(): BelongsTo // Expõe o usuário associado à leitura.
    {
        return $this->belongsTo(User::class); // Resolve a conta que originou a sessão de controle.
    }
}
