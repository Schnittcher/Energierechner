<?php

declare(strict_types=1);

require_once __DIR__ . '/ErTariff.php';
require_once __DIR__ . '/ErCalculator.php';

/**
 * Zusatzwerte zu den Rechenergebnissen: Durchschnittskosten je Tag, Vergleich mit dem Vorjahr, teuerster Tag
 * und die Eckdaten des aktuellen Tarifs. Reine Rechenlogik ohne IPS-Abhängigkeit.
 */
final class ErExtras
{
    /** Zeiträume mit Vorjahresvergleich (Verbrauch) */
    public const LAST_YEAR = ['Month_Current', 'Month_Previous', 'Year_Current', 'Year_Previous'];

    /** Zeiträume mit teuerstem Tag */
    public const PEAK = ['Week_Current', 'Week_Previous', 'Month_Current', 'Month_Previous', 'Year_Current', 'Year_Previous'];

    /**
     * Ergänzt ein Rechenergebnis um die Zusatzwerte. Fehlende Werte stehen auf 0.
     *
     * @param array<string, mixed> $result
     * @param array{lastYearConsumption?:float,peakDay?:int,peakCosts?:float} $extra
     * @return array<string, mixed>
     */
    public static function apply(array $result, array $extra): array
    {
        $days = (int) ($result['days'] ?? 0);
        $costs = (float) ($result['costs'] ?? 0.0);
        $result['avgPerDay'] = $days > 1 && $costs > 0.0 ? $costs / $days : 0.0;

        $lastYear = (float) ($extra['lastYearConsumption'] ?? 0.0);
        $result['lastYearConsumption'] = $lastYear;
        $result['vsLastYear'] = $lastYear > 0.0 ? ((float) ($result['consumption'] ?? 0.0) / $lastYear - 1.0) * 100.0 : 0.0;

        $result['peakDay'] = (int) ($extra['peakDay'] ?? 0);
        $result['peakCosts'] = (float) ($extra['peakCosts'] ?? 0.0);
        return $result;
    }

    /**
     * Der Tag mit den höchsten Kosten im Zeitraum [$start, $end), oder null ohne Kosten.
     *
     * @param array<int, array{start:int,end:int,value:float}> $intervals Archivintervalle des Zeitraums
     * @param array<string, mixed> $prices dynamische Preise wie für ErCalculator::calculate
     * @return array{day:int,costs:float}|null
     */
    public static function peakDay(array $intervals, ErTariff $tariff, float $unitFactor, bool $gas, int $start, int $end, array $prices, bool $includeBase): ?array
    {
        $byDay = [];
        foreach ($intervals as $interval) {
            $byDay[ErTariff::startOfDay($interval['start'])][] = $interval;
        }
        $best = null;
        for ($day = ErTariff::startOfDay($start); $day < $end; $day = ErTariff::nextDay($day)) {
            if (!isset($byDay[$day])) {
                continue;
            }
            $next = ErTariff::nextDay($day);
            $costs = ErCalculator::calculate($byDay[$day], $tariff, $unitFactor, $gas, $day, $next, $next, $includeBase, $prices)['costs'];
            if ($best === null || $costs > $best['costs']) {
                $best = ['day' => $day, 'costs' => $costs];
            }
        }
        return $best !== null && $best['costs'] > 0.0 ? $best : null;
    }

    /**
     * Eckdaten des zum Zeitpunkt gültigen Tarifabschnitts, oder null ohne Tarif.
     * Preise in ct je Einheit. "until" ist der letzte Tag der Gültigkeit (null bei offenem Ende),
     * "daysLeft" die Tage bis dahin (0 = letzter Tag ist heute, null bei offenem Ende).
     *
     * @return array{name:string,supplier:string,dynamic:bool,ht:float,nt:?float,until:?int,daysLeft:?int}|null
     */
    public static function currentTariff(ErTariff $tariff, int $now): ?array
    {
        $i = $tariff->indexAt($now);
        if ($i < 0) {
            return null;
        }
        $segment = $tariff->segments()[$i];
        $end = $tariff->segmentEnd($i, 0);
        $until = $end > 0 ? $end - 1 : null;
        $daysLeft = null;
        if ($until !== null) {
            // Kalendertage zählen (Sommer-/Winterzeit-sicher)
            $daysLeft = 0;
            $lastDay = ErTariff::startOfDay($until);
            for ($day = ErTariff::startOfDay($now); $day < $lastDay; $day = ErTariff::nextDay($day)) {
                $daysLeft++;
            }
        }
        return [
            'name'     => $segment->name,
            'supplier' => $segment->supplier,
            'dynamic'  => $segment->isDynamic(),
            'ht'       => round($segment->priceHt * 100, 4),
            'nt'       => $segment->hasNt() ? round($segment->priceNt * 100, 4) : null,
            'until'    => $until,
            'daysLeft' => $daysLeft
        ];
    }
}
