<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\CurrencyConverter;
use Brick\Money\Exception\CurrencyConversionException;
use Brick\Money\ExchangeRateProvider\ConfigurableProvider;
use Brick\Money\Money;

class CurrencyService
{
    private const string BASE = 'MYR';

    /**
     * Mid-market rates as of 2026-05-21 (BNM Interbank Foreign Exchange Market).
     * All rates are normalised to 1 unit of source currency = X MYR.
     */
    private const array RATES_TO_MYR = [
        // Major global & regional
        'USD' => '3.9745',
        'EUR' => '4.6283',
        'GBP' => '5.3334',
        'SGD' => '3.1062',
        'AUD' => '2.8422',
        'CAD' => '2.8910',
        'CHF' => '5.0614',
        'JPY' => '0.024995',   // 100 JPY = 2.4995 MYR  → 1 JPY = 0.024995
        'CNY' => '0.5845',
        'NZD' => '2.3293',

        // Asia-Pacific
        'BND' => '3.1062',
        'HKD' => '0.507563',   // 100 HKD = 50.7563 MYR → 1 HKD = 0.507563
        'IDR' => '0.000225',   // 1000 IDR = 0.2250 MYR  → 1 IDR = 0.000225
        'INR' => '0.041251',   // 100 INR = 4.1251 MYR   → 1 INR = 0.041251
        'KRW' => '0.002650',   // 100 KRW = 0.2650 MYR   → 1 KRW = 0.002650
        'PHP' => '0.064438',   // 100 PHP = 6.4438 MYR   → 1 PHP = 0.064438
        'THB' => '0.121992',   // 100 THB = 12.1992 MYR  → 1 THB = 0.121992
        'TWD' => '0.125799',   // 100 TWD = 12.5799 MYR  → 1 TWD = 0.125799
        'VND' => '0.000151',   // 1000 VND = 0.1510 MYR  → 1 VND = 0.000151
        'KHR' => '0.000985',   // 1000 KHR = 0.9850 MYR  → 1 KHR = 0.000985
        'MMK' => '0.001898',   // 100 MMK = 0.1898 MYR   → 1 MMK = 0.001898
        'PKR' => '0.014266',   // 100 PKR = 1.4266 MYR   → 1 PKR = 0.014266
        'NPR' => '0.025782',   // 100 NPR = 2.5782 MYR   → 1 NPR = 0.025782

        // Middle East & Africa
        'SAR' => '1.0590',
        'AED' => '1.0821',
        'EGP' => '0.0745',
        'ZAR' => '0.2285',
    ];

    private CurrencyConverter $converter;

    public function __construct()
    {
        $provider = new ConfigurableProvider();

        foreach (self::RATES_TO_MYR as $code => $rate) {
            // source → MYR
            $provider->setExchangeRate($code, self::BASE, $rate);
            // MYR → source (reciprocal)
            $provider->setExchangeRate(self::BASE, $code, bcdiv('1', $rate, 10));
        }

        // Same-currency passthrough
        foreach (array_keys(self::RATES_TO_MYR) as $code) {
            $provider->setExchangeRate($code, $code, '1');
        }
        $provider->setExchangeRate(self::BASE, self::BASE, '1');

        $this->converter = new CurrencyConverter($provider);
    }

    /**
     * Convert a minor-unit integer amount from one currency to another.
     * Returns the result in minor units of the target currency.
     *
     * @throws CurrencyConversionException if either currency is unsupported or
     *                                     a cross-rate pair has no path through MYR.
     */
    public function convertMinorUnits(int $amount, string $from, string $to): int
    {
        if ($from === $to) {
            return $amount;
        }

        $money = Money::ofMinor($amount, Currency::of($from));

        if ($from !== self::BASE && $to !== self::BASE) {
            // Cross-rate via MYR
            $inMyr = $this->converter->convert($money, Currency::of(self::BASE), null, RoundingMode::HalfUp);
            $result = $this->converter->convert($inMyr, Currency::of($to), null, RoundingMode::HalfUp);
        } else {
            $result = $this->converter->convert($money, Currency::of($to), null, RoundingMode::HalfUp);
        }

        return $result->getMinorAmount()->toBigInteger()->toInt();
    }

    /**
     * Convert a decimal amount from one currency to another.
     * Returns a Money instance in the target currency.
     *
     * @throws CurrencyConversionException
     */
    public function convert(Money $money, string $to): Money
    {
        $from = $money->getCurrency()->getCurrencyCode();

        if ($from === $to) {
            return $money;
        }

        if ($from !== self::BASE && $to !== self::BASE) {
            $inMyr = $this->converter->convert($money, Currency::of(self::BASE), null, RoundingMode::HalfUp);
            return $this->converter->convert($inMyr, Currency::of($to), null, RoundingMode::HalfUp);
        }

        return $this->converter->convert($money, Currency::of($to), null, RoundingMode::HalfUp);
    }

    /**
     * Returns true if the given ISO code is supported by this service.
     */
    public function supports(string $currencyCode): bool
    {
        return $currencyCode === self::BASE || isset(self::RATES_TO_MYR[$currencyCode]);
    }

    /**
     * Returns all supported ISO currency codes.
     *
     * @return string[]
     */
    public function supportedCurrencies(): array
    {
        return [self::BASE, ...array_keys(self::RATES_TO_MYR)];
    }
}
