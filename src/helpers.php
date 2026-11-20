<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Http;

/** HTML çıktısını kaçırır (XSS). Şablonlarda HER dinamik değer e() ile yazılır. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Uygulama içi bağlantı (uygulama alt klasörde çalışsa bile doğru çalışır). */
function url(string $path = '/'): string
{
    return Http::basePath() . $path;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

/** "-1234.5" => "-1.234,50"  (metin üzerinde çalışır, float kullanmaz) */
function money(string|int|float|null $dec): string
{
    if ($dec === null || $dec === '') return '';
    $s = is_string($dec) ? $dec : number_format((float)$dec, 2, '.', '');
    $neg = str_starts_with($s, '-');
    $s = ltrim($s, '-');
    [$i, $f] = array_pad(explode('.', $s, 2), 2, '00');
    $f = str_pad(substr($f, 0, 2), 2, '0');
    $i = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $i);
    return ($neg ? '-' : '') . $i . ',' . $f;
}

/** Tek seferlik mesaj (işlemden sonra yönlendirilen sayfada gösterilir). */
function flash_koy(string $tur, string $mesaj): void
{
    $_SESSION['flash'] = ['tur' => $tur, 'mesaj' => $mesaj];
}

function flash_al(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
}

/** Stok miktarını gösterir: "230.000" => "230", "12.500" => "12,5" */
function miktar(string|int|null $dec): string
{
    if ($dec === null || $dec === '') return '';
    return \App\Services\Birim::goster(\App\Services\Birim::fromDecimal((string)$dec));
}

/** DECIMAL(15,2) metni → "1.234,50" */
function tl(string|int|null $dec): string
{
    if ($dec === null || $dec === '') return '';
    return \App\Services\Hesap::goster(\App\Services\Hesap::kurus((string)$dec));
}

/** DECIMAL(15,4) fiyat → "12,5" (gereksiz sıfırlar atılır, en az 2 ondalık) */
function fiyat_goster(string $dec): string
{
    [$t, $o] = explode('.', $dec . '.');
    $o = rtrim($o, '0'); $o = str_pad($o, 2, '0');
    return number_format((int)$t, 0, ',', '.') . ',' . $o;
}

function durum_ad(string $d): string
{
    return ['onay_bekliyor' => 'Onay bekliyor', 'onayli' => 'Onaylı', 'kismi_sevk' => 'Kısmi sevk', 'sevk_edildi' => 'Sevk edildi',
            'faturalandi' => 'Faturalandı', 'iptal' => 'İptal', 'portfoyde' => 'Portföyde', 'alindi' => 'Alındı',
            'tahsil_edildi' => 'Tahsil edildi', 'karsiliksiz' => 'Karşılıksız'][$d] ?? $d;
}
