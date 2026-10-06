<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ErTariff.php';
require_once __DIR__ . '/../libs/ErPeriods.php';
require_once __DIR__ . '/../libs/ErPriceLookup.php';

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
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $rows = json_decode($this->ReadPropertyString('Periods'), true);
        $rows = is_array($rows) ? $rows : [];

        // Jede Zeile braucht eine stabile, eindeutige Id (sie ist Teil der Variablen-Idents im Energierechner).
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
     * validFrom und validUntil.
     *
     * @return array{price:float,type:string,dynamic:bool,fallback:bool,tariff:string,supplier:string,validFrom:int,validUntil:?int}
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
        );
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        foreach ($form['elements'] as $e => $element) {
            if (($element['name'] ?? '') !== 'Periods') {
                continue;
            }
            foreach ($element['columns'] as $c => $column) {
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
