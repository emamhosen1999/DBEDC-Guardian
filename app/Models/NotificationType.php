<?php

// app/Models/NotificationType.php

namespace App\Models;

use App\Services\Notification\NotificationChannelResolver;
use Illuminate\Database\Eloquent\Model;

class NotificationType extends Model
{
    protected $fillable = ['key', 'category', 'label', 'description', 'default_channels', 'locked_channels', 'recipient_roles', 'is_active'];

    protected $casts = [
        'default_channels' => 'array',
        'locked_channels' => 'array',
        'recipient_roles' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        $flush = fn () => app(NotificationChannelResolver::class)->forgetTypes();
        static::saved($flush);
        static::deleted($flush);
    }
}
