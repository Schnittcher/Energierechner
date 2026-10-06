<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ErTile.php';

/**
 * Reine Anzeige-Instanz für die Kachel-Visualisierung (experimentell).
 * Sie zeigt einen festen Zeitraum eines Energierechner 2 und rechnet nichts selbst: Die Kacheldaten
 * holt sie von der Quelle (ER2_GetTileData) und gibt sie an die Kachel weiter. So lassen sich mehrere
 * Kacheln mit verschiedenen Zeiträumen aus einem Energierechner anlegen.
 *
 * Gehört zur Kachel: zum Entfernen diesen Ordner löschen.
 */
class EnergierechnerKachel extends IPSModuleStrict
{
    private const SOURCE_MODULE = '{4E7A6F79-8C7E-4D15-900E-1B97DD916FC4}';
    private const STATUS_NO_SOURCE = 201;
    private const STATUS_WRONG_SOURCE = 202;
    private const REFRESH_SECONDS = 60;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterPropertyInteger('SourceID', 0);
        $this->RegisterPropertyString('Period', 'Month_Current');
        $this->RegisterPropertyString('Display', 'auto');
        $this->RegisterPropertyString('Title', '');
        $this->RegisterAttributeString('Last', '');
        $this->RegisterTimer('Refresh', 0, 'ERK_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $id => $messages) {
            $this->UnregisterMessage($id, VM_UPDATE);
        }

        $source = $this->ReadPropertyInteger('SourceID');
        if ($source === 0) {
            $this->SetStatus(self::STATUS_NO_SOURCE);
            $this->SetTimerInterval('Refresh', 0);
            return;
        }
        if (!$this->isSource($source)) {
            $this->SetStatus(self::STATUS_WRONG_SOURCE);
            $this->SetTimerInterval('Refresh', 0);
            return;
        }
        $this->SetStatus(IS_ACTIVE);

        // Sofort aktualisieren, sobald die Quelle neu gerechnet hat; der Timer ist die Rückfallebene
        $variable = @IPS_GetObjectIDByIdent('LastCalculation', $source);
        if (is_int($variable) && $variable > 0) {
            $this->RegisterMessage($variable, VM_UPDATE);
        }
        $this->SetTimerInterval('Refresh', self::REFRESH_SECONDS * 1000);
        $this->WriteAttributeString('Last', '');
        $this->Refresh();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->Refresh();
        }
    }

    /** Holt die Kacheldaten der Quelle und schickt sie an die Kachel, wenn sie sich geändert haben. */
    public function Refresh(): void
    {
        if ($this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $json = $this->buildData();
        if ($json === $this->ReadAttributeString('Last')) {
            return;
        }
        $this->WriteAttributeString('Last', $json);
        $this->UpdateVisualizationValue($json);
    }

    /** Die Daten der Kachel als JSON (für Skripte und zur Fehlersuche). */
    public function GetTileData(): string
    {
        return $this->buildData();
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/../Energierechner2/tile.html');
        // "</" im JSON würde das Skript-Element beenden
        return str_replace('__TILE_DATA__', str_replace('</', '<\/', $this->buildData()), (string) $html);
    }

    private function isSource(int $id): bool
    {
        return IPS_InstanceExists($id) && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::SOURCE_MODULE;
    }

    private function buildData(): string
    {
        $source = $this->ReadPropertyInteger('SourceID');
        $data = [];
        if ($source > 0 && $this->isSource($source) && function_exists('ER2_GetTileData')) {
            // Die Anzeige rechnet nie selbst und stößt auch keine Berechnung der Quelle an: Ein verschachtelter Aufruf
            // stört deren Abfrage des Tarifs und kann die Quelle auf Status 205 setzen.
            $data = json_decode((string) ER2_GetTileData($source), true);
        }
        $data = is_array($data) ? $data : [];
        $data += ['title' => IPS_GetName($this->InstanceID), 'unit' => '', 'updated' => 0, 'warnings' => [], 'flags' => [], 'labels' => ['noPeriods' => $this->Translate('The Energierechner 2 has not calculated yet.')], 'periods' => []];

        return (string) json_encode(ErTile::fixed($data, $this->ReadPropertyString('Period'), $this->ReadPropertyString('Display'), trim($this->ReadPropertyString('Title'))));
    }
}
