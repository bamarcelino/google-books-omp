<?php

declare(strict_types=1);

namespace APP\plugins\generic\googleBooks\classes\Util;

final class Xml
{
    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    public static function text(?string $value): string
    {
        return trim((string) $value);
    }
}
