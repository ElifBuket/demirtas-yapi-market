<?php
declare(strict_types=1);

namespace App\Services;

/** Kullanıcıya olduğu gibi gösterilebilecek iş kuralı hatası (limit aşımı, engelli cari, yetersiz stok vb.). */
final class IsHatasi extends \RuntimeException {}
