<?php

declare(strict_types=1);

/**
 * Ein Tarifabschnitt (gültig ab einem Datum bis zum nächsten Abschnitt).
 * HT = Hochtarif, NT = Niedertarif. Der Niedertarif gilt in bis zu zwei Zeitfenstern pro Tag
 * und optional an Samstag und Sonntag ganztägig.
 * Reine Datenklasse, keine IPS-Abhängigkeit.
 */
final class ErTariffSegment
{
    /** @var array<int, array{0:int,1:int}> Niedertarif-Zeitfenster als [von, bis] in Minuten seit Mitternacht (bis exklusiv) */
    public readonly array $ntWindows;

    /**
     * @param array<int, array{0:int,1:int}> $ntWindows Fenster mit von == bis werden ignoriert
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $validFrom,      // Unix-Timestamp, lokaler Tagesbeginn
        public readonly float $priceHt,      // Arbeitspreis pro Einheit im Hochtarif
        public readonly float $priceNt,      // Arbeitspreis pro Einheit im Niedertarif, = priceHt wenn nicht gesetzt
        array $ntWindows,
        public readonly bool $ntWeekend,     // Samstag und Sonntag ganztägig Niedertarif
        public readonly float $baseYear,     // Grundpreis pro Jahr
        public readonly float $advance,      // Abschlag je Zahlung
        public readonly int $advanceCount,   // Zahlungen pro Jahr
        public readonly float $gasFactor,    // Zustandszahl-Faktoren für m³ -> kWh
        public readonly float $gasZ,
        public readonly float $gasCalorific,
        public readonly string $supplier = '',          // Anbieter, nur zur Anzeige
        public readonly int $priceVariableId = 0,       // dynamischer Preis: geloggte Variable (0 = fester Preis)
        public readonly float $priceFactor = 1.0,       // Faktor Variablenwert -> Euro je Einheit (ct/kWh: 0.01, EUR/MWh: 0.001)
        public readonly float $surcharge = 0.0,         // fester Aufschlag je Einheit auf den dynamischen Preis
        public readonly int $priceResolution = 60       // Auflösung des Preises in Minuten (60 oder 15)
    ) {
        $windows = [];
        foreach ($ntWindows as $window) {
            if ((int) $window[0] !== (int) $window[1]) {
                $windows[] = [(int) $window[0], (int) $window[1]];
            }
        }
        $this->ntWindows = $windows;
    }

    /** Kommt der Arbeitspreis aus einer Variable statt aus dem festen Preis? */
    public function isDynamic(): bool
    {
        return $this->priceVariableId > 0;
    }

    /** Gibt es einen Niedertarif (mindestens ein Zeitfenster oder Wochenende)? */
    public function hasNt(): bool
    {
        return count($this->ntWindows) > 0 || $this->ntWeekend;
    }

    /**
     * Gilt zum Zeitpunkt $ts der Niedertarif? Zeitfenster beginnen inklusive und enden exklusive,
     * ein Fenster darf über Mitternacht gehen (22:00 bis 06:00).
     */
    public function isNt(int $ts): bool
    {
        if ($this->ntWeekend && (int) date('N', $ts) >= 6) {
            return true;
        }
        $minute = ErTariff::minuteOfDay($ts);
        foreach ($this->ntWindows as [$from, $to]) {
            if ($from < $to ? ($minute >= $from && $minute < $to) : ($minute >= $from || $minute < $to)) {
                return true;
            }
        }
        return false;
    }

    /** kWh pro m³ aus Umrechnungsfaktor, Zustandszahl und Brennwert. */
    public function gasKwhPerUnit(): float
    {
        return $this->gasFactor * $this->gasZ * $this->gasCalorific;
    }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'validFrom'       => $this->validFrom,
            'priceHt'         => $this->priceHt,
            'priceNt'         => $this->priceNt,
            'ntWindows'       => $this->ntWindows,
            'ntWeekend'       => $this->ntWeekend,
            'baseYear'        => $this->baseYear,
            'advance'         => $this->advance,
            'advanceCount'    => $this->advanceCount,
            'gasFactor'       => $this->gasFactor,
            'gasZ'            => $this->gasZ,
            'gasCalorific'    => $this->gasCalorific,
            'supplier'        => $this->supplier,
            'priceVariableId' => $this->priceVariableId,
            'priceFactor'     => $this->priceFactor,
            'surcharge'       => $this->surcharge,
            'priceResolution' => $this->priceResolution
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['id'] ?? ''),
            (string) ($a['name'] ?? ''),
            (int) ($a['validFrom'] ?? 0),
            (float) ($a['priceHt'] ?? 0),
            (float) ($a['priceNt'] ?? ($a['priceHt'] ?? 0)),
            (array) ($a['ntWindows'] ?? []),
            (bool) ($a['ntWeekend'] ?? false),
            (float) ($a['baseYear'] ?? 0),
            (float) ($a['advance'] ?? 0),
            (int) ($a['advanceCount'] ?? 0),
            (float) ($a['gasFactor'] ?? 0),
            (float) ($a['gasZ'] ?? 0),
            (float) ($a['gasCalorific'] ?? 0),
            (string) ($a['supplier'] ?? ''),
            (int) ($a['priceVariableId'] ?? 0),
            (float) ($a['priceFactor'] ?? 1.0),
            (float) ($a['surcharge'] ?? 0.0),
            (int) ($a['priceResolution'] ?? 60)
        );
    }
}

