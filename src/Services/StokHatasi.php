<?php
declare(strict_types=1);

namespace App\Services;

/** Kullanıcıya olduğu gibi gösterilebilecek iş kuralı hatası (yetersiz stok, geçersiz miktar vb.). */
final class StokHatasi extends \RuntimeException
{
}
