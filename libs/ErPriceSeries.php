<?php

declare(strict_types=1);

/**
 * Zeitreihe von Preisen für einen dynamischen Tarif (z. B. Börsenstrompreis je Stunde).
 * Intervalle im Format von ErArchiveReader::read(): ['start' => int, 'end' => int, 'value' => float].
 * Reine Datenklasse ohne IPS-Abhängigkeit.
 */
final class ErPriceSeries
{
    /** @var int[] */
    private array $starts = [];
    /** @var int[] */
    private array $ends = [];
    /** @var float[] */
    private array $values = [];

    /** @param array<int, array{start:int,end:int,value:float}> $intervals aufsteigend nach Beginn */
    public function __construct(array $intervals)
    {
        foreach ($intervals as $interval) {
            $this->starts[] = $interval['start'];
            $this->ends[] = $interval['end'];
            $this->values[] = $interval['value'];
        }
    }

    public function isEmpty(): bool
    {
        return count($this->starts) === 0;
    }

    /** Preis zum Zeitpunkt $ts oder null, wenn kein Intervall ihn abdeckt. */
    public function priceAt(int $ts): ?float
    {
        $i = $this->indexAtOrBefore($ts);
        if ($i < 0 || $ts >= $this->ends[$i]) {
            return null;
        }
        return $this->values[$i];
    }

    /**
     * Beginn aller Preisintervalle, die in ($a, $b) liegen.
     *
     * @return int[]
     */
    public function boundariesWithin(int $a, int $b): array
    {
        $cuts = [];
        for ($i = $this->indexAtOrBefore($a) + 1, $n = count($this->starts); $i < $n && $this->starts[$i] < $b; $i++) {
            if ($this->starts[$i] > $a) {
                $cuts[] = $this->starts[$i];
            }
        }
        return $cuts;
    }

    /** Index des letzten Intervalls mit Beginn <= $ts, sonst -1 (binäre Suche). */
    private function indexAtOrBefore(int $ts): int
    {
        $low = 0;
        $high = count($this->starts) - 1;
        $found = -1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->starts[$mid] <= $ts) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        return $found;
    }
}
