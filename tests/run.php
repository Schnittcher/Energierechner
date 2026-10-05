<?php

declare(strict_types=1);

/**
 * Tests für die Rechenklassen in libs/.
 *
 * Ausführung:
 *   php tests/run.php                       (reine Unit-Tests)
 *   Auf einem Symcon-System: Skript mit  require '<Modulordner>/tests/run.php';
 *   Dort laufen zusätzlich die Integrationstests gegen das Archiv (Testdaten in Kategorie "Energierechner Demodaten").
 */
date_default_timezone_set('Europe/Berlin');

require_once __DIR__ . '/../libs/ErTariff.php';
require_once __DIR__ . '/../libs/ErCalculator.php';
require_once __DIR__ . '/../libs/ErArchiveReader.php';
require_once __DIR__ . '/../libs/ErPeriods.php';
require_once __DIR__ . '/../libs/ErLegacy.php';

$GLOBALS['er_pass'] = 0;
$GLOBALS['er_fail'] = 0;

function check(string $name, mixed $actual, mixed $expected, float $eps = 1e-6): void
{
    $ok = is_float($expected) || is_float($actual)
        ? abs((float) $actual - (float) $expected) <= $eps
        : $actual === $expected;
    if ($ok) {
        $GLOBALS['er_pass']++;
        return;
    }
    $GLOBALS['er_fail']++;
    echo 'FAIL ' . $name . ': erwartet ' . json_encode($expected) . ', erhalten ' . json_encode($actual) . "\n";
}

function ts(int $y, int $m, int $d, int $h = 0, int $i = 0): int
{
    return mktime($h, $i, 0, $m, $d, $y);
}

function segment(array $o = []): ErTariffSegment
{
    return ErTariffSegment::fromArray($o + [
        'id'           => 'a',
        'name'         => 'A',
        'validFrom'    => ts(2025, 1, 1),
        'priceDay'     => 0.30,
        'priceNight'   => 0.20,
        'nightFrom'    => 0,
        'nightTo'      => 0,
        'baseYear'     => 0.0,
        'advance'      => 0.0,
        'advanceCount' => 0,
        'gasFactor'    => 0.0,
        'gasZ'         => 0.0,
        'gasCalorific' => 0.0
    ]);
}

/** Stundenintervalle [from, to) mit Verbrauch fn(hourStart). */
function hourly(int $from, int $to, callable $fn): array
{
    $out = [];
    for ($t = $from; $t < $to; $t += 3600) {
        $out[] = ['start' => $t, 'end' => $t + 3600, 'value' => (float) $fn($t)];
    }
    return $out;
}

function daily(int $from, int $to, float $value): array
{
    $out = [];
    for ($t = $from; $t < $to; $t = ErTariff::nextDay($t)) {
        $out[] = ['start' => $t, 'end' => ErTariff::nextDay($t), 'value' => $value];
    }
    return $out;
}

$night = ['nightFrom' => 22 * 60, 'nightTo' => 6 * 60];
$pattern = static fn (int $t): float => ((int) date('G', $t) >= 22 || (int) date('G', $t) < 6) ? 0.2 : 0.5;

// ---------------------------------------------------------------- Nachtfenster
$tariff = new ErTariff([segment($night)]);
$p = $tariff->split(ts(2025, 3, 3, 22), ts(2025, 3, 3, 23));
check('Nacht: 22:00 gehört zur Nacht', [count($p), $p[0]['night']], [1, true]);
$p = $tariff->split(ts(2025, 3, 3, 6), ts(2025, 3, 3, 7));
check('Nacht: 06:00 gehört zum Tag', [count($p), $p[0]['night']], [1, false]);
$p = $tariff->split(ts(2025, 3, 3, 5), ts(2025, 3, 3, 6));
check('Nacht: 05:00 gehört zur Nacht', $p[0]['night'], true);
$p = $tariff->split(ts(2025, 3, 3, 21), ts(2025, 3, 3, 22));
check('Nacht: 21:00 gehört zum Tag', $p[0]['night'], false);

