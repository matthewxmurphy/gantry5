<?php
/** Literal client text, including downstream WordPress shortcode contexts. Matthew C. Murphy, 2026. MIT. */
namespace Gantry\Component\ClientEditing;

class SafeText
{
    public static function escape($value)
    {
        $text = is_string($value) ? $value : '';
        return str_replace(['[', ']'], ['&#91;', '&#93;'],
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
