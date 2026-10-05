<?php

declare(strict_types=1);

/**
 * Ein Tarifabschnitt (gültig ab einem Datum bis zum nächsten Abschnitt).
 * Reine Datenklasse, keine IPS-Abhängigkeit.
 */
final class ErTariffSegment
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $validFrom,      // Unix-Timestamp, lokaler Tagesbeginn
        public readonly float $priceDay,     // Arbeitspreis pro Einheit (Tag)
        public readonly float $priceNight,   // Arbeitspreis pro Einheit (Nacht), = priceDay wenn nicht gesetzt
        public readonly int $nightFrom,      // Minuten seit Mitternacht
        public readonly int $nightTo,        // Minuten seit Mitternacht (Ende exklusiv)
        public readonly float $baseYear,     // Grundpreis pro Jahr
        public readonly float $advance,      // Abschlag je Zahlung
        public readonly int $advanceCount,   // Zahlungen pro Jahr
        public readonly float $gasFactor,    // Zustandszahl-Faktoren für m³ -> kWh
        public readonly float $gasZ,
        public readonly float $gasCalorific,
        public readonly string $supplier = ''   // Anbieter, nur zur Anzeige
    ) {
    }

    /** Gibt es ein Nachtfenster (Beginn != Ende)? */
    public function hasNight(): bool
    {
        return $this->nightFrom !== $this->nightTo;
    }

    /** Liegt die Minute des Tages im Nachtfenster? Beginn inklusive, Ende exklusive. */
    public function isNight(int $minuteOfDay): bool
    {
        if (!$this->hasNight()) {
            return false;
        }
        if ($this->nightFrom < $this->nightTo) {
            return $minuteOfDay >= $this->nightFrom && $minuteOfDay < $this->nightTo;
        }
        return $minuteOfDay >= $this->nightFrom || $minuteOfDay < $this->nightTo;
    }

    /** kWh pro m³ aus Umrechnungsfaktor, Zustandszahl und Brennwert. */
    public function gasKwhPerUnit(): float
    {
        return $this->gasFactor * $this->gasZ * $this->gasCalorific;
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'validFrom'    => $this->validFrom,
            'priceDay'     => $this->priceDay,
            'priceNight'   => $this->priceNight,
            'nightFrom'    => $this->nightFrom,
            'nightTo'      => $this->nightTo,
            'baseYear'     => $this->baseYear,
            'advance'      => $this->advance,
            'advanceCount' => $this->advanceCount,
            'gasFactor'    => $this->gasFactor,
            'gasZ'         => $this->gasZ,
            'gasCalorific' => $this->gasCalorific,
            'supplier'     => $this->supplier
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['id'] ?? ''),
            (string) ($a['name'] ?? ''),
            (int) ($a['validFrom'] ?? 0),
            (float) ($a['priceDay'] ?? 0),
            (float) ($a['priceNight'] ?? ($a['priceDay'] ?? 0)),
            (int) ($a['nightFrom'] ?? 0),
            (int) ($a['nightTo'] ?? 0),
            (float) ($a['baseYear'] ?? 0),
            (float) ($a['advance'] ?? 0),
            (int) ($a['advanceCount'] ?? 0),
            (float) ($a['gasFactor'] ?? 0),
            (float) ($a['gasZ'] ?? 0),
            (float) ($a['gasCalorific'] ?? 0),
            (string) ($a['supplier'] ?? '')
        );
    }
}

/**
 * Sortierte Folge von Tarifabschnitten.
 * Ermittelt den gültigen Abschnitt zu einem Zeitpunkt und zerlegt Zeiträume
 * an Tarifwechseln und Nachtfenster-Grenzen.
 */
final class ErTariff
{
    /** @var ErTariffSegment[] */
    private array $segments;

    /** @param ErTariffSegment[] $segments */
    public function __construct(array $segments)
    {
        usort($segments, static fn (ErTariffSegment $a, ErTariffSegment $b): int => $a->validFrom <=> $b->validFrom);
        $this->segments = array_values($segments);
    }

    /** @return ErTariffSegment[] */
    public function segments(): array
    {
        return $this->segments;
    }

    public function isEmpty(): bool
    {
        return count($this->segments) === 0;
    }

    /** Index des gültigen Abschnitts oder -1, wenn der Zeitpunkt vor dem ersten Abschnitt liegt. */
    public function indexAt(int $ts): int
    {
        for ($i = count($this->segments) - 1; $i >= 0; $i--) {
            if ($ts >= $this->segments[$i]->validFrom) {
                return $i;
            }
        }
        return -1;
    }

    public function segmentAt(int $ts): ?ErTariffSegment
    {
        $i = $this->indexAt($ts);
        return $i < 0 ? null : $this->segments[$i];
    }

    /** Ende (exklusiv) des Abschnitts $index: Beginn des nächsten, sonst $openEnd. */
    public function segmentEnd(int $index, int $openEnd): int
    {
        return isset($this->segments[$index + 1]) ? $this->segments[$index + 1]->validFrom : $openEnd;
    }

    public function hasNight(): bool
    {
        foreach ($this->segments as $s) {
            if ($s->hasNight()) {
                return true;
            }
        }
        return false;
    }

