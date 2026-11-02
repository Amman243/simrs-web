<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Penanggung jawab / cara bayar (UMUM, BPJS, asuransi, ...).
 */
class Penjab extends KhanzaModel
{
    protected $table = 'penjab';

    protected $primaryKey = 'kd_pj';

    public function scopeAktif(Builder $query): void
    {
        $query->where('status', '1');
    }
}
