<?php

declare(strict_types=1);

require_once __DIR__ . '/ErTile.php';
require_once __DIR__ . '/ErExtras.php';

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
            'tariff'   => ErExtras::currentTariff($tariff, $now)
        ], $now);
        $json = json_encode($data);
        $this->WriteAttributeString('TileData', $json);
        if ($this->ReadPropertyBoolean('TileEnabled')) {
            $this->UpdateVisualizationValue($json);
        }
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
