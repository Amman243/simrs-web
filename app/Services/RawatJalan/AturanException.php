<?php

namespace App\Services\RawatJalan;

use RuntimeException;

/**
 * Pelanggaran aturan bisnis Khanza (kunci billing, batas 48 jam, kamar inap, dst.).
 * Pesannya aman ditampilkan ke pengguna (toast).
 */
class AturanException extends RuntimeException {}
