<?php

declare(strict_types=1);

namespace APP\plugins\generic\googleBooks\classes\Util;

final class Xml
{
    public static function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function element(string $name, ?string $value, int $indent = 0, array $attributes = []): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $attrs = '';
        foreach ($attributes as $key => $attrValue) {
            $attrs .= ' ' . $key . '="' . self::esc((string) $attrValue) . '"';
        }
        return str_repeat('  ', $indent) . '<' . $name . $attrs . '>' . self::esc($value) . '</' . $name . ">\n";
    }
}
