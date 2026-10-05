<?php

declare(strict_types=1);

require_once __DIR__ . '/ErTariff.php';

/**
 * Zeiträume und stabile Variablen-Idents.
 *
 * Ident-Schema: <Zeitraum>_<Art>, z. B. Month_Current_Costs, Tariff_a1b2c3d4_Balance, Custom_x9y8z7w6_Consumption.
 * Die Idents enthalten kein Datum und bleiben stabil, wenn sich Start- oder Enddaten ändern.
 */
final class ErPeriods
{
    /** Eigenschaft (Formular) => Zeitraum-Schlüssel. */
    public const FIXED = [
        'DayCurrent'    => 'Day_Current',
        'DayPrevious'   => 'Day_Previous',
        'WeekCurrent'   => 'Week_Current',
        'WeekPrevious'  => 'Week_Previous',
        'MonthCurrent'  => 'Month_Current',
        'MonthPrevious' => 'Month_Previous',
        'YearCurrent'   => 'Year_Current',
        'YearPrevious'  => 'Year_Previous'
    ];

    /** Zeiträume, für die eine Prognose Sinn ergibt (laufende Woche/Monat/Jahr). */
    public const FORECAST = ['Week_Current', 'Month_Current', 'Year_Current'];

    /**
     * Zeiträume, deren Prognose den Vorjahresverlauf nutzen kann. Die Woche bleibt linear: Der Wochenrhythmus
     * (Werktag, Wochenende) hat im Kalender-Vorjahr keine Entsprechung.
     */
    public const FORECAST_SEASONAL = ['Month_Current', 'Year_Current'];

    /** Zeiträume mit Saldo der Abschläge (zusätzlich zu Tarifzeiträumen und Summe). */
    public const BALANCE = ['Year_Current', 'Year_Previous'];

    public static function ident(string $periodKey, string $kind): string
    {
        return $periodKey . '_' . $kind;
    }

    /** Nur Buchstaben und Ziffern, damit der Schlüssel als Ident taugt. */
    public static function sanitizeId(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $id) ?? '';
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * Berechnet Beginn (inklusive) und Ende (exklusive) eines festen Zeitraums.
     *
     * @return array{start:int,end:int}
     */
    public static function fixed(string $key, int $now): array
    {
        $y = (int) date('Y', $now);
        $m = (int) date('n', $now);
        $d = (int) date('j', $now);
        $weekday = (int) date('N', $now); // 1 = Montag

        switch ($key) {
            case 'Day_Current':
                return ['start' => mktime(0, 0, 0, $m, $d, $y), 'end' => mktime(0, 0, 0, $m, $d + 1, $y)];
            case 'Day_Previous':
                return ['start' => mktime(0, 0, 0, $m, $d - 1, $y), 'end' => mktime(0, 0, 0, $m, $d, $y)];
            case 'Week_Current':
                $start = mktime(0, 0, 0, $m, $d - ($weekday - 1), $y);
                return ['start' => $start, 'end' => mktime(0, 0, 0, (int) date('n', $start), (int) date('j', $start) + 7, (int) date('Y', $start))];
            case 'Week_Previous':
                $end = mktime(0, 0, 0, $m, $d - ($weekday - 1), $y);
                return ['start' => mktime(0, 0, 0, (int) date('n', $end), (int) date('j', $end) - 7, (int) date('Y', $end)), 'end' => $end];
            case 'Month_Current':
                return ['start' => mktime(0, 0, 0, $m, 1, $y), 'end' => mktime(0, 0, 0, $m + 1, 1, $y)];
            case 'Month_Previous':
                return ['start' => mktime(0, 0, 0, $m - 1, 1, $y), 'end' => mktime(0, 0, 0, $m, 1, $y)];
            case 'Year_Current':
                return ['start' => mktime(0, 0, 0, 1, 1, $y), 'end' => mktime(0, 0, 0, 1, 1, $y + 1)];
            case 'Year_Previous':
                return ['start' => mktime(0, 0, 0, 1, 1, $y - 1), 'end' => mktime(0, 0, 0, 1, 1, $y)];
        }
        throw new InvalidArgumentException('Unknown period ' . $key);
    }

    /**
     * Eigener Zeitraum aus einer Listenzeile (From/To als SelectDate); das Enddatum ist inklusive.
     *
     * @return array{start:int,end:int}|null
     */
    public static function custom(array $row): ?array
    {
        $from = ErTariff::decode($row['From'] ?? null);
        $to = ErTariff::decode($row['To'] ?? null);
        if ($from === null || $to === null || !isset($from['year'], $from['month'], $from['day'], $to['year'], $to['month'], $to['day'])) {
            return null;
        }
        $start = mktime(0, 0, 0, (int) $from['month'], (int) $from['day'], (int) $from['year']);
        $end = mktime(0, 0, 0, (int) $to['month'], (int) $to['day'] + 1, (int) $to['year']);
        return $end > $start ? ['start' => $start, 'end' => $end] : null;
    }
}
