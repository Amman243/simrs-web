<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kamar extends KhanzaModel
{
    protected $table = 'kamar';

    protected $primaryKey = 'kd_kamar';

    public function bangsal(): BelongsTo
    {
        return $this->belongsTo(Bangsal::class, 'kd_bangsal', 'kd_bangsal');
    }

    public function scopeKosong(Builder $query): void
    {
        $query->where('status', 'KOSONG')->where('statusdata', '1');
    }
}
