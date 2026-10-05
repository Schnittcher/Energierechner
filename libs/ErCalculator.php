<?php

declare(strict_types=1);

require_once __DIR__ . '/ErTariff.php';
require_once __DIR__ . '/ErPriceSeries.php';

/**
 * Berechnet Verbrauch und Kosten eines Zeitraums aus Archivintervallen.
 * Reine Rechenlogik ohne IPS-Abhängigkeit.
 *
 * Intervalle: Liste von ['start' => int, 'end' => int, 'value' => float] (Verbrauch im Intervall, Zählerdelta).
 */
final class ErCalculator
{
    /**
     * @param array<int, array{start:int,end:int,value:float}> $intervals
     * @param float $unitFactor Faktor Zählerwert -> Ausgabeeinheit (z. B. 0.001 für Wh -> kWh, 1/Impulse je kWh)
     * @param bool $gas Verbrauch (m³) für die Preisberechnung in kWh umrechnen
     * @param int $periodStart Beginn (inklusive)
     * @param int $periodEnd Ende (exklusive)
     * @param int $now aktueller Zeitpunkt, begrenzt "aufgelaufene" Grund-/Abschlagskosten
     * @param bool $includeBase Grundpreis in die Gesamtkosten einrechnen
     * @param array<string, ErPriceSeries> $prices Preisreihen dynamischer Tarifabschnitte, Schlüssel = Id des Abschnitts
     * @return array<string, mixed>
     */
    public static function calculate(
        array $intervals,
        ErTariff $tariff,
        float $unitFactor,
        bool $gas,
        int $periodStart,
        int $periodEnd,
        int $now,
        bool $includeBase = true,
        array $prices = []
    ): array {
        $consumption = 0.0;
        $consumptionDay = 0.0;
        $consumptionNight = 0.0;
        $energy = 0.0;
        $workDay = 0.0;
        $workNight = 0.0;
        $noTariffConsumption = 0.0;
        $gasMissing = false;
        $priceFallback = false;

        foreach ($intervals as $iv) {
            $length = $iv['end'] - $iv['start'];
            if ($length <= 0) {
                continue;
            }
            $a = max($iv['start'], $periodStart);
            $b = min($iv['end'], $periodEnd);
            if ($b <= $a) {
                continue;
            }
            $value = $iv['value'] * $unitFactor;

            // Dynamische Preise ändern sich innerhalb eines Verbrauchsintervalls: an deren Grenzen zusätzlich teilen
            $extraCuts = [];
            foreach ($prices as $series) {
                foreach ($series->boundariesWithin($a, $b) as $cut) {
                    $extraCuts[] = $cut;
                }
            }

            foreach ($tariff->split($a, $b, $extraCuts) as $piece) {
                $v = $value * (($piece['to'] - $piece['from']) / $length);
                $consumption += $v;
                $seg = $piece['seg'];
                if ($seg === null) {
                    $noTariffConsumption += $v;
                    continue;
                }

                $basis = $v;
                if ($gas) {
                    $perUnit = $seg->gasKwhPerUnit();
                    if ($perUnit <= 0.0) {
                        $gasMissing = true;
                    }
                    $basis = $v * $perUnit;
                    $energy += $basis;
                }

                // Fester Preis; bei dynamischem Tarif der Preis der Reihe, ohne Preis der feste Preis als Ausweichwert
                $price = $piece['night'] ? $seg->priceNight : $seg->priceDay;
                if ($seg->isDynamic()) {
                    $dynamic = isset($prices[$seg->id]) ? $prices[$seg->id]->priceAt($piece['from']) : null;
                    if ($dynamic === null) {
                        $priceFallback = true;
                    } else {
                        $price = $dynamic * $seg->priceFactor + $seg->surcharge;
                    }
                }

                if ($piece['night']) {
                    $consumptionNight += $v;
                    $workNight += $basis * $price;
                } else {
                    $consumptionDay += $v;
                    $workDay += $basis * $price;
                }
            }
        }

        $accrual = self::accrue($tariff, $periodStart, $periodEnd, $now);
        $work = $workDay + $workNight;
        $base = $includeBase ? $accrual['base'] : 0.0;
        $costs = $work + $base;

        $warnings = [];
        if ($accrual['noTariffDays'] > 0 || $noTariffConsumption > 0.0) {
            $warnings[] = 'noTariff';
        }
        if ($gasMissing) {
            $warnings[] = 'gasParameters';
        }
        if ($priceFallback) {
            $warnings[] = 'priceFallback';
        }

        $result = [
            'consumption'         => $consumption,
            'consumptionDay'      => $consumptionDay,
            'consumptionNight'    => $consumptionNight,
            'energy'              => $energy,
            'costs'               => $costs,
            'costsWork'           => $work,
            'costsBase'           => $base,
            'costsDay'            => $workDay,
            'costsNight'          => $workNight,
            'advance'             => $accrual['advance'],
            'balance'             => $accrual['advance'] - $costs,
            'days'                => $accrual['days'],
            'forecastCosts'       => null,
            'forecastConsumption' => null,
            'warnings'            => $warnings
        ];

        // Prognose nur für laufende Zeiträume, lineare Hochrechnung nach verstrichener Zeit
        $span = $periodEnd - $periodStart;
        $elapsed = $now - $periodStart;
        if ($periodEnd > $now && $span > 0 && $elapsed >= 6 * 3600) {
            $fraction = $elapsed / $span;
            $result['forecastConsumption'] = $consumption / $fraction;
            $result['forecastCosts'] = $work / $fraction + ($includeBase ? $accrual['baseFull'] : 0.0);
        }

        return $result;
    }

