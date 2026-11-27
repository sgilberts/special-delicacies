<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class GhanaPhoneNumber
{
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) $digits = '233'.substr($digits, 1);
        elseif (strlen($digits) === 9 && in_array($digits[0], ['2', '5'], true)) $digits = '233'.$digits;
        if (! preg_match('/^233[25]\d{8}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => ['Enter a valid Ghanaian phone number.']]);
        }
        return '+'.$digits;
    }

    public static function lookupVariants(string $normalized): array
    {
        $digits = substr($normalized, 1);
        return ['+'.$digits, $digits, '0'.substr($digits, 3)];
    }

    /**
     * Decide whether two stored numbers are the same contact detail. Comparing
     * raw strings would treat a reformatted number as a new number and needlessly
     * clear an existing verification; unparseable values fall back to the
     * trimmed comparison so existing behaviour is preserved.
     */
    public static function sameNumber(?string $first, ?string $second): bool
    {
        if ($first === null || $second === null) return $first === $second;
        try {
            return self::normalize($first) === self::normalize($second);
        } catch (ValidationException) {
            return trim($first) === trim($second);
        }
    }
}