$half = new ErTariff([segment(['nightFrom' => 22 * 60 + 30, 'nightTo' => 6 * 60 + 30])]);
$p = $half->split(ts(2025, 3, 3, 22), ts(2025, 3, 3, 23));
check('Nacht 22:30: Stunde wird geteilt', [count($p), $p[0]['night'], $p[1]['night']], [2, false, true]);
check('Nacht 22:30: Raster', $half->nightGridMinutes(), 30 % 15 === 0 ? 15 : 1);
check('Aggregation volle Stunde', ErArchiveReader::aggregationFor($tariff), ErArchiveReader::HOURLY);
check('Aggregation Viertelstunde', ErArchiveReader::aggregationFor($half), ErArchiveReader::QUARTER_HOUR);
check('Aggregation ohne Nacht = täglich', ErArchiveReader::aggregationFor(new ErTariff([segment()])), ErArchiveReader::DAILY);

// ---------------------------------------------------------------- Ein Tag mit Tag/Nacht
$tariff = new ErTariff([segment($night + ['baseYear' => 120.0])]);
$day = ['start' => ts(2025, 3, 3), 'end' => ts(2025, 3, 4)];
$r = ErCalculator::calculate(hourly($day['start'], $day['end'], $pattern), $tariff, 1.0, false, $day['start'], $day['end'], ts(2025, 3, 3, 23, 59));
check('Tag: Verbrauch', $r['consumption'], 9.6);
check('Tag: Verbrauch Tag', $r['consumptionDay'], 8.0);
check('Tag: Verbrauch Nacht', $r['consumptionNight'], 1.6);
check('Tag: Arbeitskosten', $r['costsWork'], 16 * 0.5 * 0.30 + 8 * 0.2 * 0.20);
check('Tag: Grundpreis genau 1 Tag', $r['costsBase'], 120 / 365);
check('Tag: Gesamtkosten', $r['costs'], 2.72 + 120 / 365);

$r = ErCalculator::calculate(hourly($day['start'], $day['end'], $pattern), $tariff, 1.0, false, $day['start'], $day['end'], ts(2025, 3, 3, 23, 59), false);
check('Grundpreis abgeschaltet', $r['costs'], 2.72);

// ---------------------------------------------------------------- Einheiten
$r = ErCalculator::calculate(hourly($day['start'], $day['end'], static fn () => 100.0), new ErTariff([segment()]), 0.001, false, $day['start'], $day['end'], $day['end']);
check('Wh -> kWh Verbrauch', $r['consumption'], 2.4);
check('Wh -> kWh Kosten', $r['costs'], 2.4 * 0.30);
$r = ErCalculator::calculate(hourly($day['start'], $day['end'], static fn () => 500.0), new ErTariff([segment()]), 1 / 1000, false, $day['start'], $day['end'], $day['end']);
check('Impulse: Verbrauch', $r['consumption'], 12.0);

// ---------------------------------------------------------------- Gas
$gasTariff = new ErTariff([segment(['priceDay' => 0.07, 'gasFactor' => 1.0, 'gasZ' => 0.95, 'gasCalorific' => 11.5])]);
$r = ErCalculator::calculate([['start' => $day['start'], 'end' => ts(2025, 3, 4), 'value' => 10.0]], $gasTariff, 1.0, true, $day['start'], $day['end'], $day['end']);
check('Gas: Verbrauch bleibt in m³', $r['consumption'], 10.0);
check('Gas: Energie in kWh', $r['energy'], 10 * 0.95 * 11.5);
check('Gas: Kosten aus kWh', $r['costs'], 10 * 0.95 * 11.5 * 0.07);
$r = ErCalculator::calculate([['start' => $day['start'], 'end' => ts(2025, 3, 4), 'value' => 10.0]], new ErTariff([segment()]), 1.0, true, $day['start'], $day['end'], $day['end']);
check('Gas: fehlende Parameter melden', $r['warnings'], ['gasParameters']);

// ---------------------------------------------------------------- Tarifwechsel im Zeitraum
$change = new ErTariff([
    segment(['id' => 'a', 'validFrom' => ts(2025, 1, 1), 'priceDay' => 0.30, 'baseYear' => 365.0]),
    segment(['id' => 'b', 'validFrom' => ts(2025, 2, 1), 'priceDay' => 0.40, 'baseYear' => 730.0])
]);
$s = ts(2025, 1, 15);
$e = ts(2025, 2, 16);
$r = ErCalculator::calculate(daily($s, $e, 10.0), $change, 1.0, false, $s, $e, ts(2025, 3, 1));
check('Tarifwechsel: Arbeitskosten', $r['costsWork'], 17 * 10 * 0.30 + 15 * 10 * 0.40);
check('Tarifwechsel: Grundpreis je Abschnitt', $r['costsBase'], 17 * 1.0 + 15 * 2.0);
check('Tarifwechsel: Verbrauch', $r['consumption'], 320.0);

