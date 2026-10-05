<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ErTariff.php';
require_once __DIR__ . '/../libs/ErCalculator.php';
require_once __DIR__ . '/../libs/ErArchiveReader.php';
require_once __DIR__ . '/../libs/ErPeriods.php';
require_once __DIR__ . '/../libs/ErPriceSeries.php';
require_once __DIR__ . '/../libs/ErForecast.php';
require_once __DIR__ . '/../libs/ErLegacy.php';
require_once __DIR__ . '/../libs/ErTileTrait.php'; // KACHEL

/**
 * Berechnet Verbrauch und Kosten eines geloggten Zählers für frei wählbare Zeiträume
 * mit Tarifwechseln, Nachttarif, Grundpreis, Prognose und Abschlags-Saldo.
 *
 * Die Rechenlogik liegt in libs/ (ErCalculator, ErTariff, ...) und ist getrennt testbar.
 */
class Energierechner2 extends IPSModuleStrict
{
    use ErTileTrait; // KACHEL

    private const TARIFF_MODULE = '{1765D09A-4E07-4B50-A6AC-B75B17D773EB}';
    private const LEGACY_MODULE = '{E02FA048-D39D-4B25-A241-9FAD43789764}';
    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const DATAFLOW_TO_PARENT = '{933FDCC6-2D94-454E-A696-84988700E4D1}';

    private const STATUS_NO_VARIABLE = 201;
    private const STATUS_NO_ARCHIVE = 202;
    private const STATUS_NOT_LOGGED = 203;
    private const STATUS_NOT_COUNTER = 204;
    private const STATUS_NO_TARIFF = 205;

    private const FIXED_LABELS = [
        'Day_Current'    => 'Today',
        'Day_Previous'   => 'Yesterday',
        'Week_Current'   => 'Current week',
        'Week_Previous'  => 'Previous week',
        'Month_Current'  => 'Current month',
        'Month_Previous' => 'Previous month',
        'Year_Current'   => 'Current year',
        'Year_Previous'  => 'Previous year'
    ];

    /** Variablenart => Feld im Rechenergebnis */
    private const KINDS = [
        'Costs'               => 'costs',
        'Consumption'         => 'consumption',
        'CostsWork'           => 'costsWork',
        'CostsBase'           => 'costsBase',
        'ConsumptionHT'       => 'consumptionHt',
        'CostsHT'             => 'costsHt',
        'ConsumptionNT'       => 'consumptionNt',
        'CostsNT'             => 'costsNt',
        'Energy'              => 'energy',
        'ForecastCosts'       => 'forecastCosts',
        'ForecastConsumption' => 'forecastConsumption',
        'Balance'             => 'balance'
    ];

    private const KIND_LABELS = [
        'Costs'               => 'Costs',
        'Consumption'         => 'Consumption',
        'CostsWork'           => 'Costs (usage)',
        'CostsBase'           => 'Costs (base price)',
        'ConsumptionHT'       => 'Consumption (HT)',
        'CostsHT'             => 'Costs (HT)',
        'ConsumptionNT'       => 'Consumption (NT)',
        'CostsNT'             => 'Costs (NT)',
        'Energy'              => 'Energy (kWh)',
        'ForecastCosts'       => 'Forecast costs',
        'ForecastConsumption' => 'Forecast consumption',
        'Balance'             => 'Balance'
    ];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->tileCreate(); // KACHEL

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('ConsumptionVariableID', 0);
        $this->RegisterPropertyString('Unit', '');
        $this->RegisterPropertyInteger('ImpulsesPerKwh', 1000);
        $this->RegisterPropertyBoolean('GasConversion', false);

        foreach (array_keys(ErPeriods::FIXED) as $property) {
            $this->RegisterPropertyBoolean($property, false);
        }
        $this->RegisterPropertyBoolean('TariffPeriods', false);
        $this->RegisterPropertyString('CustomPeriods', '[]');

        $this->RegisterPropertyBoolean('ShowHtNt', false);
        $this->RegisterPropertyBoolean('ShowBaseCosts', false);
        $this->RegisterPropertyBoolean('IncludeBaseCosts', true);
        $this->RegisterPropertyBoolean('ShowForecast', false);
        $this->RegisterPropertyBoolean('ForecastLastYear', true);
        $this->RegisterPropertyBoolean('ShowBalance', false);
        $this->RegisterPropertyBoolean('LogClosedPeriods', false);
        $this->RegisterPropertyInteger('UpdateInterval', 10);

