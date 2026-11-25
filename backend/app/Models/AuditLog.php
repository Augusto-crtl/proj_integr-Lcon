<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'actor',
        'action',
        'ip_address',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ip_address' => 'encrypted',
            'metadata' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        Request $request,
        string $action,
        string $actor,
        ?int $userId = null,
        array $metadata = [],
    ): self {
        return static::create([
            'user_id' => $userId,
            'actor' => $actor,
            'action' => $action,
            'ip_address' => $request->ip(),
            'metadata' => $metadata,
        ]);
    }
}
