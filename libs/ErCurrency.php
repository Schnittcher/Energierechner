<?php

declare(strict_types=1);

/**
 * Währungen für die Beschriftung von Beträgen und Preisen. Das Modul rechnet nicht um: Die Zahl im Tarif sind Einheiten der
 * Währung je Einheit des Zählers. Reine Logik ohne IPS-Abhängigkeit.
 *
 * "prefix" und "suffix" stehen vor und nach dem Betrag (CHF 12.50, £12.50, 12,50 €), "symbol" ohne Leerzeichen für Preiseinheiten
 * (€/kWh). Die Untereinheit ist bei allen Währungen 1/100. "priceInMinor" sagt, ob Preise je kWh üblicherweise in der Untereinheit
 * stehen (30,73 ct/kWh) oder in der Hauptwährung (0,7480 zł/kWh).
 */
final class ErCurrency
{
    public const DEFAULT = 'EUR';

    /** Umrechnung Hauptwährung -> Untereinheit */
    public const MINOR_FACTOR = 100;

    private const CURRENCIES = [
        'EUR' => ['symbol' => '€', 'prefix' => '', 'suffix' => ' €', 'minor' => 'ct', 'priceInMinor' => true],
        'CHF' => ['symbol' => 'CHF', 'prefix' => 'CHF ', 'suffix' => '', 'minor' => 'Rp.', 'priceInMinor' => true],
        'GBP' => ['symbol' => '£', 'prefix' => '£', 'suffix' => '', 'minor' => 'p', 'priceInMinor' => true],
        'USD' => ['symbol' => '$', 'prefix' => '$', 'suffix' => '', 'minor' => '¢', 'priceInMinor' => true],
        'SEK' => ['symbol' => 'kr', 'prefix' => '', 'suffix' => ' kr', 'minor' => 'öre', 'priceInMinor' => true],
        'NOK' => ['symbol' => 'kr', 'prefix' => '', 'suffix' => ' kr', 'minor' => 'øre', 'priceInMinor' => true],
        'DKK' => ['symbol' => 'kr', 'prefix' => '', 'suffix' => ' kr', 'minor' => 'øre', 'priceInMinor' => true],
        'PLN' => ['symbol' => 'zł', 'prefix' => '', 'suffix' => ' zł', 'minor' => 'gr', 'priceInMinor' => false],
        'CZK' => ['symbol' => 'Kč', 'prefix' => '', 'suffix' => ' Kč', 'minor' => 'h', 'priceInMinor' => false]
    ];

    /** @return string[] Währungscodes in der Reihenfolge der Auswahl */
    public static function codes(): array
    {
        return array_keys(self::CURRENCIES);
    }

    /** Unbekannte oder leere Codes ergeben den Euro. */
    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));
        return isset(self::CURRENCIES[$code]) ? $code : self::DEFAULT;
    }

    /**
     * @return array{code:string,symbol:string,prefix:string,suffix:string,minor:string,priceInMinor:bool}
     */
    public static function info(string $code): array
    {
        $code = self::normalize($code);
        return ['code' => $code] + self::CURRENCIES[$code];
    }

    /**
     * Einheit, in der Preise je Einheit des Zählers angezeigt werden: die Untereinheit (ct) oder das Symbol der Hauptwährung (zł).
     */
    public static function priceUnit(string $code): string
    {
        $info = self::info($code);
        return $info['priceInMinor'] ? $info['minor'] : $info['symbol'];
    }

    /**
     * Rechnet einen Preis in der Hauptwährung je Einheit in den Wert um, der angezeigt wird (in der Untereinheit oder unverändert).
     */
    public static function displayPrice(string $code, float $price): float
    {
        return self::info($code)['priceInMinor'] ? $price * self::MINOR_FACTOR : $price;
    }
}
