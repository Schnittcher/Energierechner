<?php

declare(strict_types=1);

require_once __DIR__ . '/ErPeriods.php';

/**
 * Überführt die Einstellungen des alten Energierechner-Moduls in das neue.
 * Reine Umrechnung ohne IPS-Abhängigkeit.
 */
final class ErLegacy
{
    private const UNITS = [
        '~Electricity'    => 'kWh',
        '~Electricity.Wh' => 'Wh',
        '~Gas'            => 'm3',
        'ER.Liter'        => 'Liter'
    ];

    private const FLAGS = [
        'Daily'              => 'DayCurrent',
        'PreviousDay'        => 'DayPrevious',
        'CurrentWeek'        => 'WeekCurrent',
        'PreviousWeek'       => 'WeekPrevious',
        'CurrentMonth'       => 'MonthCurrent',
        'LastMonth'          => 'MonthPrevious',
        'CurrentYear'        => 'YearCurrent',
        'LastYear'           => 'YearPrevious',
        'PeriodsCalculation' => 'TariffPeriods',
        'Balance'            => 'ShowBalance',
        'AddBasePrice'       => 'IncludeBaseCosts'
    ];

    /**
     * @param array<string, mixed> $old Konfiguration des alten Energierechners
     * @return array<string, mixed> Eigenschaften des neuen Moduls
     */
    public static function convertSettings(array $old): array
    {
        $new = [
            'Active'                => (bool) ($old['Active'] ?? false),
            'ConsumptionVariableID' => (int) ($old['consumptionVariableID'] ?? 0),
            'Unit'                  => self::UNITS[$old['ProfileType'] ?? ''] ?? '',
            'ImpulsesPerKwh'        => max(1, (int) ($old['Impulse_kWh'] ?? 1000)),
            'GasConversion'         => (bool) ($old['GasPriceCalculationActive'] ?? false),
            'ShowHtNt'              => (bool) ($old['DailyConsumption'] ?? false) || (bool) ($old['NightlyConsumption'] ?? false),
            'UpdateInterval'        => max(1, intdiv((int) ($old['UpdateInterval'] ?? 600), 60))
        ];
        // Das alte Modul rechnete bei "Gaspreis berechnen" den Zählerwert (m³) selbst in kWh um und zeigte kWh an (Profil kWh).
        // Im neuen Modul muss dafür die Zählereinheit m³ gesetzt sein, sonst greift die Umrechnung nicht und m³ werden wie kWh bepreist.
        if (!empty($old['GasPriceCalculationActive'])) {
            $new['Unit'] = 'm3';
        }
        if (!empty($old['Impulse_kWhBool'])) {
            $new['Unit'] = 'Impulse';
        }
        foreach (self::FLAGS as $oldKey => $newKey) {
            $new[$newKey] = (bool) ($old[$oldKey] ?? false);
        }

        $custom = [];
        if (!empty($old['IndividualPeriods'])) {
            $rows = json_decode((string) ($old['IndividualPeriodsList'] ?? '[]'), true);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $from = ErTariff::decode($row['startDate'] ?? null);
                $to = ErTariff::decode($row['endDate'] ?? null);
                if ($from === null || $to === null) {
                    continue;
                }
                $custom[] = [
                    'Id'   => ErPeriods::newId(),
                    'Name' => sprintf('%02d.%02d.%04d - %02d.%02d.%04d', $from['day'], $from['month'], $from['year'], $to['day'], $to['month'], $to['year']),
                    'From' => json_encode(['year' => (int) $from['year'], 'month' => (int) $from['month'], 'day' => (int) $from['day']]),
                    'To'   => json_encode(['year' => (int) $to['year'], 'month' => (int) $to['month'], 'day' => (int) $to['day']])
                ];
            }
        }
        $new['CustomPeriods'] = json_encode($custom);
        return $new;
    }

    /**
     * Wandelt die Tarifliste des alten Tarif-Moduls in Zeilen des neuen um.
     * Das Nachtfenster des alten Moduls wird zum ersten Niedertarif-Zeitfenster (NT), der Tagpreis zum
     * Hochtarif-Preis (HT). Hat das alte Modul den Nachttarif nicht benutzt, wird kein NT-Fenster
     * übernommen, damit die Kosten unverändert bleiben.
     *
     * @param array<int, array<string, mixed>> $oldRows
     * @return array<int, array<string, mixed>>
     */
    public static function convertTariffRows(array $oldRows, bool $nightWasUsed): array
    {
        $rows = [];
        foreach ($oldRows as $old) {
            $nightFrom = $nightWasUsed ? ErTariff::decode($old['NightTimeStart'] ?? null) : null;
            $nightTo = $nightWasUsed ? ErTariff::decode($old['NightTimeEnd'] ?? null) : null;
            $time = static fn (?array $t): string => json_encode(['hour' => (int) ($t['hour'] ?? 0), 'minute' => (int) ($t['minute'] ?? 0), 'second' => 0]);
            $date = ErTariff::decode($old['StartDate'] ?? null);

            $rows[] = [
                'Id'           => ErPeriods::newId(),
                'Name'         => $date !== null ? sprintf('%02d.%02d.%04d', $date['day'], $date['month'], $date['year']) : '',
                'Supplier'     => (string) ($old['ElectricitySuppliers'] ?? ''),
                'ValidFrom'    => json_encode($date ?? []),
                'PriceHT'      => (float) ($old['DayPrice'] ?? 0),
                'PriceNT'      => $nightWasUsed ? (float) ($old['NightPrice'] ?? 0) : 0.0,
                'NtFrom1'      => $time($nightFrom),
                'NtTo1'        => $time($nightTo),
                'NtFrom2'      => $time(null),
                'NtTo2'        => $time(null),
                'NtWeekend'    => false,
                'BasePrice'    => (float) ($old['BasePrice'] ?? 0),
                'Advance'      => (float) ($old['AdvancePayment'] ?? 0),
                'AdvanceCount' => (int) ($old['DeductionsPerYear'] ?? 0),
                'GasFactor'    => (float) ($old['GasConversionFactor'] ?? 0),
                'GasZ'         => (float) ($old['GasZNumber'] ?? 0),
                'GasCalorific' => (float) ($old['GasCalorificValue'] ?? 0)
            ];
        }
        return $rows;
    }
}
