<?php

declare(strict_types=1);

require_once __DIR__ . '/ErTariff.php';

/**
 * Liest aggregierte Zählerwerte aus dem Archiv und normalisiert sie zu Intervallen.
 * Der eigentliche Archivaufruf wird als Callable übergeben, damit die Klasse ohne IPS testbar bleibt.
 */
final class ErArchiveReader
{
    public const HOURLY = 0;
    public const DAILY = 1;
    public const QUARTER_HOUR = 8; // ab Symcon 8.1

    /** Das Archiv liefert höchstens 10.000 Datensätze pro Abfrage. */
    private const CHUNK_LIMIT = 10000;

    /**
     * Wählt die gröbste Aggregation, die für den Tarif noch exakt ist.
     * Ohne Nachtfenster und ohne dynamische Preise reicht täglich (Tarifwechsel liegen immer an Tagesgrenzen).
     * Mit Nachtfenster oder dynamischen Preisen wird auf Stunden gerechnet, bei Grenzen oder Preisen
     * im Viertelstundenraster auf Viertelstunden.
     */
    public static function aggregationFor(ErTariff $tariff): int
    {
        $grid = 1440;
        if ($tariff->hasNt()) {
            $grid = min($grid, $tariff->ntGridMinutes());
        }
        if ($tariff->hasDynamic()) {
            $grid = min($grid, $tariff->dynamicResolutionMinutes());
        }
        if ($grid >= 1440) {
            return self::DAILY;
        }
        return $grid >= 60 ? self::HOURLY : self::QUARTER_HOUR;
    }

    /** Ende (exklusiv) eines Aggregationsintervalls, das bei $start beginnt. */
    public static function bucketEnd(int $aggregation, int $start): int
    {
        switch ($aggregation) {
            case self::QUARTER_HOUR:
                return $start + 900;
            case self::HOURLY:
                return $start + 3600;
            case self::DAILY:
                return ErTariff::nextDay($start);
            default:
                throw new InvalidArgumentException('Unsupported aggregation ' . $aggregation);
        }
    }

    /**
     * @param callable(int,int,int):array $fetch fn(aggregation, from, to): array im Format von AC_GetAggregatedValues
     * @return array<int, array{start:int,end:int,value:float}> aufsteigend nach Zeit
     */
    public static function read(callable $fetch, int $aggregation, int $from, int $to): array
    {
        if ($to < $from) {
            return [];
        }
        $byStart = [];
        foreach (self::chunks($aggregation, $from, $to) as [$chunkFrom, $chunkTo]) {
            $rows = $fetch($aggregation, $chunkFrom, $chunkTo);
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $start = (int) $row['TimeStamp'];
                $byStart[$start] = [
                    'start' => $start,
                    'end'   => self::bucketEnd($aggregation, $start),
                    'value' => (float) $row['Avg']
                ];
            }
        }
        ksort($byStart);
        return array_values($byStart);
    }

    /**
     * Summe der Tageswerte in [$from, $to]. Billiger Fingerabdruck der Archivdaten eines Zeitraums:
     * Ändert sich nachträglich ein Wert im Archiv, ändert sich auch die Summe.
     *
     * @param callable(int,int,int):array $fetch fn(aggregation, from, to) wie bei read()
     */
    public static function sumDaily(callable $fetch, int $from, int $to): float
    {
        $sum = 0.0;
        foreach (self::read($fetch, self::DAILY, $from, $to) as $interval) {
            $sum += $interval['value'];
        }
        return $sum;
    }

    /**
     * Vergleicht zwei Fingerabdrücke (Name => Summe) mit relativer Toleranz gegen Rundungsrauschen.
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    public static function sameFingerprint(array $a, array $b, float $tolerance = 1e-6): bool
    {
        ksort($a);
        ksort($b);
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $name => $value) {
            if (abs($value - $b[$name]) > $tolerance * max(1.0, abs($value))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Teilt den Bereich so, dass jede Abfrage unter dem 10.000er-Limit bleibt.
     *
     * @return array<int, array{0:int,1:int}>
     */
    public static function chunks(int $aggregation, int $from, int $to): array
    {
        $step = $aggregation === self::DAILY ? 'year' : 'month';
        $chunks = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $y = (int) date('Y', $cursor);
            $m = (int) date('n', $cursor);
            $next = $step === 'year' ? mktime(0, 0, 0, 1, 1, $y + 1) : mktime(0, 0, 0, $m + 1, 1, $y);
            $chunks[] = [$cursor, min($to, $next - 1)];
            $cursor = $next;
        }
        return $chunks;
    }
}
