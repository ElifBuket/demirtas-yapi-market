<?php
declare(strict_types=1);

namespace App\Services\Asistan;

use App\Core\Config;

/** Claude Messages API ile araç kullanan döngü. Yalnızca Araclar'daki salt okunur araçlar sunulur. */
final class Llm
{
    private const URL = 'https://api.anthropic.com/v1/messages';
    private const MAKS_TUR = 5;

    /** @param null|callable(array):array $http gövde dizisi alır, çözümlenmiş yanıt dizisi döner (test için) */
    public static function calistir(string $soru, array $kul, string $sistem, string $anahtar, ?callable $http = null): array
    {
        $araclar = array_values(array_filter(Araclar::tanimlar(), fn($t) => Araclar::izinli($t['name'], $kul)));
        $sunulan = array_map(fn($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['input_schema']], $araclar);
        $mesajlar = [['role' => 'user', 'content' => $soru]];
        $kullanilan = [];
        for ($tur = 0; $tur < self::MAKS_TUR; $tur++) {
            $govde = ['model' => Config::env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'), 'max_tokens' => 1024, 'system' => $sistem, 'tools' => $sunulan, 'messages' => $mesajlar];
            $y = $http ? $http($govde) : self::istek($govde, $anahtar);
            $icerik = $y['content'] ?? [];
            if (($y['stop_reason'] ?? '') !== 'tool_use') {
                $metin = trim(implode("\n", array_map(fn($b) => $b['text'] ?? '', array_filter($icerik, fn($b) => ($b['type'] ?? '') === 'text'))));
                return Asistan::yanit($metin !== '' ? $metin : 'Cevap üretilemedi.', null, 'yapay_zeka', array_values(array_unique($kullanilan)));
            }
            $mesajlar[] = ['role' => 'assistant', 'content' => $icerik];
            $sonuclar = [];
            foreach ($icerik as $b) {
                if (($b['type'] ?? '') !== 'tool_use') continue;
                $kullanilan[] = $b['name'];
                // Yalnızca sunulan araçlar çalışır; model başka bir ad uydursa bile Araclar::calistir reddeder.
                $r = Araclar::calistir((string)$b['name'], (array)($b['input'] ?? []), $kul);
                $sonuclar[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)];
            }
            $mesajlar[] = ['role' => 'user', 'content' => $sonuclar];
        }
        return Asistan::yanit('Soru çok fazla adım gerektirdi; daha kısa bir soru deneyin.', null, 'yapay_zeka', array_values(array_unique($kullanilan)));
    }

    private static function istek(array $govde, string $anahtar): array
    {
        $ch = curl_init(self::URL);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $anahtar, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode($govde, JSON_UNESCAPED_UNICODE)]);
        $r = curl_exec($ch); $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($r === false || $kod !== 200) throw new \RuntimeException('LLM isteği başarısız: ' . $kod);
        $j = json_decode((string)$r, true);
        if (!is_array($j)) throw new \RuntimeException('LLM yanıtı çözümlenemedi');
        return $j;
    }
}
