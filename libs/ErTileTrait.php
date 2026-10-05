<?php

declare(strict_types=1);

require_once __DIR__ . '/ErTile.php';

/**
 * Kachel-Visualisierung für Energierechner 2 (experimentell).
 *
 * Alles zur Kachel steckt in dieser Datei, in ErTile.php und in Energierechner2/tile.html.
 * Im Modul sind nur die mit "KACHEL" markierten Zeilen eingehängt. Zum Entfernen diese drei Dateien löschen,
 * die markierten Zeilen in module.php sowie die Eigenschaft TileEnabled im Formular entfernen.
 */
trait ErTileTrait
{
    /** Zeigt die Kachel auf Clients der Kachel-Visualisierung an. */
    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/../Energierechner2/tile.html');
        $data = $this->ReadAttributeString('TileData');
        if ($data === '' || $data === '{}') {
            $data = json_encode([
                'title'    => IPS_GetName($this->InstanceID),
                'unit'     => '',
                'updated'  => 0,
                'warnings' => [],
                'flags'    => [],
                'labels'   => $this->tileLabels(),
                'periods'  => []
            ]);
        }
        // "</" im JSON würde das Skript-Element beenden
        return str_replace('__TILE_DATA__', str_replace('</', '<\/', $data), (string) $html);
    }

    /** Die Daten der Kachel als JSON, so wie die Kachel sie bekommt (für Skripte und zur Fehlersuche). */
    public function GetTileData(): string
    {
        return $this->ReadAttributeString('TileData');
    }

    private function tileCreate(): void
    {
        $this->RegisterPropertyBoolean('TileEnabled', true);
        $this->RegisterAttributeString('TileData', '{}');
        $this->RegisterAttributeString('TileLastYear', '{}');
        $this->RegisterAttributeString('TilePeaks', '{}');
        $this->SetVisualizationType(1);
    }

    private function tileApply(): void
    {
        $this->SetVisualizationType($this->ReadPropertyBoolean('TileEnabled') ? 1 : 0);
    }

    /**
     * Baut die Kacheldaten und merkt sie für das nächste Öffnen und für die Anzeige-Instanz Energierechner Kachel.
     * An die eigene Kachel gehen die Werte nur, wenn sie eingeschaltet ist.
     *
     * @param array<string, array<string, mixed>> $results
     * @param array<int, array{key:string,label:string,start:int,end:int}> $defs
     */
    private function tileUpdate(array $results, array $defs, string $unitSuffix, int $now, ErTariff $tariff): void
    {
        $warnings = json_decode($this->ReadAttributeString('Warnings'), true);
        $data = ErTile::build($defs, $results, [
            'title'    => IPS_GetName($this->InstanceID),
            'unit'     => trim($unitSuffix),
            'warnings' => is_array($warnings) ? $warnings : [],
            'flags'    => [
                'htnt'     => $this->ReadPropertyBoolean('ShowHtNt'),
                'hasNt'    => $tariff->hasNt(),
                'base'     => $this->ReadPropertyBoolean('ShowBaseCosts'),
                'forecast' => $this->ReadPropertyBoolean('ShowForecast'),
                'balance'  => $this->ReadPropertyBoolean('ShowBalance')
            ],
            'labels'   => $this->tileLabels(),
            'tariff'   => ErTile::tariffInfo($tariff, $now),
            'lastYear' => $this->tileLastYear($defs, $now),
            'peak'     => $this->tilePeaks($defs, $tariff, $now)
        ], $now);
        $json = json_encode($data);
        $this->WriteAttributeString('TileData', $json);
        if ($this->ReadPropertyBoolean('TileEnabled')) {
            $this->UpdateVisualizationValue($json);
        }
    }

    /**
     * Verbrauch des gleichen Zeitraums im Vorjahr für den Vorjahresvergleich (Monat und Jahr, laufend und abgeschlossen).
     * Das Vorjahr ändert sich nicht mehr, deshalb wird es nur einmal am Tag aus dem Archiv gelesen.
     *
     * @param array<int, array{key:string,label:string,start:int,end:int}> $defs
     * @return array<string, float>
     */
    private function tileLastYear(array $defs, int $now): array
    {
        $cache = json_decode($this->ReadAttributeString('TileLastYear'), true);
        $today = date('Y-m-d', $now);
        if (is_array($cache) && ($cache['day'] ?? '') === $today && isset($cache['map']) && ($cache['starts'] ?? null) === array_column($defs, 'start', 'key')) {
            return $cache['map'];
        }

        $wanted = array_values(array_filter(
            $defs,
            static fn (array $d): bool => in_array($d['key'], ['Month_Current', 'Month_Previous', 'Year_Current', 'Year_Previous'], true)
        ));
        $archiveID = $this->archiveID();
        $variableID = $this->ReadPropertyInteger('ConsumptionVariableID');
        $map = [];
        if ($wanted !== [] && $archiveID !== 0 && IPS_VariableExists($variableID)) {
            $from = ErForecast::lastYear(min(array_column($wanted, 'start')));
            $to = ErForecast::lastYear(min(max(array_column($wanted, 'end')), $now)) - 1;
            if ($to > $from) {
                $fetch = static fn (int $a, int $f, int $t): array => (array) AC_GetAggregatedValues($archiveID, $variableID, $a, $f, $t, 0);
                $daily = ErArchiveReader::read($fetch, ErArchiveReader::DAILY, $from, $to);
                foreach ($wanted as $def) {
                    $value = ErForecast::lastYearConsumption($daily, $def['start'], $def['end'], $now);
                    if ($value !== null) {
                        $map[$def['key']] = $value;
                    }
                }
            }
        }
        $this->WriteAttributeString('TileLastYear', json_encode(['day' => $today, 'starts' => array_column($defs, 'start', 'key'), 'map' => $map]));
        return $map;
    }

    /**
     * Teuerster abgeschlossener Tag je Woche, Monat und Jahr. Das Archiv wird nur einmal am Tag gelesen,
     * heutige Werte zählen nicht mit.
     *
     * @param array<int, array{key:string,label:string,start:int,end:int}> $defs
     * @return array<string, array{day:int,costs:float}>
     */
    private function tilePeaks(array $defs, ErTariff $tariff, int $now): array
    {
        $unit = $this->unitInfo();
        $archiveID = $this->archiveID();
        $variableID = $this->ReadPropertyInteger('ConsumptionVariableID');
        $includeBase = $this->ReadPropertyBoolean('IncludeBaseCosts');
        $gas = $this->gasConversion();
        $today = ErTariff::startOfDay($now);
        $wanted = array_values(array_filter(
            $defs,
            static fn (array $d): bool => preg_match('/^(Week|Month|Year)_(Current|Previous)$/', $d['key']) === 1 && $d['start'] < $today
        ));

        $signature = md5(json_encode([$tariff->toArray(), $unit['factor'] ?? 1, $gas, $variableID, $includeBase, date('Y-m-d', $now), array_column($wanted, 'start', 'key')]));
        $cache = json_decode($this->ReadAttributeString('TilePeaks'), true);
        if (is_array($cache) && ($cache['sig'] ?? '') === $signature && isset($cache['map'])) {
            return $cache['map'];
        }

        $map = [];
        if ($wanted !== [] && $unit !== null && $archiveID !== 0 && IPS_VariableExists($variableID)) {
            $from = min(array_column($wanted, 'start'));
            $to = min(max(array_column($wanted, 'end')), $today) - 1;
            $fetch = static fn (int $a, int $f, int $t): array => (array) AC_GetAggregatedValues($archiveID, $variableID, $a, $f, $t, 0);
            $intervals = ErArchiveReader::read($fetch, ErArchiveReader::aggregationFor($tariff), $from, $to);
            $problems = [];
            $prices = $this->loadPrices($tariff, $archiveID, $from, $to, $problems);
            foreach ($wanted as $def) {
                $end = min($def['end'], $today);
                $slice = array_values(array_filter(
                    $intervals,
                    static fn (array $iv): bool => $iv['start'] >= $def['start'] && $iv['start'] < $end
                ));
                $peak = ErTile::peakDay($slice, $tariff, $unit['factor'], $gas, $def['start'], $end, $prices, $includeBase);
                if ($peak !== null) {
                    $map[$def['key']] = $peak;
                }
            }
        }
        $this->WriteAttributeString('TilePeaks', json_encode(['sig' => $signature, 'map' => $map]));
        return $map;
    }

    /** Aktionen aus der Kachel. Gibt true zurück, wenn die Aktion zur Kachel gehört. */
    private function tileAction(string $Ident): bool
    {
        if ($Ident === 'TileRecalculate') {
            $this->Recalculate();
            return true;
        }
        return false;
    }

    /** @return array<string, string> */
    private function tileLabels(): array
    {
        return [
            'day'                 => $this->Translate('Day'),
            'week'                => $this->Translate('Week'),
            'month'               => $this->Translate('Month'),
            'year'                => $this->Translate('Year'),
            'more'                => $this->Translate('More'),
            'costs'               => $this->Translate('Costs'),
            'costsWork'           => $this->Translate('Costs (usage)'),
            'costsBase'           => $this->Translate('Costs (base price)'),
            'effective'           => $this->Translate('Effective price per kWh'),
            'consumption'         => $this->Translate('Consumption'),
            'ht'                  => $this->Translate('HT'),
            'nt'                  => $this->Translate('NT'),
            'energy'              => $this->Translate('Energy (kWh)'),
            'forecast'            => $this->Translate('Forecast'),
            'forecastCosts'       => $this->Translate('Forecast costs'),
            'forecastConsumption' => $this->Translate('Forecast consumption'),
            'advance'             => $this->Translate('Advance payments'),
            'balance'             => $this->Translate('Balance'),
            'recalculate'         => $this->Translate('Recalculate'),
            'updated'             => $this->Translate('Updated'),
            'tapDetails'          => $this->Translate('Tap for all values'),
            'back'                => $this->Translate('Tap for the overview'),
            'avgPerDay'           => $this->Translate('Average per day'),
            'tariff'              => $this->Translate('Tariff'),
            'validUntil'          => $this->Translate('valid until'),
            'dynamicPrice'        => $this->Translate('dynamic price'),
            'peakDay'             => $this->Translate('Most expensive day'),
            'credit'              => $this->Translate('Credit'),
            'additionalPayment'   => $this->Translate('Additional payment'),
            'vsLastYear'          => $this->Translate('vs. last year'),
            'noPeriods'           => $this->Translate('No periods enabled.')
        ];
    }
}
