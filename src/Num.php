<?php
declare(strict_types=1);

/** Excel'de SAYI olarak saklanan hücre. Metin hücrelerden ayırt edebilmek için ayrı tip (9.999 sayı ≠ "9.999" metin). */
final class Num
{
    public function __construct(public readonly string $raw) {}
    public function __toString(): string { return $this->raw; }
}
