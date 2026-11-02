<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class Dokter extends KhanzaModel
{
    protected $table = 'dokter';

    protected $primaryKey = 'kd_dokter';

    public function scopeAktif(Builder $query): void
    {
        $query->where('status', '1');
    }
}
