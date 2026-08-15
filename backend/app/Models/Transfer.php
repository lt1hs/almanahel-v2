<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    protected $fillable = [
        'from_branch_id',
        'to_branch_id',
        'status',
        'user_id',
        'items',
    ];

    protected $casts = [
        'items' => 'json',
    ];

    public function lineItems(): array
    {
        return collect($this->items ?? [])
            ->filter(fn ($item) => is_array($item) && !empty($item['book_id']))
            ->values()
            ->all();
    }

    public function statusLog(): array
    {
        $meta = collect($this->items ?? [])->first(
            fn ($item) => is_array($item) && array_key_exists('_status_log', $item)
        );

        return is_array($meta['_status_log'] ?? null) ? $meta['_status_log'] : [];
    }

    public function displayStatusLog(): array
    {
        $log = $this->statusLog();
        if ($log) {
            return $log;
        }

        $steps = [[
            'status' => 'pending',
            'at' => optional($this->created_at)?->toIso8601String(),
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
        ]];

        if (in_array($this->status, ['shipped', 'received', 'cancelled'], true)) {
            $steps[] = [
                'status' => $this->status,
                'at' => optional($this->updated_at)?->toIso8601String(),
            ];
        }

        return $steps;
    }

    public function itemsWithStatusLog(array $log): array
    {
        $lines = $this->lineItems();
        $lines[] = ['_status_log' => array_values($log)];

        return $lines;
    }

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