// ---------------------------------------------------------------- Grundpreis: Länge der Zeiträume
$flat = new ErTariff([segment(['baseYear' => 365.0, 'validFrom' => ts(2024, 1, 1)])]);
$far = ts(2030, 1, 1);
check('Grundpreis Woche = 7 Tage', ErCalculator::accrue($flat, ts(2025, 3, 3), ts(2025, 3, 10), $far)['base'], 7.0);
check('Grundpreis Januar = 31 Tage', ErCalculator::accrue($flat, ts(2025, 1, 1), ts(2025, 2, 1), $far)['base'], 31.0);
check('Grundpreis Jahr 2025 = Jahrespreis', ErCalculator::accrue($flat, ts(2025, 1, 1), ts(2026, 1, 1), $far)['base'], 365.0);
check('Grundpreis Jahr 2024 (Schaltjahr) = Jahrespreis', ErCalculator::accrue($flat, ts(2024, 1, 1), ts(2025, 1, 1), $far)['base'], 365.0);
check('Grundpreis Februar 2024 = 29/366', ErCalculator::accrue($flat, ts(2024, 2, 1), ts(2024, 3, 1), $far)['base'], 29 * 365 / 366);
check('Grundpreis laufender Monat zählt bis heute', ErCalculator::accrue($flat, ts(2025, 3, 1), ts(2025, 4, 1), ts(2025, 3, 10, 12))['base'], 10.0);
check('Tage ohne Tarif werden gemeldet', ErCalculator::accrue($flat, ts(2023, 12, 30), ts(2024, 1, 2), $far)['noTariffDays'], 2);

// ---------------------------------------------------------------- Abschlag / Saldo
$adv = new ErTariff([segment(['advance' => 100.0, 'advanceCount' => 12, 'validFrom' => ts(2025, 1, 1)])]);
$a = ErCalculator::accrue($adv, ts(2025, 1, 1), ts(2026, 1, 1), $far);
check('Abschlag Jahr = Abschlag x Anzahl', $a['advance'], 1200.0);

$balanceTariff = new ErTariff([segment(['advance' => 100.0, 'advanceCount' => 12, 'validFrom' => ts(2024, 1, 1)])]);
$y = ErCalculator::calculate(daily(ts(2025, 1, 1), ts(2026, 1, 1), 10.0), $balanceTariff, 1.0, false, ts(2025, 1, 1), ts(2026, 1, 1), $far);
check('Saldo Jahr: Abschlag - Kosten', $y['balance'], 1200.0 - 365 * 10 * 0.30);
check('Saldo Jahr: positiv = Guthaben', $y['balance'] > 0, true);
$half = ErCalculator::calculate(daily(ts(2025, 1, 1), ts(2025, 7, 1), 10.0), $balanceTariff, 1.0, false, ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 6, 30, 12));
check('Saldo laufendes Jahr: Abschlag nur bis heute', $half['advance'], 1200.0 * 181 / 365);

// ---------------------------------------------------------------- Prognose
$fc = new ErTariff([segment(['baseYear' => 365.0])]);
$ms = ts(2025, 1, 1);
$me = ts(2025, 2, 1);
$r = ErCalculator::calculate(daily($ms, ts(2025, 1, 16), 10.0), $fc, 1.0, false, $ms, $me, ts(2025, 1, 16));
check('Prognose: Verbrauch linear', $r['forecastConsumption'], 150.0 / (15 / 31));
check('Prognose: Kosten = Arbeit hochgerechnet + voller Grundpreis', $r['forecastCosts'], 45.0 / (15 / 31) + 31.0);
$r = ErCalculator::calculate(daily($ms, $me, 10.0), $fc, 1.0, false, $ms, $me, ts(2025, 3, 1));
check('Prognose: nicht für abgeschlossene Zeiträume', $r['forecastCosts'], null);

// ---------------------------------------------------------------- Summe
$sum = ErCalculator::sum([['costs' => 1.5, 'consumption' => 2.0, 'warnings' => ['noTariff']], ['costs' => 2.5, 'consumption' => 3.0, 'warnings' => ['noTariff']]]);
check('Summe Kosten', $sum['costs'], 4.0);
check('Summe Warnungen eindeutig', $sum['warnings'], ['noTariff']);

