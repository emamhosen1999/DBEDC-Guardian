<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Letter extends Model
{
    use HasFactory;

    protected $fillable = [
        'from',
        'sender_name',
        'sender_email',
        'sender_address',
        'sender_phone',
        'recipient',
        'status', // unread, read, archived, processed, urgent
        'priority', // low, normal, high, urgent
        'category', // general, official, personal, legal, financial
        'received_date',
        'memo_number',
        'handling_memo',
        'subject',
        'content',
        'action_taken',
        'handling_link',
        'handling_status',
        'need_reply',
        'replied_status',
        'reply_date',
        'reply_content',
        'need_forward',
        'forwarded_status',
        'forwarded_to',
        'forward_date',
        'dealt_by', // Foreign key for User model
        'assigned_to', // Foreign key for User model
        'due_date',
        'attachments', // JSON array of attachment metadata
        'metadata', // JSON for additional extracted data
        'source', // email, physical, fax, etc.
        'reference_number',
        'confidential',
        'tags', // JSON array
    ];

    protected $casts = [
        'need_reply' => 'boolean',
        'replied_status' => 'boolean',
        'need_forward' => 'boolean',
        'forwarded_status' => 'boolean',
        'received_date' => 'datetime',
        'reply_date' => 'datetime',
        'forward_date' => 'datetime',
        'due_date' => 'datetime',
        'confidential' => 'boolean',
        'attachments' => 'array',
        'metadata' => 'array',
        'tags' => 'array',
    ];

    /**
     * Get the user who dealt with the letter.
     */
    public function dealtBy()
    {
        return $this->belongsTo(User::class, 'dealt_by');
    }

    /**
     * Get the user assigned to handle the letter.
     */
    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who forwarded the letter.
     */
    public function forwardedTo()
    {
        return $this->belongsTo(User::class, 'forwarded_to');
    }

    /**
     * Scope for filtering by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for filtering by priority
     */
    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope for filtering by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope for unread letters
     */
    public function scopeUnread($query)
    {
        return $query->where('status', 'unread');
    }

    /**
     * Scope for urgent letters
     */
    public function scopeUrgent($query)
    {
        return $query->whereIn('priority', ['high', 'urgent']);
    }

    /**
     * Scope for letters needing reply
     */
    public function scopeNeedingReply($query)
    {
        return $query->where('need_reply', true)->where('replied_status', false);
    }

    /**
     * Scope for overdue letters
     */
    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')->where('due_date', '<', now());
    }

    /**
     * Scope for search functionality
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('subject', 'like', "%{$search}%")
              ->orWhere('content', 'like', "%{$search}%")
              ->orWhere('from', 'like', "%{$search}%")
              ->orWhere('sender_name', 'like', "%{$search}%")
              ->orWhere('memo_number', 'like', "%{$search}%")
              ->orWhere('reference_number', 'like', "%{$search}%");
        });
    }

    /**
     * Get status badge color
     */
    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'unread' => 'danger',
            'read' => 'primary',
            'processed' => 'success',
            'archived' => 'secondary',
            'urgent' => 'warning',
            default => 'default'
        };
    }

    /**
     * Get priority badge color
     */
    public function getPriorityColorAttribute()
    {
        return match($this->priority) {
            'low' => 'secondary',
            'normal' => 'default',
            'high' => 'warning',
            'urgent' => 'danger',
            default => 'default'
        };
    }
}
