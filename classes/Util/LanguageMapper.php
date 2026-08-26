<?php

declare(strict_types=1);

namespace APP\plugins\generic\googleBooks\classes\Util;

final class LanguageMapper
{
    /**
     * ONIX List 74 uses ISO 639-2 three-letter language codes. Prefer the
     * terminology (T) forms where ISO 639-2 has distinct bibliographic (B)
     * aliases, because these are the modern canonical codes used by ONIX 3.0.
     */
    private const MAP = [
        'ar' => 'ara', 'de' => 'deu', 'en' => 'eng', 'es' => 'spa', 'fr' => 'fra',
        'it' => 'ita', 'ja' => 'jpn', 'ko' => 'kor', 'nl' => 'nld', 'pl' => 'pol',
        'pt' => 'por', 'ru' => 'rus', 'tr' => 'tur', 'uk' => 'ukr', 'zh' => 'zho',
        'ca' => 'cat', 'cs' => 'ces', 'da' => 'dan', 'el' => 'ell', 'fi' => 'fin',
        'hu' => 'hun', 'no' => 'nor', 'ro' => 'ron', 'sk' => 'slk', 'sv' => 'swe',
        'gn' => 'grn', 'gu' => 'guj',
    ];

    public static function toOnix(string $locale): string
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));
        $base = explode('_', $locale)[0] ?: 'en';
        return self::MAP[$base] ?? (strlen($base) === 3 ? $base : 'eng');
    }
}