    /** Kleinste Rastergröße (in Minuten), auf der alle Nachtfenster-Grenzen liegen: 60, 15 oder 1. */
    public function nightGridMinutes(): int
    {
        $grid = 60;
        foreach ($this->segments as $s) {
            if (!$s->hasNight()) {
                continue;
            }
            foreach ([$s->nightFrom, $s->nightTo] as $m) {
                if ($m % 60 !== 0) {
                    $grid = min($grid, ($m % 15 === 0) ? 15 : 1);
                }
            }
        }
        return $grid;
    }

    public static function minuteOfDay(int $ts): int
    {
        return ((int) date('G', $ts)) * 60 + (int) date('i', $ts);
    }

    public static function startOfDay(int $ts): int
    {
        return mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
    }

    public static function nextDay(int $dayStart): int
    {
        return mktime(0, 0, 0, (int) date('n', $dayStart), (int) date('j', $dayStart) + 1, (int) date('Y', $dayStart));
    }

    /**
     * Zerlegt [$a, $b) an Tarifwechseln und Nachtfenster-Grenzen.
     *
     * @return array<int, array{from:int,to:int,seg:?ErTariffSegment,night:bool}>
     */
    public function split(int $a, int $b): array
    {
        if ($b <= $a) {
            return [];
        }

        $cuts = [];
        foreach ($this->segments as $s) {
            if ($s->validFrom > $a && $s->validFrom < $b) {
                $cuts[] = $s->validFrom;
            }
        }

        $minutes = [];
        foreach ($this->segments as $s) {
            if ($s->hasNight()) {
                $minutes[$s->nightFrom] = true;
                $minutes[$s->nightTo] = true;
            }
        }
        if (count($minutes) > 0) {
            $day = self::startOfDay($a);
            while ($day < $b) {
                $y = (int) date('Y', $day);
                $mo = (int) date('n', $day);
                $d = (int) date('j', $day);
                foreach (array_keys($minutes) as $m) {
                    $t = mktime(intdiv($m, 60), $m % 60, 0, $mo, $d, $y);
                    if ($t > $a && $t < $b) {
                        $cuts[] = $t;
                    }
                }
                $day = mktime(0, 0, 0, $mo, $d + 1, $y);
            }
        }

        $cuts = array_values(array_unique($cuts));
        sort($cuts);
        $points = array_merge([$a], $cuts, [$b]);

        $pieces = [];
        for ($i = 0, $n = count($points) - 1; $i < $n; $i++) {
            $from = $points[$i];
            $seg = $this->segmentAt($from);
            $pieces[] = [
                'from'  => $from,
                'to'    => $points[$i + 1],
                'seg'   => $seg,
                'night' => $seg !== null && $seg->isNight(self::minuteOfDay($from))
            ];
        }
        return $pieces;
    }

    public function toArray(): array
    {
        return array_map(static fn (ErTariffSegment $s): array => $s->toArray(), $this->segments);
    }

    public static function fromArray(array $rows): self
    {
        return new self(array_map(static fn (array $r): ErTariffSegment => ErTariffSegment::fromArray($r), $rows));
    }

    /**
     * Baut den Tarif aus den Zeilen der Konfigurationsliste (SelectDate/SelectTime als JSON-String oder Array).
     *
     * @return array{0:self,1:string[]} Tarif und Warnungen
     */
    public static function fromFormRows(array $rows): array
    {
        $segments = [];
        $warnings = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $date = self::decode($row['ValidFrom'] ?? null);
            if ($date === null || !isset($date['year'], $date['month'], $date['day'])) {
                $warnings[] = 'row ' . ($i + 1) . ': ValidFrom missing';
                continue;
            }
            $validFrom = mktime(0, 0, 0, (int) $date['month'], (int) $date['day'], (int) $date['year']);
            if (isset($seen[$validFrom])) {
                $warnings[] = 'row ' . ($i + 1) . ': duplicate ValidFrom';
                continue;
            }
            $seen[$validFrom] = true;

            $priceDay = (float) ($row['PriceDay'] ?? 0);
            $priceNight = (float) ($row['PriceNight'] ?? 0);
            if ($priceNight <= 0.0) {
                $priceNight = $priceDay;
            }
            $segments[] = new ErTariffSegment(
                (string) ($row['Id'] ?? ''),
                (string) ($row['Name'] ?? ''),
                $validFrom,
                $priceDay,
                $priceNight,
                self::minutes($row['NightFrom'] ?? null),
                self::minutes($row['NightTo'] ?? null),
                (float) ($row['BasePrice'] ?? 0),
                (float) ($row['Advance'] ?? 0),
                (int) ($row['AdvanceCount'] ?? 0),
                (float) ($row['GasFactor'] ?? 0),
                (float) ($row['GasZ'] ?? 0),
                (float) ($row['GasCalorific'] ?? 0),
                (string) ($row['Supplier'] ?? '')
            );
        }
        return [new self($segments), $warnings];
    }

    /** Liest SelectDate/SelectTime-Werte (JSON-String, Array oder Timestamp). */
    public static function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $d = json_decode($value, true);
            return is_array($d) ? $d : null;
        }
        return null;
    }

    public static function minutes(mixed $value): int
    {
        if (is_int($value)) {
            return self::minuteOfDay($value);
        }
        $t = self::decode($value);
        if ($t === null) {
            return 0;
        }
        return ((int) ($t['hour'] ?? 0)) * 60 + (int) ($t['minute'] ?? 0);
    }
}