        $this->RegisterAttributeString('AutoLogged', '[]');
        $this->RegisterAttributeString('Warnings', '[]');
        $this->RegisterAttributeString('ForecastShares', '{}');
        $this->RegisterAttributeString('Cache', '{}');
        $this->RegisterAttributeString('Idents', '[]');
        $this->RegisterAttributeString('TariffSig', '');

        $this->RegisterTimer('UpdateCalculation', 0, 'ER2_UpdateCalculation($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->tileApply(); // KACHEL

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Eigene Zeiträume brauchen stabile, eindeutige Ids (Teil der Variablen-Idents)
        $custom = json_decode($this->ReadPropertyString('CustomPeriods'), true);
        [$custom, $changed] = $this->normalizeIds(is_array($custom) ? $custom : []);
        if ($changed) {
            IPS_SetProperty($this->InstanceID, 'CustomPeriods', json_encode($custom));
            $this->RegisterOnceTimer('ApplyChanges', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
            return;
        }

        $tariff = $this->fetchTariff();
        $this->maintainVariables($tariff);
        $this->syncArchiveLogging();
        if ($tariff !== null) {
            $this->WriteAttributeString('TariffSig', $this->tariffSignature($tariff));
        }

        // Referenz auf die Zählervariable, damit sie nicht unbemerkt gelöscht wird
        foreach ($this->GetReferenceList() ?: [] as $referenceID) {
            $this->UnregisterReference($referenceID);
        }
        $variableID = $this->ReadPropertyInteger('ConsumptionVariableID');
        if ($variableID > 0 && IPS_VariableExists($variableID)) {
            $this->RegisterReference($variableID);
            $this->SetSummary(IPS_GetName($variableID));
        } else {
            $this->SetSummary('');
        }

        $status = $this->validate($tariff);
        $this->SetStatus($status);

        if ($status === IS_ACTIVE) {
            $this->SetTimerInterval('UpdateCalculation', max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);
            $this->RegisterOnceTimer('Calculate', 'ER2_UpdateCalculation($_IPS[\'TARGET\']);');
        } else {
            $this->SetTimerInterval('UpdateCalculation', 0);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (($data['Event'] ?? '') === 'TariffChanged') {
            // Tarifzeiträume können sich geändert haben: Variablen neu abgleichen und neu rechnen
            $this->RegisterOnceTimer('ApplyChanges', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($this->tileAction($Ident)) { // KACHEL
            return; // KACHEL
        } // KACHEL
        if ($Ident === 'UnitChanged') {
            $this->UpdateFormField('ImpulsesPerKwh', 'visible', (string) $Value === 'Impulse');
            $this->UpdateFormField('GasConversion', 'visible', (string) $Value === 'm3');
        }
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'    => 'connect',
            'modules' => [['moduleID' => self::TARIFF_MODULE]]
        ]);
    }

    /**
     * Berechnet alle aktivierten Zeiträume. Abgeschlossene Zeiträume werden zwischengespeichert.
     */
    public function UpdateCalculation(): bool
    {
        return $this->calculate();
    }

    /**
     * Wie UpdateCalculation, verwirft aber vorher den Zwischenspeicher der abgeschlossenen Zeiträume.
     */
    public function Recalculate(): bool
    {
        $this->WriteAttributeString('Cache', '{}');
        $this->WriteAttributeString('ForecastShares', '{}');
        return $this->calculate();
    }

    /**
     * Übernimmt Einstellungen, Zeiträume und Tarife aus einer Instanz des alten Energierechner-Moduls.
     * Die neue Instanz bleibt danach inaktiv, bis du die Werte geprüft hast.
     */
    public function ImportLegacy(int $LegacyInstanceID): string
    {
        if (!IPS_InstanceExists($LegacyInstanceID)) {
            return $this->Translate('The selected instance does not exist.');
        }
        $legacy = IPS_GetInstance($LegacyInstanceID);
        if ($legacy['ModuleInfo']['ModuleID'] !== self::LEGACY_MODULE) {
            return $this->Translate('The selected instance is not an Energierechner.');
        }

        $old = json_decode(IPS_GetConfiguration($LegacyInstanceID), true);
        $settings = ErLegacy::convertSettings(is_array($old) ? $old : []);
        $settings['Active'] = false;
        foreach ($settings as $property => $value) {
            IPS_SetProperty($this->InstanceID, $property, $value);
        }

        $messages = [$this->Translate('Settings imported.')];
        if (($settings['Unit'] ?? '') === 'Wh') {
            $messages[] = $this->Translate('Meter unit Wh: prices in the tariff must now be per kWh. If you entered a price per Wh in the old module, multiply it by 1000.');
        }
        $legacyTariffID = (int) $legacy['ConnectionID'];
        if ($legacyTariffID > 0) {
            $oldTariff = json_decode(IPS_GetConfiguration($legacyTariffID), true);
            $oldRows = json_decode((string) ($oldTariff['Periods'] ?? '[]'), true);
            $nightUsed = !empty($old['NightRate']) || !empty($old['NightlyConsumption']);
            $rows = ErLegacy::convertTariffRows(is_array($oldRows) ? $oldRows : [], $nightUsed);

            $tariffID = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($tariffID <= 0 || IPS_GetInstance($tariffID)['ModuleInfo']['ModuleID'] !== self::TARIFF_MODULE) {
                $tariffID = IPS_CreateInstance(self::TARIFF_MODULE);
                IPS_SetName($tariffID, 'Energierechner Tarif 2');
                IPS_SetParent($tariffID, IPS_GetParent($this->InstanceID));
                IPS_ConnectInstance($this->InstanceID, $tariffID);
                $messages[] = $this->Translate('A new tariff instance was created.');
            }
            $existing = json_decode(IPS_GetProperty($tariffID, 'Periods'), true);
            if (is_array($existing) && count($existing) > 0) {
                $messages[] = $this->Translate('The connected tariff already contains entries and was not changed.');
            } else {
                IPS_SetProperty($tariffID, 'Periods', json_encode($rows));
                IPS_ApplyChanges($tariffID);
                $messages[] = sprintf($this->Translate('%d tariff periods imported.'), count($rows));
            }
        }

        IPS_ApplyChanges($this->InstanceID);
        $messages[] = $this->Translate('The instance is inactive. Please check the values and activate it.');
        return implode("\n", $messages);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $unit = $this->ReadPropertyString('Unit');
        $walk = function (array &$elements) use (&$walk, $unit): void
        {
            foreach ($elements as &$element) {
                // Impulse pro kWh nur bei Impulszählern, die Gas-Umrechnung nur bei m³ anzeigen
                if (($element['name'] ?? '') === 'ImpulsesPerKwh') {
                    $element['visible'] = $unit === 'Impulse';
                }
                if (($element['name'] ?? '') === 'GasConversion') {
                    $element['visible'] = $unit === 'm3';
                }
                if (($element['name'] ?? '') === 'CustomPeriods') {
                    foreach ($element['columns'] as &$column) {
                        if ($column['name'] === 'Id') {
                            $column['add'] = ErPeriods::newId();
                        }
                    }
                    unset($column);
                }
                foreach (['items', 'elements'] as $child) {
                    if (isset($element[$child]) && is_array($element[$child])) {
                        $walk($element[$child]);
                    }
                }
            }
            unset($element);
        };
        $walk($form['elements']);

        // Warnungen der letzten Berechnung oben im Formular anzeigen
        $warnings = json_decode($this->ReadAttributeString('Warnings'), true);
        $labels = [];
        foreach (is_array($warnings) ? $warnings : [] as $warning) {
            $labels[] = ['type' => 'Label', 'caption' => '⚠ ' . $warning, 'bold' => true];
        }
        $form['elements'] = array_merge($labels, $form['elements']);

        return json_encode($form);
    }

    // ------------------------------------------------------------------ Berechnung

    private function calculate(): bool
    {
        $status = $this->GetStatus();
        if ($status !== IS_ACTIVE && $status !== self::STATUS_NO_TARIFF) {
            return false;
        }
        $semaphore = 'ER2_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 0)) {
            return false; // Berechnung läuft bereits
        }
        try {
            return $this->doCalculate($status);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function doCalculate(int $status): bool
    {
        $now = time();
        $tariff = $this->fetchTariff();
        if ($tariff === null || $tariff->isEmpty()) {
            $this->SetStatus(self::STATUS_NO_TARIFF);
            return false;
        }
        if ($status === self::STATUS_NO_TARIFF) {
            $this->SetStatus(IS_ACTIVE);
        }
        if ($this->tariffSignature($tariff) !== $this->ReadAttributeString('TariffSig')) {
            // Die Tarifzeiträume haben sich geändert: erst die Variablen abgleichen
            $this->RegisterOnceTimer('ApplyChanges', 'IPS_ApplyChanges($_IPS[\'TARGET\']);');
            return false;
        }

        $unit = $this->unitInfo();
        $archiveID = $this->archiveID();
        $variableID = $this->ReadPropertyInteger('ConsumptionVariableID');
        if ($unit === null || $archiveID === 0 || !IPS_VariableExists($variableID)) {
            return false;
        }
        $gas = $this->gasConversion();
        $includeBase = $this->ReadPropertyBoolean('IncludeBaseCosts');
        $aggregation = ErArchiveReader::aggregationFor($tariff);

        $signature = md5(json_encode([$tariff->toArray(), $unit['factor'], $gas, $variableID, $aggregation, $includeBase]));
        $cache = json_decode($this->ReadAttributeString('Cache'), true);
        $cache = is_array($cache) ? $cache : [];

        $results = [];
        $jobs = [];
        $fresh = [];
        $defs = $this->periodDefs($tariff, $now);
        foreach ($defs as $def) {
            if ($def['key'] === 'Total') {
                continue;
            }
            $cached = $cache[$def['key']] ?? null;
            $closed = $def['end'] <= $now - 3600; // Archivdaten sind vollständig
            if ($closed && is_array($cached) && $cached['sig'] === $signature && $cached['start'] === $def['start'] && $cached['end'] === $def['end']) {
                $results[$def['key']] = $cached['r'];
                continue;
            }
            $jobs[] = $def + ['closed' => $closed];
        }

        if (count($jobs) > 0) {
            $from = min(array_column($jobs, 'start'));
            $to = min(max(array_column($jobs, 'end')) - 1, $now);
            $fetch = static fn (int $a, int $f, int $t): array => (array) AC_GetAggregatedValues($archiveID, $variableID, $a, $f, $t, 0);
            $intervals = ErArchiveReader::read($fetch, $aggregation, $from, $to);
            $this->SendDebug(__FUNCTION__, sprintf('%d intervals (aggregation %d), %d periods', count($intervals), $aggregation, count($jobs)), 0);

            $priceProblems = [];
            $prices = $this->loadPrices($tariff, $archiveID, $from, $to, $priceProblems);

            foreach ($jobs as $job) {
                $slice = array_values(array_filter(
                    $intervals,
                    static fn (array $iv): bool => $iv['start'] >= $job['start'] && $iv['start'] < $job['end']
                ));
                $share = null;
                if ($this->ReadPropertyBoolean('ForecastLastYear') && in_array($job['key'], ErPeriods::FORECAST_SEASONAL, true)) {
                    $share = $this->seasonalShare($archiveID, $variableID, $job['key'], $job['start'], $job['end'], $now);
                }
                $result = ErCalculator::calculate($slice, $tariff, $unit['factor'], $gas, $job['start'], $job['end'], $now, $includeBase, $prices, $share);
                if ($result['forecastMethod'] !== null) {
                    $this->SendDebug($job['key'], 'Forecast method: ' . $result['forecastMethod'] . ($share !== null ? sprintf(' (last year share %.3f)', $share) : ''), 0);
                }
                if ($priceProblems !== []) {
                    $result['warnings'][] = 'priceVariable';
                }
                $results[$job['key']] = $result;
                $fresh[$job['key']] = true;
                if ($job['closed']) {
                    $cache[$job['key']] = [
                        'sig'   => $signature,
                        'start' => $job['start'],
                        'end'   => $job['end'],
                        'r'     => $result,
                        'fp'    => $this->fingerprint($tariff, $archiveID, $variableID, $job['start'], $job['end'])
                    ];
                }
                if ($result['warnings'] !== []) {
                    $this->SendDebug($job['key'], 'Warnings: ' . implode(', ', $result['warnings']), 0);
                }
            }
        }

        // Archivdaten abgeschlossener Zeiträume können sich nachträglich ändern (Lücke nachgetragen, Preise korrigiert)
        $cache = $this->checkArchiveChanges($cache, $fresh, $defs, $tariff, $archiveID, $variableID);
        $this->WriteAttributeString('Cache', json_encode($cache));
        foreach ($cache as $key => $entry) {
            if (!empty($entry['changed']) && isset($results[$key])) {
                $results[$key]['warnings'][] = 'archiveChanged';
            }
        }

        if ($this->ReadPropertyBoolean('TariffPeriods')) {
            $tariffResults = [];
            foreach ($results as $key => $result) {
                if (strpos($key, 'Tariff_') === 0) {
                    $tariffResults[] = $result;
                }
            }
            $results['Total'] = ErCalculator::sum($tariffResults);
        }

        $wanted = array_flip(json_decode($this->ReadAttributeString('Idents'), true) ?: []);
        foreach ($results as $key => $result) {
            $this->writeResult($key, $result, $wanted);
        }
        $this->publishWarnings($results, $defs);
        $this->tileUpdate($results, $defs, $unit['suffix'], $now, $tariff); // KACHEL
        if (isset($wanted['LastCalculation'])) {
            $this->SetValue('LastCalculation', $now);
        }
        return true;
    }

    /**
     * Anteil, den der Zeitraum im Vorjahr zum gleichen Zeitpunkt schon erreicht hatte (für die Prognose).
     * Das Vorjahr ändert sich nicht mehr, deshalb wird der Wert je Zeitraum nur einmal am Tag aus dem Archiv gelesen.
     */
    private function seasonalShare(int $archiveID, int $variableID, string $key, int $start, int $end, int $now): ?float
    {
        $cache = json_decode($this->ReadAttributeString('ForecastShares'), true);
        $cache = is_array($cache) ? $cache : [];
        $today = date('Y-m-d', $now);
        if (isset($cache[$key]) && $cache[$key]['day'] === $today && $cache[$key]['start'] === $start) {
            return $cache[$key]['share'];
        }

        $fetch = static fn (int $a, int $f, int $t): array => (array) AC_GetAggregatedValues($archiveID, $variableID, $a, $f, $t, 0);
        $daily = ErArchiveReader::read($fetch, ErArchiveReader::DAILY, ErForecast::lastYear($start), ErForecast::lastYear($end) - 1);
        $share = ErForecast::seasonalShare($daily, $start, $end, $now);

        $cache[$key] = ['day' => $today, 'start' => $start, 'share' => $share];
        $this->WriteAttributeString('ForecastShares', json_encode($cache));
        return $share;
    }

    /**
     * Fingerabdruck der Archivdaten eines Zeitraums: Summe der Tageswerte des Zählers und je dynamischem Preis.
     * Das sind wenige Datensätze und billig zu lesen.
     *
     * @return array<string, float>
     */
    private function fingerprint(ErTariff $tariff, int $archiveID, int $variableID, int $start, int $end): array
    {
        $fetchOf = static fn (int $id): callable => static fn (int $a, int $f, int $t): array => (array) AC_GetAggregatedValues($archiveID, $id, $a, $f, $t, 0);

        $fingerprint = ['consumption' => ErArchiveReader::sumDaily($fetchOf($variableID), $start, $end - 1)];
        foreach ($tariff->segments() as $i => $segment) {
            if (!$segment->isDynamic() || !IPS_VariableExists($segment->priceVariableId) || !AC_GetLoggingStatus($archiveID, $segment->priceVariableId)) {
                continue;
            }
            $from = max($start, $segment->validFrom);
            $to = min($end, $tariff->segmentEnd($i, $end)) - 1;
            if ($to >= $from) {
                $fingerprint['price' . $segment->id] = ErArchiveReader::sumDaily($fetchOf($segment->priceVariableId), $from, $to);
            }
        }
        return $fingerprint;
    }

    /**
     * Vergleicht die Archivdaten der zwischengespeicherten Zeiträume mit dem Stand bei der Berechnung.
     * Ein geänderter Zeitraum wird markiert und bleibt es, bis er neu berechnet wird.
     *
     * @param array<string, array<string, mixed>> $cache
     * @param array<string, bool> $fresh in diesem Durchlauf frisch berechnete Zeiträume (ohne Prüfung)
     * @param array<int, array{key:string,start:int,end:int}> $defs
     * @return array<string, array<string, mixed>>
     */
    private function checkArchiveChanges(array $cache, array $fresh, array $defs, ErTariff $tariff, int $archiveID, int $variableID): array
    {
        foreach ($defs as $def) {
            $key = $def['key'];
            if (!isset($cache[$key]) || isset($fresh[$key]) || !empty($cache[$key]['changed'])) {
                continue;
            }
            $entry = $cache[$key];
            if ($entry['start'] !== $def['start'] || $entry['end'] !== $def['end']) {
                continue;
            }
            $current = $this->fingerprint($tariff, $archiveID, $variableID, $entry['start'], $entry['end']);
            if (!isset($entry['fp'])) {
                $cache[$key]['fp'] = $current; // Eintrag aus einer älteren Version: aktuellen Stand als Referenz nehmen
            } elseif (!ErArchiveReader::sameFingerprint($entry['fp'], $current)) {
                $cache[$key]['changed'] = true;
                $this->SendDebug(__FUNCTION__, $key . ': archive data changed ' . json_encode($entry['fp']) . ' -> ' . json_encode($current), 0);
            }
        }
        return $cache;
    }

    /**
     * Liest die Preisreihen der dynamischen Tarifabschnitte aus dem Archiv.
     * Ohne geloggte Preisvariable gibt es keine Reihe; dann gilt der feste Preis als Ausweichwert.
     *
     * @param string[] $problems Namen der Tarifabschnitte, deren Preisvariable nicht nutzbar ist
     * @return array<string, ErPriceSeries> Schlüssel = Id des Tarifabschnitts
     */
    private function loadPrices(ErTariff $tariff, int $archiveID, int $from, int $to, array &$problems): array
    {
        $series = [];
        $openEnd = $to + 1;
        foreach ($tariff->segments() as $i => $segment) {
            if (!$segment->isDynamic()) {
                continue;
            }
            $variableID = $segment->priceVariableId;
            if (!IPS_VariableExists($variableID) || !AC_GetLoggingStatus($archiveID, $variableID)) {
                $problems[] = $segment->name !== '' ? $segment->name : date('d.m.Y', $segment->validFrom);
                continue;
            }
            $segmentFrom = max($from, $segment->validFrom);
            $segmentTo = min($to, $tariff->segmentEnd($i, $openEnd) - 1);
            if ($segmentTo < $segmentFrom) {
                continue;
            }
            $aggregation = $segment->priceResolution === 15 ? ErArchiveReader::QUARTER_HOUR : ErArchiveReader::HOURLY;
            $fetch = static fn (int $a, int $f, int $t): array => (array) AC_GetAggregatedValues($archiveID, $variableID, $a, $f, $t, 0);
            $series[$segment->id] = new ErPriceSeries(ErArchiveReader::read($fetch, $aggregation, $segmentFrom, $segmentTo));
        }
        return $series;
    }

    /**
     * Fasst die Warnungen aller Zeiträume zusammen, speichert sie für das Formular und schreibt neue ins Log.
     *
     * @param array<string, array<string, mixed>> $results
     * @param array<int, array{key:string,label:string}> $defs
     */
    private function publishWarnings(array $results, array $defs): void
    {
        $labels = [];
        foreach ($defs as $def) {
            $labels[$def['key']] = $def['label'];
        }

        $byCode = [];
        foreach ($results as $key => $result) {
            foreach ($result['warnings'] ?? [] as $code) {
                if ($key !== 'Total') {
                    $byCode[$code][] = $labels[$key] ?? $key;
                } else {
                    $byCode[$code] = $byCode[$code] ?? [];
                }
            }
        }

        $messages = [];
        foreach ($byCode as $code => $periods) {
            $text = $this->warningText((string) $code);
            $messages[] = $periods === [] ? $text : $text . ' (' . implode(', ', array_unique($periods)) . ')';
        }

        $previous = json_decode($this->ReadAttributeString('Warnings'), true);
        $previous = is_array($previous) ? $previous : [];
        foreach (array_diff($messages, $previous) as $message) {
            $this->LogMessage($message, KL_WARNING);
        }
        if ($messages !== $previous) {
            $this->WriteAttributeString('Warnings', json_encode($messages));
        }
    }

    private function warningText(string $code): string
    {
        switch ($code) {
            case 'noTariff':
                return $this->Translate('Part of the period lies before the first tariff period. Consumption without a tariff has no price.');
            case 'gasParameters':
                return $this->Translate('Gas conversion values (factor, Z-number, calorific value) are missing in a tariff period. The costs are too low.');
            case 'priceFallback':
                return $this->Translate('No price data for a dynamic tariff in some intervals. The fixed price (HT) was used there.');
            case 'priceVariable':
                return $this->Translate('The price variable of a dynamic tariff does not exist or is not logged in the archive.');
            case 'archiveChanged':
                return $this->Translate('Archive data changed after these periods were calculated. Press "Recalculate" to update them.');
        }
        return $code;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, int> $wanted
     */
    private function writeResult(string $key, array $result, array $wanted): void
    {
        foreach (self::KINDS as $kind => $field) {
            $ident = ErPeriods::ident($key, $kind);
            if (!isset($wanted[$ident]) || $result[$field] === null) {
                continue;
            }
            $this->SetValue($ident, round((float) $result[$field], 4));
        }
    }

    // ------------------------------------------------------------------ Zeiträume und Variablen

    /**
     * Alle aktivierten Zeiträume. Ohne Tarif fehlen die Tarifzeiträume.
     *
     * @return array<int, array{key:string,label:string,start:int,end:int,forecast:bool,balance:bool}>
     */
    private function periodDefs(?ErTariff $tariff, int $now): array
    {
        $defs = [];
        foreach (ErPeriods::FIXED as $property => $key) {
            if ($this->ReadPropertyBoolean($property)) {
                $span = ErPeriods::fixed($key, $now);
                $defs[] = [
                    'key'      => $key,
                    'label'    => $this->Translate(self::FIXED_LABELS[$key]),
                    'start'    => $span['start'],
                    'end'      => $span['end'],
                    'forecast' => in_array($key, ErPeriods::FORECAST, true),
                    'balance'  => in_array($key, ErPeriods::BALANCE, true)
                ];
            }
        }

        if ($this->ReadPropertyBoolean('TariffPeriods') && $tariff !== null) {
            $openEnd = ErTariff::nextDay(ErTariff::startOfDay($now));
            foreach ($tariff->segments() as $i => $segment) {
                $name = $segment->name !== '' ? $segment->name : date('d.m.Y', $segment->validFrom);
                $defs[] = [
                    'key'      => 'Tariff_' . ErPeriods::sanitizeId($segment->id),
                    'label'    => $this->Translate('Tariff period') . ' ' . $name,
                    'start'    => $segment->validFrom,
                    'end'      => $tariff->segmentEnd($i, $openEnd),
                    'forecast' => false,
                    'balance'  => true
                ];
            }
            $defs[] = [
                'key'      => 'Total',
                'label'    => $this->Translate('Total (all tariff periods)'),
                'start'    => 0,
                'end'      => 0,
                'forecast' => false,
                'balance'  => true
            ];
        }

        $custom = json_decode($this->ReadPropertyString('CustomPeriods'), true);
        foreach (is_array($custom) ? $custom : [] as $row) {
            $span = ErPeriods::custom($row);
            if ($span === null || ($row['Id'] ?? '') === '') {
                continue;
            }
            $defs[] = [
                'key'      => 'Custom_' . ErPeriods::sanitizeId((string) $row['Id']),
                'label'    => ((string) ($row['Name'] ?? '')) !== '' ? (string) $row['Name'] : date('d.m.Y', $span['start']) . ' - ' . date('d.m.Y', $span['end'] - 1),
                'start'    => $span['start'],
                'end'      => $span['end'],
                'forecast' => false,
                'balance'  => false
            ];
        }
        return $defs;
    }

    private function maintainVariables(?ErTariff $tariff): void
    {
        $unit = $this->unitInfo();
        $consumption = $this->presentation($unit['suffix'] ?? '', 2);
        $euro = $this->presentation(' €', 2);
        $kwh = $this->presentation(' kWh', 2);

        $wanted = [];
        $position = 10;
        foreach ($this->periodDefs($tariff, time()) as $def) {
            $kinds = ['Costs', 'Consumption'];
            if ($this->ReadPropertyBoolean('ShowBaseCosts')) {
                array_push($kinds, 'CostsWork', 'CostsBase');
            }
            if ($this->ReadPropertyBoolean('ShowHtNt')) {
                array_push($kinds, 'ConsumptionHT', 'CostsHT', 'ConsumptionNT', 'CostsNT');
            }
            if ($this->gasConversion()) {
                $kinds[] = 'Energy';
            }
            if ($def['forecast'] && $this->ReadPropertyBoolean('ShowForecast')) {
                array_push($kinds, 'ForecastCosts', 'ForecastConsumption');
            }
            if ($def['balance'] && $this->ReadPropertyBoolean('ShowBalance')) {
                $kinds[] = 'Balance';
            }

            foreach ($kinds as $kind) {
                $isCosts = strpos($kind, 'Costs') !== false || $kind === 'Balance';
                $presentation = $isCosts ? $euro : ($kind === 'Energy' ? $kwh : $consumption);
                $ident = ErPeriods::ident($def['key'], $kind);
                $this->MaintainVariable($ident, $def['label'] . ' - ' . $this->Translate(self::KIND_LABELS[$kind]), VARIABLETYPE_FLOAT, $presentation, $position++, true);
                $wanted[] = $ident;
            }
        }
        $this->MaintainVariable('LastCalculation', $this->Translate('Last calculation'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 1, true);
        $wanted[] = 'LastCalculation';

        // Variablen deaktivierter Zeiträume entfernen. Tarifzeiträume bleiben stehen, solange der Tarif nicht erreichbar ist,
        // damit ein verspäteter Start des Tarif-Moduls keine Variablen samt Archivdaten löscht.
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            $ident = (string) $object['ObjectIdent'];
            if ($object['ObjectType'] !== OBJECTTYPE_VARIABLE || in_array($ident, $wanted, true)) {
                continue;
            }
            if (!preg_match('/^(Day|Week|Month|Year|Tariff|Custom|Total)_/', $ident)) {
                continue;
            }
            if ($tariff === null && preg_match('/^(Tariff|Total)_/', $ident)) {
                $wanted[] = $ident;
                continue;
            }
            IPS_DeleteVariable($childID);
        }
        $this->WriteAttributeString('Idents', json_encode($wanted));
    }

    /**
     * Schaltet das Archiv-Logging für Kosten und Verbrauch der abgeschlossenen Zeiträume ein (Gestern, Vorwoche, Vormonat, Vorjahr).
     * Diese Werte ändern sich nur einmal je Zeitraum und ergeben saubere Verlaufsdiagramme.
     * Das Modul schaltet nur das wieder aus, was es selbst eingeschaltet hat. Von Hand geloggte Variablen bleiben unberührt.
     */
    private function syncArchiveLogging(): void
    {
        $archiveID = $this->archiveID();
        if ($archiveID === 0) {
            return;
        }

        $target = [];
        if ($this->ReadPropertyBoolean('LogClosedPeriods')) {
            foreach (['Day_Previous', 'Week_Previous', 'Month_Previous', 'Year_Previous'] as $period) {
                foreach (['Costs', 'Consumption'] as $kind) {
                    $ident = ErPeriods::ident($period, $kind);
                    $variableID = @$this->GetIDForIdent($ident);
                    if ($variableID) {
                        $target[$ident] = $variableID;
                    }
                }
            }
        }

        $autoLogged = json_decode($this->ReadAttributeString('AutoLogged'), true);
        $autoLogged = is_array($autoLogged) ? $autoLogged : [];
        $changed = false;

        foreach ($target as $ident => $variableID) {
            if (!AC_GetLoggingStatus($archiveID, $variableID)) {
                AC_SetLoggingStatus($archiveID, $variableID, true);
                $autoLogged[$ident] = true;
                $changed = true;
            }
        }
        foreach (array_keys($autoLogged) as $ident) {
            if (isset($target[$ident])) {
                continue;
            }
            $variableID = @$this->GetIDForIdent((string) $ident);
            if ($variableID && AC_GetLoggingStatus($archiveID, $variableID)) {
                AC_SetLoggingStatus($archiveID, $variableID, false);
                $changed = true;
            }
            unset($autoLogged[$ident]);
        }

        $this->WriteAttributeString('AutoLogged', json_encode($autoLogged));
        if ($changed) {
            IPS_ApplyChanges($archiveID);
        }
    }

    /** @return array<string, mixed> */
    private function presentation(string $suffix, int $digits): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits];
    }

    // ------------------------------------------------------------------ Konfiguration prüfen

    private function validate(?ErTariff $tariff): int
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return IS_INACTIVE;
        }
        $variableID = $this->ReadPropertyInteger('ConsumptionVariableID');
        if ($this->unitInfo() === null || $variableID <= 0 || !IPS_VariableExists($variableID)) {
            return self::STATUS_NO_VARIABLE;
        }
        $archiveID = $this->archiveID();
        if ($archiveID === 0) {
            return self::STATUS_NO_ARCHIVE;
        }
        if (!AC_GetLoggingStatus($archiveID, $variableID)) {
            return self::STATUS_NOT_LOGGED;
        }
        if (AC_GetAggregationType($archiveID, $variableID) !== 1) {
            return self::STATUS_NOT_COUNTER;
        }
        if ($tariff === null || $tariff->isEmpty()) {
            return self::STATUS_NO_TARIFF;
        }
        return IS_ACTIVE;
    }