// ---------------------------------------------------------------- Zeiträume
$now = ts(2025, 3, 3, 12);
check('Woche aktuell (Montag)', ErPeriods::fixed('Week_Current', $now), ['start' => ts(2025, 3, 3), 'end' => ts(2025, 3, 10)]);
check('Woche zuletzt', ErPeriods::fixed('Week_Previous', $now), ['start' => ts(2025, 2, 24), 'end' => ts(2025, 3, 3)]);
check('Monat zuletzt', ErPeriods::fixed('Month_Previous', $now), ['start' => ts(2025, 2, 1), 'end' => ts(2025, 3, 1)]);
$now = ts(2025, 1, 15, 8);
check('Vormonat über Jahreswechsel', ErPeriods::fixed('Month_Previous', $now), ['start' => ts(2024, 12, 1), 'end' => ts(2025, 1, 1)]);
check('Vorjahr', ErPeriods::fixed('Year_Previous', $now), ['start' => ts(2024, 1, 1), 'end' => ts(2025, 1, 1)]);
check('Vortag', ErPeriods::fixed('Day_Previous', $now), ['start' => ts(2025, 1, 14), 'end' => ts(2025, 1, 15)]);
$sunday = ts(2025, 3, 9, 23);
check('Woche aktuell (Sonntag)', ErPeriods::fixed('Week_Current', $sunday), ['start' => ts(2025, 3, 3), 'end' => ts(2025, 3, 10)]);
check('Eigener Zeitraum: Ende inklusive', ErPeriods::custom(['From' => '{"year":2025,"month":1,"day":10}', 'To' => '{"year":2025,"month":1,"day":10}']), ['start' => ts(2025, 1, 10), 'end' => ts(2025, 1, 11)]);
check('Eigener Zeitraum: Ende vor Beginn', ErPeriods::custom(['From' => '{"year":2025,"month":2,"day":1}', 'To' => '{"year":2025,"month":1,"day":1}']), null);
check('Ident-Schema', ErPeriods::ident('Month_Current', 'Costs'), 'Month_Current_Costs');

// ---------------------------------------------------------------- Archiv-Leser
$chunks = ErArchiveReader::chunks(ErArchiveReader::HOURLY, ts(2025, 1, 1), ts(2025, 12, 31, 23));
check('Chunks stündlich: je Monat', count($chunks), 12);
$maxRows = 0;
foreach ($chunks as [$from, $to]) {
    $maxRows = max($maxRows, intdiv($to - $from, 3600) + 1);
}
check('Chunks bleiben unter dem Limit von 10000', $maxRows < 10000, true);
check('Chunks decken den Bereich lückenlos ab', [$chunks[0][0], $chunks[11][1]], [ts(2025, 1, 1), ts(2025, 12, 31, 23)]);
$daily = ErArchiveReader::chunks(ErArchiveReader::DAILY, ts(2024, 6, 1), ts(2025, 6, 1));
check('Chunks täglich: je Jahr', count($daily), 2);

$calls = 0;
$fetch = static function (int $agg, int $from, int $to) use (&$calls): array
{
    $calls++;
    $rows = [];
    for ($t = $from; $t <= $to; $t += 3600) {
        $rows[] = ['TimeStamp' => $t, 'Avg' => 1.0];
    }
    return array_reverse($rows); // das Archiv liefert absteigend
};
$iv = ErArchiveReader::read($fetch, ErArchiveReader::HOURLY, ts(2025, 1, 1), ts(2025, 2, 28, 23));
check('Leser: Anzahl Intervalle', count($iv), 59 * 24);
check('Leser: aufsteigend sortiert', $iv[0]['start'] < $iv[1]['start'], true);
check('Leser: Intervallende = Start + 1 h', $iv[0]['end'] - $iv[0]['start'], 3600);
check('Leser: eine Abfrage je Monat', $calls, 2);

check('Leser: leerer Bereich', ErArchiveReader::read($fetch, ErArchiveReader::HOURLY, 100, 50), []);

