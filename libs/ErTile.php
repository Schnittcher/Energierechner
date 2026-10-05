<?php

declare(strict_types=1);

/**
 * Baut die Daten für die Kachel-Visualisierung aus den Rechenergebnissen.
 * Reine Datenaufbereitung ohne IPS-Abhängigkeit.
 *
 * Teil der Kachel (siehe ErTileTrait). Zum Entfernen der Kachel diese Datei, ErTileTrait.php und
 * Energierechner2/tile.html löschen und die mit "KACHEL" markierten Zeilen in module.php entfernen.
 */
final class ErTile
{
    /** Ergebnisfelder, die die Kachel anzeigen kann (Feldname im Rechenergebnis => Name in den Kacheldaten). */
    private const FIELDS = [
        'costs'               => 'costs',
        'consumption'         => 'consumption',
        'costsWork'           => 'costsWork',
        'costsBase'           => 'costsBase',
        'costsHt'             => 'costsHt',
        'costsNt'             => 'costsNt',
        'consumptionHt'       => 'consumptionHt',
        'consumptionNt'       => 'consumptionNt',
        'energy'              => 'energy',
        'forecastCosts'       => 'forecastCosts',
        'forecastConsumption' => 'forecastConsumption',
        'balance'             => 'balance',
        'days'                => 'days'
    ];

    /**
     * @param array<int, array{key:string,label:string,start:int,end:int,forecast?:bool,balance?:bool}> $defs aktivierte Zeiträume.
     *        Prognose und Saldo übernimmt die Kachel nur für Zeiträume, bei denen das Modul sie auch als Variable führt.
     * @param array<string, array<string, mixed>> $results Rechenergebnisse je Zeitraum-Schlüssel
     * @param array{title:string,unit:string,warnings:string[],flags:array<string,bool>,labels:array<string,string>,lastYear?:array<string,float>,peak?:array<string,array{day:int,costs:float}>} $options
     *        lastYear: Verbrauch des Vorjahreszeitraums je Zeitraum-Schlüssel (für den Vorjahresvergleich)
     * @return array<string, mixed>
     */
    public static function build(array $defs, array $results, array $options, int $now): array
    {
        $periods = [];
        foreach ($defs as $def) {
            $key = $def['key'];
            if (!isset($results[$key])) {
                continue;
            }
            [$group, $role] = self::group($key);
            $values = [];
            foreach (self::FIELDS as $field => $name) {
                if (strpos($field, 'forecast') === 0 && empty($def['forecast'])) {
                    continue;
                }
                if ($field === 'balance' && empty($def['balance'])) {
                    continue;
                }
                $value = $results[$key][$field] ?? null;
                if ($value !== null) {
                    $values[$name] = round((float) $value, 4);
                }
            }
            if (isset($options['peak'][$key])) {
                $values['peakDay'] = $options['peak'][$key]['day'];
                $values['peakCosts'] = round((float) $options['peak'][$key]['costs'], 4);
            }
            if (isset($options['lastYear'][$key])) {
                $values['lastYearConsumption'] = round((float) $options['lastYear'][$key], 4);
            }
            $span = $def['end'] - $def['start'];
            $open = $span > 0 && $def['end'] > $now && $def['start'] <= $now;
            $periods[] = [
                'key'      => $key,
                'label'    => $def['label'],
                'group'    => $group,
                'role'     => $role,
                'open'     => $open,
                'progress' => $open ? round(min(1.0, max(0.0, ($now - $def['start']) / $span)), 4) : 1.0,
                'v'        => $values
            ];
        }

        return [
            'title'    => $options['title'],
            'unit'     => $options['unit'],
            'updated'  => $now,
            'warnings' => array_values($options['warnings']),
            'flags'    => $options['flags'],
            'labels'   => $options['labels'],
            'periods'  => $periods,
            'tariff'   => $options['tariff'] ?? null
        ];
    }

    /**
     * Kacheldaten für die Anzeige-Instanz: nur der gewählte Zeitraum, eigene Darstellung und optional ein eigener Titel.
     *
     * @param array<string, mixed> $data Kacheldaten der Energierechner-Instanz
     * @param string $display auto, compact oder standard
     * @return array<string, mixed>
     */
    public static function fixed(array $data, string $key, string $display, string $title): array
    {
        $periods = array_values(array_filter(
            (array) ($data['periods'] ?? []),
            static fn (array $p): bool => ($p['key'] ?? '') === $key
        ));
        $data['periods'] = $periods;
        $data['fixed'] = $key;
        $data['mode'] = in_array($display, ['compact', 'standard'], true) ? $display : 'auto';
        if ($title !== '') {
            $data['title'] = $title;
        }
        return $data;
    }

    /**
     * Der Tag mit den höchsten Kosten im Zeitraum [$start, $end), oder null ohne Kosten.
     *
     * @param array<int, array{start:int,end:int,value:float}> $intervals Archivintervalle des Zeitraums
     * @param array<int, mixed> $prices dynamische Preise wie für ErCalculator::calculate
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
     * Der zum Zeitpunkt gültige Tarifabschnitt für die Anzeige, oder null ohne Tarif.
     * Preise in ct je Einheit; "until" ist der letzte Tag der Gültigkeit (null bei offenem Ende).
     *
     * @return array{name:string,supplier:string,dynamic:bool,ht:float,nt:?float,until:?int}|null
     */
    public static function tariffInfo(ErTariff $tariff, int $now): ?array
    {
        $i = $tariff->indexAt($now);
        if ($i < 0) {
            return null;
        }
        $segment = $tariff->segments()[$i];
        $end = $tariff->segmentEnd($i, 0);
        return [
            'name'     => $segment->name,
            'supplier' => $segment->supplier,
            'dynamic'  => $segment->isDynamic(),
            'ht'       => round($segment->priceHt * 100, 2),
            'nt'       => $segment->hasNt() ? round($segment->priceNt * 100, 2) : null,
            'until'    => $end > 0 ? $end - 1 : null
        ];
    }

    /**
     * Ordnet einen Zeitraum-Schlüssel einer Gruppe und Rolle zu.
     *
     * @return array{0:string,1:string} Gruppe (day, week, month, year, tariff, custom, total) und Rolle (cur, prev)
     */
    public static function group(string $key): array
    {
        if (preg_match('/^(Day|Week|Month|Year)_(Current|Previous)$/', $key, $m)) {
            return [strtolower($m[1]), $m[2] === 'Current' ? 'cur' : 'prev'];
        }
        if (strpos($key, 'Tariff_') === 0) {
            return ['tariff', 'cur'];
        }
        if (strpos($key, 'Custom_') === 0) {
            return ['custom', 'cur'];
        }
        return ['total', 'cur'];
    }
}
