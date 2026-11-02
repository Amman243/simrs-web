<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Basis model untuk tabel Khanza: PK string, tanpa timestamps.
 * Tabel dengan PK komposit (kamar_inap, rawat_jl_*, pemeriksaan_*, dll.)
 * ditangani lewat query builder di service modul, bukan Eloquent.
 */
abstract class KhanzaModel extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
