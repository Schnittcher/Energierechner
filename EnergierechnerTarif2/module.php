<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ErTariff.php';
require_once __DIR__ . '/../libs/ErPeriods.php';
require_once __DIR__ . '/../libs/ErPriceLookup.php';
require_once __DIR__ . '/../libs/ErCurrency.php';

/**
 * Hält die Tarifabschnitte (Preise, Grundpreis, Nachttarif, Abschlag, Gasumrechnung).
 * Mehrere Energierechner 2 können sich dieselbe Tarif-Instanz teilen.
 */
class EnergierechnerTarif2 extends IPSModuleStrict
{
    private const DATAFLOW_TO_CHILDREN = '{EC6EE5AC-D081-4AB4-9F2C-5F4232781DD9}';
    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    private const STATUS_NO_PERIODS = 201;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterPropertyString('Periods', '[]');
        // Währung der Preise (nur Beschriftung, das Modul rechnet nicht um); Standard ist der Euro
        $this->RegisterPropertyString('Currency', ErCurrency::DEFAULT);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $rows = json_decode($this->ReadPropertyString('Periods'), true);
        $rows = is_array($rows) ? $rows : [];

        // Jede Zeile braucht eine stabile, eindeutige Id (sie ist Teil der Variablen-Idents im Energierechner).
        // Store-Richtlinie (Eigenschaften gehören dem Nutzer): IPS_SetProperty ist hier die einzige Ausnahme und aus diesem Grund nötig:
        // Die Id der Zeile steckt im Ident der Tarifzeitraum-Variablen (Tariff_<Id>_Costs). Fehlt sie oder kommt sie doppelt vor
        // (Zeile aus einem Skript, kopierte Zeile), muss sie dauerhaft in der Eigenschaft stehen. Sonst bekäme die Zeile bei jedem
        // Übernehmen eine neue Id, und der Energierechner würde die Variablen löschen und neu anlegen (Verknüpfungen, Skripte und
        // Archivdaten gingen verloren). Geändert wird nur das Feld "Id", und nur wenn es fehlt oder doppelt ist.
        [$rows, $changed] = $this->normalizeIds($rows);
        if ($changed) {
            IPS_SetProperty($this->InstanceID, 'Periods', json_encode($rows));
            $this->RegisterOnceTimer('ApplyChanges', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
            return;
        }

        [$tariff, $warnings] = ErTariff::fromFormRows($rows);
        foreach ($warnings as $warning) {
            $this->SendDebug(__FUNCTION__, $warning, 0);
        }
        $this->SetStatus($tariff->isEmpty() ? self::STATUS_NO_PERIODS : IS_ACTIVE);

        // Verbundene Energierechner neu berechnen lassen
        $this->SendDataToChildren(json_encode(['DataID' => self::DATAFLOW_TO_CHILDREN, 'Event' => 'TariffChanged']));
    }

    public function ForwardData(string $JSONString): string
    {
        $this->SendDebug(__FUNCTION__, $JSONString, 0);
        $data = json_decode($JSONString, true);

        switch ($data['Command'] ?? '') {
            case 'GetTariff':
                return $this->GetTariff();
            case 'GetCurrency':
                return $this->currency();
            default:
                $this->LogMessage('Invalid command: ' . ($data['Command'] ?? ''), KL_WARNING);
                return '';
        }
    }

    /**
     * Liefert die Tarifabschnitte als JSON (aufsteigend nach Gültigkeitsbeginn).
     */
    public function GetTariff(): string
    {
        $rows = json_decode($this->ReadPropertyString('Periods'), true);
        [$tariff] = ErTariff::fromFormRows(is_array($rows) ? $rows : []);
        return json_encode($tariff->toArray());
    }

    /**
     * Der Preis, der zum Zeitpunkt gilt (ohne Angabe: jetzt): Hochtarif, Niedertarif oder aktueller Preis eines dynamischen Tarifs.
     * Ersatz für ER_getPrice des alten Moduls. Rückgabe wie ER2_GetPrice: price, type, dynamic, fallback, tariff, supplier,
     * validFrom und validUntil, dazu currency (Währungscode des Tarifs, der Preis steht in dieser Währung je Einheit des Zählers).
     *
     * @return array{price:float,type:string,dynamic:bool,fallback:bool,tariff:string,supplier:string,validFrom:int,validUntil:?int,currency:string}
     */
    public function GetPrice(int $Timestamp = 0): array
    {
        $now = time();
        $timestamp = $Timestamp > 0 ? $Timestamp : $now;
        $rows = json_decode($this->ReadPropertyString('Periods'), true);
        [$tariff] = ErTariff::fromFormRows(is_array($rows) ? $rows : []);
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE);
        $archiveID = (int) ($archives[0] ?? 0);
        return ErPriceLookup::at(
            $tariff,
            $timestamp,
            static fn (ErTariffSegment $segment, int $ts): ?float => ErPriceLookup::dynamicValue($segment, $ts, $archiveID, $now)
        ) + ['currency' => $this->currency()];
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $currency = ErCurrency::info($this->currency());
        foreach ($form['elements'] as $e => $element) {
            if (($element['name'] ?? '') !== 'Periods') {
                continue;
            }
            foreach ($element['columns'] as $c => $column) {
                // Die Preiseinheit der Preisvariable nennt die Währung des Tarifs (die Werte EUR_KWH, CT_KWH und EUR_MWH bleiben)
                if ($column['name'] === 'PriceUnit' && $currency['code'] !== ErCurrency::DEFAULT) {
                    $form['elements'][$e]['columns'][$c]['edit']['options'] = [
                        ['caption' => $currency['symbol'] . '/kWh ' . $this->Translate('(or per m³ or liter)'), 'value' => 'EUR_KWH'],
                        ['caption' => $currency['minor'] . '/kWh', 'value' => 'CT_KWH'],
                        ['caption' => $currency['symbol'] . '/MWh', 'value' => 'EUR_MWH']
                    ];
                }
                // Neue Zeilen bekommen eine frische Id; doppelte Ids werden in ApplyChanges bereinigt.
                if ($column['name'] === 'Id') {
                    $form['elements'][$e]['columns'][$c]['add'] = ErPeriods::newId();
                }
                if ($column['name'] === 'ValidFrom') {
                    $form['elements'][$e]['columns'][$c]['add'] = ['year' => (int) date('Y'), 'month' => 1, 'day' => 1];
                }
            }
        }
        return json_encode($form);
    }

    /** Währungscode der Preise dieses Tarifs (ein unbekannter oder leerer Wert gilt als Euro). */
    private function currency(): string
    {
        return ErCurrency::normalize($this->ReadPropertyString('Currency'));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0:array<int, array<string, mixed>>,1:bool}
     */
    private function normalizeIds(array $rows): array
    {
        $changed = false;
        $seen = [];
        foreach ($rows as $i => $row) {
            $id = ErPeriods::sanitizeId((string) ($row['Id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                $id = ErPeriods::newId();
            }
            if (($row['Id'] ?? '') !== $id) {
                $rows[$i]['Id'] = $id;
                $changed = true;
            }
            $seen[$id] = true;
        }
        return [$rows, $changed];
    }
}
