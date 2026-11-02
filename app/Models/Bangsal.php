<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Bangsal extends KhanzaModel
{
    protected $table = 'bangsal';

    protected $primaryKey = 'kd_bangsal';

    public function kamar(): HasMany
    {
        return $this->hasMany(Kamar::class, 'kd_bangsal', 'kd_bangsal');
    }
}