/**
 * Sortierte Folge von Tarifabschnitten.
 * Ermittelt den gültigen Abschnitt zu einem Zeitpunkt und zerlegt Zeiträume
 * an Tarifwechseln und Grenzen der Niedertarif-Zeiten.
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

    /** Hat mindestens ein Abschnitt einen Niedertarif? */
    public function hasNt(): bool
    {
        foreach ($this->segments as $s) {
            if ($s->hasNt()) {
                return true;
            }
        }
        return false;
    }

    /** Hat mindestens ein Abschnitt einen dynamischen Preis? */
    public function hasDynamic(): bool
    {
        foreach ($this->segments as $s) {
            if ($s->isDynamic()) {
                return true;
            }
        }
        return false;
    }

    /** Feinste Auflösung (Minuten) der dynamischen Preise: 15 oder 60. */
    public function dynamicResolutionMinutes(): int
    {
        $resolution = 60;
        foreach ($this->segments as $s) {
            if ($s->isDynamic()) {
                $resolution = min($resolution, $s->priceResolution === 15 ? 15 : 60);
            }
        }
        return $resolution;
    }

    /** Kleinste Rastergröße (in Minuten), auf der alle Grenzen der Niedertarif-Zeitfenster liegen: 60, 15 oder 1. */
    public function ntGridMinutes(): int
    {
        $grid = 60;
        foreach ($this->segments as $s) {
            foreach ($s->ntWindows as $window) {
                foreach ($window as $minute) {
                    if ($minute % 60 !== 0) {
                        $grid = min($grid, ($minute % 15 === 0) ? 15 : 1);
                    }
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
     * Zerlegt [$a, $b) an Tarifwechseln, Grenzen der Niedertarif-Zeiten und weiteren Schnittpunkten
     * (z. B. den Grenzen der Preisintervalle eines dynamischen Tarifs).
     *
     * @param int[] $extraCuts zusätzliche Schnittpunkte; Werte außerhalb von ($a, $b) werden ignoriert
     * @return array<int, array{from:int,to:int,seg:?ErTariffSegment,nt:bool}>
     */
    public function split(int $a, int $b, array $extraCuts = []): array
    {
        if ($b <= $a) {
            return [];
        }

        $cuts = [];
        foreach ($extraCuts as $t) {
            if ($t > $a && $t < $b) {
                $cuts[] = $t;
            }
        }
        foreach ($this->segments as $s) {
            if ($s->validFrom > $a && $s->validFrom < $b) {
                $cuts[] = $s->validFrom;
            }
        }

        // Minuten des Tages, an denen sich der Niedertarif ändern kann; bei "Wochenende ganztägig" auch Mitternacht
        $minutes = [];
        foreach ($this->segments as $s) {
            foreach ($s->ntWindows as [$from, $to]) {
                $minutes[$from] = true;
                $minutes[$to] = true;
            }
            if ($s->ntWeekend) {
                $minutes[0] = true;
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
                'from' => $from,
                'to'   => $points[$i + 1],
                'seg'  => $seg,
                'nt'   => $seg !== null && $seg->isNt($from)
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

            $priceHt = (float) ($row['PriceHT'] ?? 0);
            $priceNt = (float) ($row['PriceNT'] ?? 0);
            if ($priceNt <= 0.0) {
                $priceNt = $priceHt;
            }
            $windows = [
                [self::minutes($row['NtFrom1'] ?? null), self::minutes($row['NtTo1'] ?? null)],
                [self::minutes($row['NtFrom2'] ?? null), self::minutes($row['NtTo2'] ?? null)]
            ];
            $segments[] = new ErTariffSegment(
                (string) ($row['Id'] ?? ''),
                (string) ($row['Name'] ?? ''),
                $validFrom,
                $priceHt,
                $priceNt,
                $windows,
                (bool) ($row['NtWeekend'] ?? false),
                (float) ($row['BasePrice'] ?? 0),
                (float) ($row['Advance'] ?? 0),
                (int) ($row['AdvanceCount'] ?? 0),
                (float) ($row['GasFactor'] ?? 0),
                (float) ($row['GasZ'] ?? 0),
                (float) ($row['GasCalorific'] ?? 0),
                (string) ($row['Supplier'] ?? ''),
                (int) ($row['PriceVariable'] ?? 0),
                self::priceFactor((string) ($row['PriceUnit'] ?? 'EUR_KWH')),
                (float) ($row['Surcharge'] ?? 0),
                (int) ($row['PriceResolution'] ?? 60) === 15 ? 15 : 60
            );
        }
        return [new self($segments), $warnings];
    }

    /** Faktor vom Wert der Preisvariable zu Euro je Einheit. */
    public static function priceFactor(string $unit): float
    {
        switch ($unit) {
            case 'CT_KWH':
                return 0.01;
            case 'EUR_MWH':
                return 0.001;
            default:
                return 1.0;
        }
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