    /**
     * Grundpreis und Abschlag tageweise: Jahreswert / Tage des Kalenderjahres je Tag.
     * "Aufgelaufen" zählt alle Tage bis einschließlich heute, "Full" bis zum Periodenende.
     *
     * @return array{base:float,baseFull:float,advance:float,days:int,noTariffDays:int}
     */
    public static function accrue(ErTariff $tariff, int $periodStart, int $periodEnd, int $now): array
    {
        $base = 0.0;
        $baseFull = 0.0;
        $advance = 0.0;
        $days = 0;
        $noTariff = 0;

        $day = ErTariff::startOfDay($periodStart);
        while ($day < $periodEnd) {
            $daysInYear = ((int) date('L', $day)) === 1 ? 366 : 365;
            $seg = $tariff->segmentAt($day);
            $accrued = $day <= $now;

            if ($seg !== null) {
                $dayBase = $seg->baseYear / $daysInYear;
                $baseFull += $dayBase;
                if ($accrued) {
                    $base += $dayBase;
                    $advance += $seg->advance * $seg->advanceCount / $daysInYear;
                }
            } elseif ($accrued) {
                $noTariff++;
            }
            if ($accrued) {
                $days++;
            }
            $day = ErTariff::nextDay($day);
        }
        return ['base' => $base, 'baseFull' => $baseFull, 'advance' => $advance, 'days' => $days, 'noTariffDays' => $noTariff];
    }

    /**
     * Summiert mehrere Ergebnisse (z. B. alle Tarifzeiträume zur Gesamtsumme).
     *
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    public static function sum(array $results): array
    {
        $keys = ['consumption', 'consumptionDay', 'consumptionNight', 'energy', 'costs', 'costsWork', 'costsBase', 'costsDay', 'costsNight', 'advance', 'balance', 'days'];
        $sum = array_fill_keys($keys, 0.0);
        $warnings = [];
        foreach ($results as $r) {
            foreach ($keys as $k) {
                $sum[$k] += (float) ($r[$k] ?? 0);
            }
            $warnings = array_merge($warnings, $r['warnings'] ?? []);
        }
        $sum['forecastCosts'] = null;
        $sum['forecastConsumption'] = null;
        $sum['warnings'] = array_values(array_unique($warnings));
        return $sum;
    }
}
