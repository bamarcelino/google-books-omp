<?php

declare(strict_types=1);

namespace APP\plugins\generic\googleBooks\classes\Util;

final class LanguageMapper
{
    private const MAP = [
        'ar' => 'ara', 'de' => 'ger', 'en' => 'eng', 'es' => 'spa', 'fr' => 'fre',
        'it' => 'ita', 'ja' => 'jpn', 'ko' => 'kor', 'nl' => 'dut', 'pl' => 'pol',
        'pt' => 'por', 'ru' => 'rus', 'tr' => 'tur', 'uk' => 'ukr', 'zh' => 'chi',
        'ca' => 'cat', 'cs' => 'cze', 'da' => 'dan', 'el' => 'gre', 'fi' => 'fin',
        'hu' => 'hun', 'no' => 'nor', 'ro' => 'rum', 'sk' => 'slo', 'sv' => 'swe',
    ];

    public static function toOnix(string $locale): string
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));
        $base = explode('_', $locale)[0] ?: 'en';
        return self::MAP[$base] ?? (strlen($base) === 3 ? $base : 'eng');
    }
}
