<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $fillable = [
        'dedupe_key', 'type', 'title', 'body', 'branch_id', 'user_id', 'data', 'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function viewerStates()
    {
        return $this->hasMany(AppNotificationUserState::class, 'app_notification_id');
    }
}