// ---------------------------------------------------------------- Formularzeilen
[$t, $warn] = ErTariff::fromFormRows([
    ['Id' => 'x1', 'ValidFrom' => '{"year":2025,"month":1,"day":1}', 'PriceDay' => 0.3, 'PriceNight' => 0.0, 'NightFrom' => '{"hour":22,"minute":0,"second":0}', 'NightTo' => '{"hour":6,"minute":0,"second":0}', 'BasePrice' => 100.0],
    ['Id' => 'x2', 'ValidFrom' => '{"year":2025,"month":1,"day":1}', 'PriceDay' => 0.4],
    ['Id' => 'x3', 'ValidFrom' => '']
]);
check('Formular: doppelte und fehlende Datumsangaben werden gemeldet', count($warn), 2);
check('Formular: Nachtpreis 0 = Tagpreis', $t->segments()[0]->priceNight, 0.3);
check('Formular: Nachtfenster in Minuten', [$t->segments()[0]->nightFrom, $t->segments()[0]->nightTo], [1320, 360]);
$round = ErTariff::fromArray($t->toArray());
check('Tarif: Transportformat verlustfrei', $round->toArray(), $t->toArray());

// ---------------------------------------------------------------- Migration
$settings = ErLegacy::convertSettings([
    'Active'                => true,
    'consumptionVariableID' => 4711,
    'ProfileType'           => '~Electricity.Wh',
    'Daily'                 => true,
    'LastMonth'             => true,
    'AddBasePrice'          => true,
    'DailyConsumption'      => true,
    'UpdateInterval'        => 600,
    'Impulse_kWhBool'       => false,
    'IndividualPeriods'     => true,
    'IndividualPeriodsList' => '[{"startDate":"{\"day\":1,\"month\":2,\"year\":2025}","endDate":"{\"day\":15,\"month\":2,\"year\":2025}"}]'
]);
check('Migration: Einheit', $settings['Unit'], 'Wh');
check('Migration: Zeiträume', [$settings['DayCurrent'], $settings['MonthPrevious'], $settings['WeekCurrent']], [true, true, false]);
check('Migration: Intervall in Minuten', $settings['UpdateInterval'], 10);
check('Migration: Tag/Nacht', $settings['ShowDayNight'], true);
check('Migration: Grundpreis', $settings['IncludeBaseCosts'], true);
$custom = json_decode($settings['CustomPeriods'], true);
check('Migration: eigener Zeitraum', [count($custom), json_decode($custom[0]['To'], true)['day']], [1, 15]);
$imp = ErLegacy::convertSettings(['ProfileType' => '~Electricity', 'Impulse_kWhBool' => true, 'Impulse_kWh' => 800]);
check('Migration: Impulszähler', [$imp['Unit'], $imp['ImpulsesPerKwh']], ['Impulse', 800]);

$old = [['StartDate' => '{"year":2024,"month":1,"day":1}', 'DayPrice' => 0.3, 'NightPrice' => 0.2, 'NightTimeStart' => '{"hour":20,"minute":0,"second":0}', 'NightTimeEnd' => '{"hour":6,"minute":0,"second":0}', 'BasePrice' => 120.0, 'AdvancePayment' => 80.0, 'DeductionsPerYear' => 12]];
$with = ErLegacy::convertTariffRows($old, true);
$without = ErLegacy::convertTariffRows($old, false);
[$tw] = ErTariff::fromFormRows($with);
[$tn] = ErTariff::fromFormRows($without);
check('Migration Tarif: Nachtfenster übernommen', $tw->segments()[0]->hasNight(), true);
check('Migration Tarif: Nachtpreis ungenutzt -> kein Fenster', $tn->segments()[0]->hasNight(), false);
$withSupplier = ErLegacy::convertTariffRows([$old[0] + ['ElectricitySuppliers' => 'Stadtwerke Beispiel']], true);
check('Migration Tarif: Stromanbieter wird zu Anbieter', $withSupplier[0]['Supplier'], 'Stadtwerke Beispiel');
[$ts] = ErTariff::fromFormRows($withSupplier);
check('Anbieter bleibt im Tarif erhalten', $ts->segments()[0]->supplier, 'Stadtwerke Beispiel');
check('Anbieter überlebt das Transportformat', ErTariff::fromArray($ts->toArray())->segments()[0]->supplier, 'Stadtwerke Beispiel');
check('Migration Tarif: Preise', [$tw->segments()[0]->priceDay, $tw->segments()[0]->priceNight, $tw->segments()[0]->advanceCount], [0.3, 0.2, 12]);

