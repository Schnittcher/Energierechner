<?php

declare(strict_types=1);

/**
 * Saisonaler Anteil für die Prognose: Welchen Teil seines Verbrauchs hatte ein Zeitraum im Vorjahr
 * zum gleichen Zeitpunkt schon erreicht? Damit lässt sich der laufende Zeitraum hochrechnen, ohne
 * dass Winter- oder Sommerverbrauch linear auf das ganze Jahr verteilt wird.
 * Reine Rechenlogik ohne IPS-Abhängigkeit.
 */
final class ErForecast
{
    /** Weicht der Vorjahresanteil um mehr als diesen Faktor vom zeitlichen Anteil ab, gilt er als unglaubwürdig. */
    private const MIN_RATIO = 0.3;
    private const MAX_RATIO = 3.3;

    /** Derselbe Zeitpunkt ein Kalenderjahr früher (29.02. wird zum 01.03.). */
    public static function lastYear(int $ts): int
    {
        return mktime(
            (int) date('G', $ts),
            (int) date('i', $ts),
            (int) date('s', $ts),
            (int) date('n', $ts),
            (int) date('j', $ts),
            (int) date('Y', $ts) - 1
        );
    }

    /**
     * Anteil des Zeitraums-Verbrauchs, der im Vorjahr bis zum entsprechenden Zeitpunkt angefallen war (0 bis 1),
     * oder null, wenn die Vorjahresdaten nicht reichen oder nicht plausibel sind. Dann gilt die lineare Prognose.
     *
     * @param array<int, array{start:int,end:int,value:float}> $dailyIntervals Tageswerte des Vorjahres, aufsteigend
     * @param int $start Beginn des laufenden Zeitraums (inklusive)
     * @param int $end Ende des laufenden Zeitraums (exklusive)
     * @param int $now aktueller Zeitpunkt
     */
    public static function seasonalShare(array $dailyIntervals, int $start, int $end, int $now): ?float
    {
        $lastStart = self::lastYear($start);
        $lastEnd = self::lastYear($end);
        $lastNow = min(self::lastYear($now), $lastEnd);
        if ($lastEnd <= $lastStart || $lastNow <= $lastStart || count($dailyIntervals) === 0) {
            return null;
        }

        $full = self::clippedSum($dailyIntervals, $lastStart, $lastEnd);
        if ($full <= 0.0) {
            return null;
        }
        $share = self::clippedSum($dailyIntervals, $lastStart, $lastNow) / $full;
        if ($share <= 0.0 || $share > 1.0) {
            return null;
        }

        // Plausibilität: ein extrem anderer Verlauf als der zeitliche Anteil deutet auf lückenhafte Vorjahresdaten hin
        $linear = ($lastNow - $lastStart) / ($lastEnd - $lastStart);
        $ratio = $share / $linear;
        return $ratio >= self::MIN_RATIO && $ratio <= self::MAX_RATIO ? $share : null;
    }

    /**
     * Summe der Intervallwerte in [$from, $to); Intervalle, die den Bereich nur teilweise abdecken, zählen anteilig.
     *
     * @param array<int, array{start:int,end:int,value:float}> $intervals
     */
    private static function clippedSum(array $intervals, int $from, int $to): float
    {
        $sum = 0.0;
        foreach ($intervals as $interval) {
            $length = $interval['end'] - $interval['start'];
            $a = max($interval['start'], $from);
            $b = min($interval['end'], $to);
            if ($length > 0 && $b > $a) {
                $sum += $interval['value'] * (($b - $a) / $length);
            }
        }
        return $sum;
    }
}
