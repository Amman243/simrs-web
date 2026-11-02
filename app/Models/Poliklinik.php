<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class Poliklinik extends KhanzaModel
{
    protected $table = 'poliklinik';

    protected $primaryKey = 'kd_poli';

    public function scopeAktif(Builder $query): void
    {
        $query->where('status', '1');
    }
}
