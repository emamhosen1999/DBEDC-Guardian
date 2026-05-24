<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PettyCashTransaction extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'petty_cash_loan_id',
        'type',
        'category',
        'amount',
        'description',
        'transaction_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function pettyCashLoan(): BelongsTo
    {
        return $this->belongsTo(PettyCashLoan::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('bills')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'])
            ->maxNumberOfFiles(10);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }
}
