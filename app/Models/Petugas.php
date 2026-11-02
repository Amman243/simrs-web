<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class Petugas extends KhanzaModel
{
    protected $table = 'petugas';

    protected $primaryKey = 'nip';

    public function scopeAktif(Builder $query): void
    {
        $query->where('status', '1');
    }
}
