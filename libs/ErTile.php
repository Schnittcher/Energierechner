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
        'costs'                => 'costs',
        'consumption'          => 'consumption',
        'costsWork'            => 'costsWork',
        'costsBase'            => 'costsBase',
        'costsHt'              => 'costsHt',
        'costsNt'              => 'costsNt',
        'consumptionHt'        => 'consumptionHt',
        'consumptionNt'        => 'consumptionNt',
        'energy'               => 'energy',
        'forecastCosts'        => 'forecastCosts',
        'forecastConsumption'  => 'forecastConsumption',
        'balance'              => 'balance',
        'days'                 => 'days',
        'avgPerDay'            => 'avgPerDay',
        'avgConsumptionPerDay' => 'avgConsumptionPerDay',
        'lastYearConsumption'  => 'lastYearConsumption',
        'vsLastYear'           => 'vsLastYear',
        'peakDay'              => 'peakDay',
        'peakCosts'            => 'peakCosts'
    ];

    /**
     * @param array<int, array{key:string,label:string,start:int,end:int,forecast?:bool,balance?:bool}> $defs aktivierte Zeiträume.
     *        Prognose und Saldo übernimmt die Kachel nur für Zeiträume, bei denen das Modul sie auch als Variable führt.
     * @param array<string, array<string, mixed>> $results Rechenergebnisse je Zeitraum-Schlüssel
     * @param array{title:string,unit:string,priceUnit?:string,warnings:string[],flags:array<string,bool>,labels:array<string,string>,tariff?:array<string,mixed>|null} $options
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
            'title'     => $options['title'],
            'unit'      => $options['unit'],
            'priceUnit' => $options['priceUnit'] ?? $options['unit'],
            'updated'   => $now,
            'warnings'  => array_values($options['warnings']),
            'flags'     => $options['flags'],
            'labels'    => $options['labels'],
            'periods'   => $periods,
            'tariff'    => $options['tariff'] ?? null
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
     * Auswahlliste für den Zeitraum der Anzeige-Instanz: die acht Standardzeiträume (Namen übersetzt übergeben),
     * danach die weiteren Zeiträume der Quelle (Tarifzeiträume, eigene Zeiträume, Summe) mit ihrem Namen.
     * Ein gespeicherter Schlüssel, den die Quelle nicht (mehr) kennt, bleibt als nicht verfügbare Option stehen,
     * damit die Auswahl ihn nicht still ersetzt.
     *
     * @param array<string, mixed> $data Kacheldaten der Quelle (leer, solange sie nicht gerechnet hat)
     * @param array<string, string> $standard Schlüssel => Name der Standardzeiträume
     * @return array<int, array{caption:string,value:string}>
     */
    public static function periodOptions(array $data, array $standard, string $current, string $unavailable): array
    {
        $options = [];
        foreach ($standard as $key => $caption) {
            $options[] = ['caption' => $caption, 'value' => $key];
        }
        $known = $standard;
        foreach ((array) ($data['periods'] ?? []) as $period) {
            $key = (string) ($period['key'] ?? '');
            if ($key === '' || isset($known[$key])) {
                continue;
            }
            $known[$key] = (string) ($period['label'] ?? $key);
            $options[] = ['caption' => $known[$key], 'value' => $key];
        }
        if ($current !== '' && !isset($known[$current])) {
            $options[] = ['caption' => $current . ' (' . $unavailable . ')', 'value' => $current];
        }
        return $options;
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
