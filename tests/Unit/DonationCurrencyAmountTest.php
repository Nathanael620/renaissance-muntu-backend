<?php

namespace Tests\Unit;

use App\Support\DonationCurrencyAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DonationCurrencyAmountTest extends TestCase
{
    public function test_amount_is_stored_as_a_two_decimal_string_without_float_multiplication(): void
    {
        $this->assertSame('25.10', DonationCurrencyAmount::toStorageDecimal('25.1', 'CAD'));
    }

    #[DataProvider('supportedStorageAmounts')]
    public function test_storage_precision_accepts_values_that_fit_the_decimal_column(string $amount): void
    {
        $this->assertTrue(DonationCurrencyAmount::hasSupportedPrecision($amount, 'CAD'));
        $this->assertTrue(DonationCurrencyAmount::fitsStorageRange($amount));
    }

    public static function supportedStorageAmounts(): array
    {
        return [
            'integer' => ['25'],
            'two decimal places' => ['25.10'],
            'extra trailing zeroes' => ['25.1000'],
            'maximum whole-number width' => ['99999999.99'],
        ];
    }

    #[DataProvider('unsupportedStorageAmounts')]
    public function test_storage_precision_rejects_values_that_do_not_fit(string $amount): void
    {
        $this->assertFalse(DonationCurrencyAmount::hasSupportedPrecision($amount, 'CAD'));
    }

    public static function unsupportedStorageAmounts(): array
    {
        return [
            'non-zero third decimal' => ['25.101'],
            'negative amount' => ['-25.00'],
            'scientific notation' => ['2.5e1'],
        ];
    }

    public function test_storage_range_rejects_an_integer_part_that_exceeds_the_decimal_column(): void
    {
        $this->assertFalse(DonationCurrencyAmount::fitsStorageRange('100000000.00'));
    }

    #[DataProvider('stripeMinorUnitAmounts')]
    public function test_stripe_minor_units_follow_the_currency_exponent(string $amount, string $currency, int $minorUnits): void
    {
        $this->assertSame($minorUnits, DonationCurrencyAmount::toMinorUnits($amount, $currency));
    }

    public static function stripeMinorUnitAmounts(): array
    {
        return [
            'CAD two decimal places' => ['25.10', 'CAD', 2510],
            'JPY zero decimal places' => ['25', 'JPY', 25],
            'KWD three decimal places' => ['25.123', 'KWD', 25123],
            'ISK Stripe special case' => ['25', 'ISK', 2500],
        ];
    }

    public function test_minor_unit_conversion_rejects_non_zero_fraction_beyond_currency_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DonationCurrencyAmount::toMinorUnits('25.01', 'JPY');
    }

    public function test_storage_conversion_rejects_a_three_decimal_value_for_a_two_decimal_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DonationCurrencyAmount::toStorageDecimal('25.123', 'KWD');
    }

    public function test_minor_unit_conversion_rejects_invalid_currency_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DonationCurrencyAmount::toMinorUnits('25.00', 'CAD!');
    }
}
