<?php

namespace App\Models;

class Pegawai extends KhanzaModel
{
    protected $table = 'pegawai';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';
}
