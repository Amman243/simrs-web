<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registrasi kunjungan (rawat jalan, IGD, dan induk rawat inap).
 */
class RegPeriksa extends KhanzaModel
{
    protected $table = 'reg_periksa';

    protected $primaryKey = 'no_rawat';

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'no_rkm_medis', 'no_rkm_medis');
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(Dokter::class, 'kd_dokter', 'kd_dokter');
    }

    public function poliklinik(): BelongsTo
    {
        return $this->belongsTo(Poliklinik::class, 'kd_poli', 'kd_poli');
    }

    public function penjab(): BelongsTo
    {
        return $this->belongsTo(Penjab::class, 'kd_pj', 'kd_pj');
    }
}