// ---------------------------------------------------------------- Integration (nur auf einem Symcon-System)
if (function_exists('AC_GetAggregatedValues') && function_exists('IPS_GetObjectIDByIdent')) {
    $cat = 41286;
    $ac = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
    $var = static fn (string $ident): int => IPS_GetObjectIDByIdent($ident, $cat);
    $read = static function (string $ident, int $agg, int $from, int $to) use ($ac, $var): array
    {
        $id = $var($ident);
        return ErArchiveReader::read(static fn (int $a, int $f, int $t): array => AC_GetAggregatedValues($ac, $id, $a, $f, $t, 0), $agg, $from, $to);
    };
    $start = ts(2025, 1, 1);
    $end = ts(2026, 1, 1);
    $far = ts(2030, 1, 1);

    $gas = new ErTariff([segment(['validFrom' => ts(2024, 1, 1), 'priceDay' => 0.10])]);
    foreach ([ErArchiveReader::DAILY, ErArchiveReader::HOURLY] as $agg) {
        $r = ErCalculator::calculate($read('T02_GasM3', $agg, $start, $end - 1), $gas, 1.0, false, $start, $end, $far);
        check("Archiv Gas 2025 (Aggregation $agg): 365 x 7,2 m³", $r['consumption'], 365 * 7.2, 1e-6);
    }

    $strom = new ErTariff([segment($night + ['validFrom' => ts(2024, 1, 1), 'baseYear' => 120.0])]);
    $agg = ErArchiveReader::aggregationFor($strom);
    $dayStart = ts(2025, 3, 3);
    $r = ErCalculator::calculate($read('T01_StromEinfach', $agg, $dayStart, ts(2025, 3, 4) - 1), $strom, 1.0, false, $dayStart, ts(2025, 3, 4), ts(2025, 3, 4));
    check('Archiv Strom: Tagesverbrauch', $r['consumption'], 9.6);
    check('Archiv Strom: Kosten Tag/Nacht', $r['costsWork'], 16 * 0.5 * 0.30 + 8 * 0.2 * 0.20);

    $imp = ErCalculator::calculate($read('T03_Impulse', $agg, $dayStart, ts(2025, 3, 4) - 1), $strom, 1 / 1000, false, $dayStart, ts(2025, 3, 4), ts(2025, 3, 4));
    check('Archiv Impulszähler: 9600 Impulse = 9,6 kWh', $imp['consumption'], 9.6);

    $reset = ErCalculator::calculate($read('T04_Zaehlerreset', $agg, ts(2026, 3, 15), ts(2026, 3, 16) - 1), $strom, 1.0, false, ts(2026, 3, 15), ts(2026, 3, 16), $far);
    check('Archiv Zählerreset: nur die Reset-Stunde fehlt', $reset['consumption'], 9.1);

    // Das Archiv füllt Lücken mit Nullwerten auf; der Verbrauch landet gesammelt in der ersten Stunde danach.
    $gapIv = $read('T05_Luecke', ErArchiveReader::HOURLY, ts(2026, 2, 1), ts(2026, 3, 1) - 1);
    check('Archiv Lücke: Archiv liefert lückenlose Stunden-Buckets', count($gapIv), 28 * 24);
    $gapSum = ErCalculator::calculate($gapIv, $strom, 1.0, false, ts(2026, 2, 1), ts(2026, 3, 1), $far);
    check('Archiv Lücke: Monatssumme bleibt vollständig', $gapSum['consumption'], 28 * 9.6, 1e-6);
    $gapDay = ErCalculator::calculate($gapIv, $strom, 1.0, false, ts(2026, 2, 10), ts(2026, 2, 13), $far);
    check('Archiv Lücke: Lückentage selbst haben keinen Verbrauch', $gapDay['consumption'], 0.0);
    $afterGap = ErCalculator::calculate($gapIv, $strom, 1.0, false, ts(2026, 2, 13), ts(2026, 2, 14), $far);
    check('Archiv Lücke: Verbrauch der Lücke steht am 13.02.', $afterGap['consumption'], 38.4, 1e-6);
}

echo "\nBestanden: " . $GLOBALS['er_pass'] . ', fehlgeschlagen: ' . $GLOBALS['er_fail'] . "\n";
if (PHP_SAPI === 'cli') {
    exit($GLOBALS['er_fail'] > 0 ? 1 : 0);
}