    /** @return array{factor:float,suffix:string}|null Faktor Zählerwert -> Ausgabeeinheit und Suffix */
    private function unitInfo(): ?array
    {
        switch ($this->ReadPropertyString('Unit')) {
            case 'kWh':
                return ['factor' => 1.0, 'suffix' => ' kWh'];
            case 'Wh':
                return ['factor' => 0.001, 'suffix' => ' kWh'];
            case 'Impulse':
                return ['factor' => 1 / max(1, $this->ReadPropertyInteger('ImpulsesPerKwh')), 'suffix' => ' kWh'];
            case 'm3':
                return ['factor' => 1.0, 'suffix' => ' m³'];
            case 'Liter':
                return ['factor' => 1.0, 'suffix' => ' L'];
        }
        return null;
    }

    private function gasConversion(): bool
    {
        return $this->ReadPropertyString('Unit') === 'm3' && $this->ReadPropertyBoolean('GasConversion');
    }

    private function archiveID(): int
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE);
        return (int) ($archives[0] ?? 0);
    }

    private function fetchTariff(): ?ErTariff
    {
        if (!$this->HasActiveParent()) {
            return null;
        }
        $json = $this->SendDataToParent(json_encode(['DataID' => self::DATAFLOW_TO_PARENT, 'Command' => 'GetTariff']));
        if (!is_string($json) || $json === '') {
            return null;
        }
        $rows = json_decode($json, true);
        return is_array($rows) ? ErTariff::fromArray($rows) : null;
    }

    /** Ändert sich, wenn Tarifzeiträume hinzukommen, wegfallen oder umbenannt werden. */
    private function tariffSignature(ErTariff $tariff): string
    {
        return md5(json_encode(array_map(static fn (ErTariffSegment $s): array => [$s->id, $s->name], $tariff->segments())));
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
