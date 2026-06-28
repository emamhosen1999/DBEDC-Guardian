<?php
// app/Models/NotificationToken.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationToken extends Model
{
    protected $fillable = ['user_id', 'provider', 'token', 'platform', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
