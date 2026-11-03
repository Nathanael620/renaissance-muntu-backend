<?php

namespace App\Support;

use InvalidArgumentException;

class DonationCurrencyAmount
{
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
        'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    public static function hasSupportedPrecision(mixed $amount, string $currency): bool
    {
        $decimal = self::decimalString($amount);

        if ($decimal === null) {
            return false;
        }

        $fraction = rtrim(explode('.', $decimal, 2)[1] ?? '', '0');

        return strlen($fraction) <= self::maximumInputDecimalPlaces($currency);
    }

    public static function fitsStorageRange(mixed $amount): bool
    {
        $decimal = self::decimalString($amount);

        if ($decimal === null) {
            return false;
        }

        $whole = ltrim(explode('.', $decimal, 2)[0], '0');

        return strlen($whole) <= 8;
    }

    public static function toStorageDecimal(mixed $amount, string $currency): string
    {
        $decimal = self::decimalString($amount);

        if ($decimal === null
            || ! self::hasSupportedPrecision($amount, $currency)
            || ! self::fitsStorageRange($amount)) {
            throw new InvalidArgumentException('Donation amount exceeds the supported storage precision.');
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = substr(rtrim($fraction, '0'), 0, 2);

        return ($whole === '' ? '0' : $whole).'.'.str_pad($fraction, 2, '0');
    }

    public static function toMinorUnits(string|int|float $amount, string $currency): int
    {
        $decimal = self::decimalString($amount);

        if ($decimal === null) {
            throw new InvalidArgumentException('Donation amount is not a plain decimal value.');
        }

        $exponent = self::stripeMinorUnitExponent($currency);
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        if (strlen($fraction) > $exponent) {
            $excessFraction = substr($fraction, $exponent);

            if (trim($excessFraction, '0') !== '') {
                throw new InvalidArgumentException('Donation amount exceeds the Stripe currency precision.');
            }

            $fraction = substr($fraction, 0, $exponent);
        }

        $fractionalMinorUnits = $exponent === 0
            ? 0
            : (int) str_pad($fraction, $exponent, '0');

        return ((int) $whole * (10 ** $exponent)) + $fractionalMinorUnits;
    }

    private static function stripeMinorUnitExponent(string $currency): int
    {
        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Donation currency must be a three-letter ISO code.');
        }

        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return in_array($currency, ['ISK', 'UGX'], true) ? 2 : 0;
        }

        return 2;
    }

    private static function maximumInputDecimalPlaces(string $currency): int
    {
        $currency = strtoupper($currency);
        $exponent = self::stripeMinorUnitExponent($currency);

        if (in_array($currency, ['ISK', 'UGX'], true)) {
            return 0;
        }

        return min($exponent, 2);
    }

    private static function decimalString(mixed $amount): ?string
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }

        if (is_float($amount) && ! is_finite($amount)) {
            return null;
        }

        $decimal = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d+)?$/D', $decimal)) {
            return null;
        }

        return $decimal;
    }
}
