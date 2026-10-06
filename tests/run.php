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
require_once __DIR__ . '/../libs/ErExtras.php';
require_once __DIR__ . '/../libs/ErPriceLookup.php';
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
        'priceHt'      => 0.30,
        'priceNt'      => 0.20,
        'ntWindows'    => [],
        'ntWeekend'    => false,
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

$night = ['ntWindows' => [[22 * 60, 6 * 60]]];
$pattern = static fn (int $t): float => ((int) date('G', $t) >= 22 || (int) date('G', $t) < 6) ? 0.2 : 0.5;

// ---------------------------------------------------------------- Nachtfenster
$tariff = new ErTariff([segment($night)]);
$p = $tariff->split(ts(2025, 3, 3, 22), ts(2025, 3, 3, 23));
check('NT: 22:00 gehört zum Niedertarif', [count($p), $p[0]['nt']], [1, true]);
$p = $tariff->split(ts(2025, 3, 3, 6), ts(2025, 3, 3, 7));
check('NT: 06:00 gehört zum Hochtarif', [count($p), $p[0]['nt']], [1, false]);
$p = $tariff->split(ts(2025, 3, 3, 5), ts(2025, 3, 3, 6));
check('NT: 05:00 gehört zum Niedertarif', $p[0]['nt'], true);
$p = $tariff->split(ts(2025, 3, 3, 21), ts(2025, 3, 3, 22));
check('NT: 21:00 gehört zum Hochtarif', $p[0]['nt'], false);

$half = new ErTariff([segment(['ntWindows' => [[22 * 60 + 30, 6 * 60 + 30]]])]);
$p = $half->split(ts(2025, 3, 3, 22), ts(2025, 3, 3, 23));
check('NT 22:30: Stunde wird geteilt', [count($p), $p[0]['nt'], $p[1]['nt']], [2, false, true]);
check('NT 22:30: Raster', $half->ntGridMinutes(), 30 % 15 === 0 ? 15 : 1);
check('Aggregation volle Stunde', ErArchiveReader::aggregationFor($tariff), ErArchiveReader::HOURLY);
check('Aggregation Viertelstunde', ErArchiveReader::aggregationFor($half), ErArchiveReader::QUARTER_HOUR);
check('Aggregation ohne NT = täglich', ErArchiveReader::aggregationFor(new ErTariff([segment()])), ErArchiveReader::DAILY);

// ---------------------------------------------------------------- Mehrere NT-Fenster und Wochenende
$twoWindows = new ErTariff([segment(['ntWindows' => [[22 * 60, 6 * 60], [13 * 60, 15 * 60]]])]);
$isNt = static fn (ErTariff $t, int $ts): bool => $t->segmentAt($ts)->isNt($ts);
check('NT 2 Fenster: 13:00 (Mittagsfenster) ist NT', $isNt($twoWindows, ts(2025, 3, 5, 13)), true);
check('NT 2 Fenster: 14:59 ist NT', $isNt($twoWindows, ts(2025, 3, 5, 14, 59)), true);
check('NT 2 Fenster: 15:00 ist HT', $isNt($twoWindows, ts(2025, 3, 5, 15)), false);
check('NT 2 Fenster: 12:59 ist HT', $isNt($twoWindows, ts(2025, 3, 5, 12, 59)), false);
check('NT 2 Fenster: 23:00 ist NT (Nachtfenster)', $isNt($twoWindows, ts(2025, 3, 5, 23)), true);
check('NT 2 Fenster: 10:00 ist HT', $isNt($twoWindows, ts(2025, 3, 5, 10)), false);
$mid = $twoWindows->split(ts(2025, 3, 5, 12), ts(2025, 3, 5, 14));
check('NT 2 Fenster: Intervall 12-14 Uhr wird an 13:00 geteilt', [count($mid), $mid[0]['nt'], $mid[1]['nt']], [2, false, true]);

$weekend = new ErTariff([segment(['ntWeekend' => true])]);
check('Wochenende: Samstag 12:00 ist NT', $isNt($weekend, ts(2025, 3, 8, 12)), true);
check('Wochenende: Sonntag 23:00 ist NT', $isNt($weekend, ts(2025, 3, 9, 23)), true);
check('Wochenende: Freitag 12:00 ist HT', $isNt($weekend, ts(2025, 3, 7, 12)), false);
check('Wochenende: Montag 00:00 ist HT', $isNt($weekend, ts(2025, 3, 10, 0)), false);
check('Wochenende allein zählt als Niedertarif', [$weekend->hasNt(), $weekend->segments()[0]->hasNt()], [true, true]);
check('Wochenende allein: Aggregation stündlich', ErArchiveReader::aggregationFor($weekend), ErArchiveReader::HOURLY);
$overMidnight = $weekend->split(ts(2025, 3, 7, 23), ts(2025, 3, 8, 1));
check('Wochenende: Freitag 23 Uhr bis Samstag 1 Uhr wird um Mitternacht geteilt', [count($overMidnight), $overMidnight[0]['nt'], $overMidnight[1]['nt']], [2, false, true]);

$weekendNight = new ErTariff([segment(['ntWeekend' => true, 'ntWindows' => [[22 * 60, 6 * 60]]])]);
check('Wochenende + Nacht: Sonntag 12:00 ist NT', $isNt($weekendNight, ts(2025, 3, 9, 12)), true);
check('Wochenende + Nacht: Montag 03:00 ist NT (Nachtfenster)', $isNt($weekendNight, ts(2025, 3, 10, 3)), true);
check('Wochenende + Nacht: Montag 12:00 ist HT', $isNt($weekendNight, ts(2025, 3, 10, 12)), false);

$saturday = hourly(ts(2025, 3, 8), ts(2025, 3, 9), static fn (): float => 1.0);
$r = ErCalculator::calculate($saturday, new ErTariff([segment(['ntWeekend' => true, 'priceHt' => 0.30, 'priceNt' => 0.20])]), 1.0, false, ts(2025, 3, 8), ts(2025, 3, 9), ts(2025, 3, 9));
check('Wochenende: ganzer Samstag ist NT-Verbrauch', [$r['consumptionNt'], $r['consumptionHt']], [24.0, 0.0]);
check('Wochenende: ganzer Samstag zum NT-Preis', $r['costsWork'], 24 * 0.20);
$wednesday = hourly(ts(2025, 3, 5), ts(2025, 3, 6), static fn (): float => 1.0);
$r = ErCalculator::calculate($wednesday, new ErTariff([segment(['ntWindows' => [[22 * 60, 6 * 60], [13 * 60, 15 * 60]], 'priceHt' => 0.30, 'priceNt' => 0.20])]), 1.0, false, ts(2025, 3, 5), ts(2025, 3, 6), ts(2025, 3, 6));
check('2 Fenster: 10 NT-Stunden (22-6 Uhr plus 13-15 Uhr) und 14 HT-Stunden', [$r['consumptionNt'], $r['consumptionHt']], [10.0, 14.0]);
check('2 Fenster: Kosten', $r['costsWork'], 10 * 0.20 + 14 * 0.30);

check('NT-Raster: zweites Fenster mit 13:30 -> Viertelstunden', (new ErTariff([segment(['ntWindows' => [[22 * 60, 6 * 60], [13 * 60 + 30, 15 * 60]]])]))->ntGridMinutes(), 15);
check('Fenster mit gleicher Zeit wird ignoriert', segment(['ntWindows' => [[0, 0], [600, 600]]])->hasNt(), false);
[$twoFromForm] = ErTariff::fromFormRows([[
    'Id'        => 'w1',
    'ValidFrom' => '{"year":2025,"month":1,"day":1}',
    'PriceHT'   => 0.3,
    'NtFrom1'   => '{"hour":22,"minute":0,"second":0}',
    'NtTo1'     => '{"hour":6,"minute":0,"second":0}',
    'NtFrom2'   => '{"hour":13,"minute":0,"second":0}',
    'NtTo2'     => '{"hour":15,"minute":0,"second":0}',
    'NtWeekend' => true
]]);
check('Formular: zwei NT-Fenster und Wochenende', [$twoFromForm->segments()[0]->ntWindows, $twoFromForm->segments()[0]->ntWeekend], [[[1320, 360], [780, 900]], true]);
check('Formular: zweites Fenster leer wird ignoriert', ErTariff::fromFormRows([['ValidFrom' => '{"year":2025,"month":1,"day":1}', 'NtFrom1' => '{"hour":22,"minute":0,"second":0}', 'NtTo1' => '{"hour":6,"minute":0,"second":0}']])[0]->segments()[0]->ntWindows, [[1320, 360]]);
check('Transportformat enthält Fenster und Wochenende', ErTariff::fromArray($twoFromForm->toArray())->toArray(), $twoFromForm->toArray());

// ---------------------------------------------------------------- Ein Tag mit Tag/Nacht
$tariff = new ErTariff([segment($night + ['baseYear' => 120.0])]);
$day = ['start' => ts(2025, 3, 3), 'end' => ts(2025, 3, 4)];
$r = ErCalculator::calculate(hourly($day['start'], $day['end'], $pattern), $tariff, 1.0, false, $day['start'], $day['end'], ts(2025, 3, 3, 23, 59));
check('Tag: Verbrauch', $r['consumption'], 9.6);
check('Tag: Verbrauch HT', $r['consumptionHt'], 8.0);
check('Tag: Verbrauch NT', $r['consumptionNt'], 1.6);
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
$gasTariff = new ErTariff([segment(['priceHt' => 0.07, 'gasFactor' => 1.0, 'gasZ' => 0.95, 'gasCalorific' => 11.5])]);
$r = ErCalculator::calculate([['start' => $day['start'], 'end' => ts(2025, 3, 4), 'value' => 10.0]], $gasTariff, 1.0, true, $day['start'], $day['end'], $day['end']);
check('Gas: Verbrauch bleibt in m³', $r['consumption'], 10.0);
check('Gas: Energie in kWh', $r['energy'], 10 * 0.95 * 11.5);
check('Gas: Kosten aus kWh', $r['costs'], 10 * 0.95 * 11.5 * 0.07);
$r = ErCalculator::calculate([['start' => $day['start'], 'end' => ts(2025, 3, 4), 'value' => 10.0]], new ErTariff([segment()]), 1.0, true, $day['start'], $day['end'], $day['end']);
check('Gas: fehlende Parameter melden', $r['warnings'], ['gasParameters']);

// ---------------------------------------------------------------- Tarifwechsel im Zeitraum
$change = new ErTariff([
    segment(['id' => 'a', 'validFrom' => ts(2025, 1, 1), 'priceHt' => 0.30, 'baseYear' => 365.0]),
    segment(['id' => 'b', 'validFrom' => ts(2025, 2, 1), 'priceHt' => 0.40, 'baseYear' => 730.0])
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

// ---------------------------------------------------------------- Prognose mit Vorjahresverlauf
require_once __DIR__ . '/../libs/ErForecast.php';

check('Vorjahr: gleicher Zeitpunkt', ErForecast::lastYear(ts(2025, 3, 3, 12, 30)), ts(2024, 3, 3, 12, 30));
check('Vorjahr: 29.02. wird 01.03.', ErForecast::lastYear(ts(2028, 2, 29)), ts(2027, 3, 1));

// Vorjahr 2024 (Schaltjahr): Januar bis März je 3,0 pro Tag (Winter), danach 1,0 pro Tag
$winterHeavy = static function (): array
{
    $out = [];
    for ($t = ts(2024, 1, 1); $t < ts(2025, 1, 1); $t = ErTariff::nextDay($t)) {
        $out[] = ['start' => $t, 'end' => ErTariff::nextDay($t), 'value' => $t < ts(2024, 4, 1) ? 3.0 : 1.0];
    }
    return $out;
};
$lastYearDaily = $winterHeavy();
$share = ErForecast::seasonalShare($lastYearDaily, ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 4, 1));
check('Vorjahresanteil: Winterverbrauch zählt stärker als der Zeitanteil', $share, 273.0 / 548.0);
check('Vorjahresanteil liegt über dem zeitlichen Anteil', $share > 91 / 366, true);

$r = ErCalculator::calculate(daily(ts(2025, 1, 1), ts(2025, 4, 1), 3.0), new ErTariff([segment()]), 1.0, false, ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 4, 1), true, [], $share);
check('Prognose Vorjahr: Verbrauch = bisheriger Verbrauch / Vorjahresanteil', $r['forecastConsumption'], 270.0 / (273.0 / 548.0));
check('Prognose Vorjahr: Methode', $r['forecastMethod'], 'seasonal');
$linear = ErCalculator::calculate(daily(ts(2025, 1, 1), ts(2025, 4, 1), 3.0), new ErTariff([segment()]), 1.0, false, ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 4, 1));
check('Prognose linear: Methode', $linear['forecastMethod'], 'linear');
check('Prognose Vorjahr ist deutlich niedriger als linear (kein Winter auf das ganze Jahr)', $r['forecastConsumption'] < $linear['forecastConsumption'] * 0.6, true);
$r = ErCalculator::calculate(daily(ts(2025, 1, 1), ts(2025, 4, 1), 3.0), new ErTariff([segment()]), 1.0, false, ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 4, 1), true, [], null);
check('Prognose ohne Vorjahresanteil bleibt linear', $r['forecastMethod'], 'linear');

check('Vorjahresvergleich: Quartal bis zum Stichtag', ErForecast::lastYearConsumption($lastYearDaily, ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 4, 1)), 273.0);
check('Vorjahresvergleich: abgeschlossener Monat komplett', ErForecast::lastYearConsumption($lastYearDaily, ts(2025, 2, 1), ts(2025, 3, 1), ts(2025, 5, 1)), 29.0 * 3.0);
check('Vorjahresvergleich: keine Daten', ErForecast::lastYearConsumption([], ts(2025, 2, 1), ts(2025, 3, 1), ts(2025, 5, 1)), null);
check('Vorjahresanteil: keine Vorjahresdaten', ErForecast::seasonalShare([], ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 4, 1)), null);
$zeros = array_map(static fn (array $iv): array => ['value' => 0.0] + $iv, $lastYearDaily);
check('Vorjahresanteil: Vorjahr ohne Verbrauch', ErForecast::seasonalShare($zeros, ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 4, 1)), null);
// Vorjahresdaten beginnen erst im November: bis April fast nichts, der Anteil wäre unglaubwürdig klein
$lateStart = array_map(static fn (array $iv): array => ['value' => $iv['start'] < ts(2024, 11, 1) ? 0.0 : 1.0] + $iv, $lastYearDaily);
check('Vorjahresanteil: lückenhafte Vorjahresdaten -> unglaubwürdig', ErForecast::seasonalShare($lateStart, ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 4, 1)), null);
check('Vorjahresanteil: Monat im Vorjahr', ErForecast::seasonalShare($lastYearDaily, ts(2025, 3, 1), ts(2025, 4, 1), ts(2025, 3, 16)) !== null, true);
check('Vorjahresanteil: gleichmäßiges Vorjahr entspricht dem Zeitanteil', ErForecast::seasonalShare(daily(ts(2024, 1, 1), ts(2025, 1, 1), 2.0), ts(2025, 1, 1), ts(2026, 1, 1), ts(2025, 7, 1)), 182 / 366);
check('Vorjahresverlauf nur für Monat und Jahr, die Woche bleibt linear', ErPeriods::FORECAST_SEASONAL, ['Month_Current', 'Year_Current']);

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

$dailyFetch = static function (int $agg, int $from, int $to): array
{
    $rows = [];
    for ($t = $from; $t <= $to; $t = ErTariff::nextDay($t)) {
        $rows[] = ['TimeStamp' => $t, 'Avg' => 2.5];
    }
    return array_reverse($rows);
};
check('Fingerabdruck: Summe der Tageswerte', ErArchiveReader::sumDaily($dailyFetch, ts(2025, 1, 1), ts(2025, 1, 10, 23, 59)), 25.0);
check('Fingerabdruck: leerer Bereich', ErArchiveReader::sumDaily($dailyFetch, 100, 50), 0.0);
check('Fingerabdruck: gleich', ErArchiveReader::sameFingerprint(['consumption' => 100.0, 'price1' => 7.5], ['price1' => 7.5, 'consumption' => 100.0]), true);
check('Fingerabdruck: Rundungsrauschen wird toleriert', ErArchiveReader::sameFingerprint(['consumption' => 3503.8], ['consumption' => 3503.8000000001]), true);
check('Fingerabdruck: geänderter Verbrauch', ErArchiveReader::sameFingerprint(['consumption' => 3503.8], ['consumption' => 3503.9]), false);
check('Fingerabdruck: geänderter Preis', ErArchiveReader::sameFingerprint(['consumption' => 1.0, 'priceA' => 5.0], ['consumption' => 1.0, 'priceA' => 5.1]), false);
check('Fingerabdruck: andere Zusammensetzung', ErArchiveReader::sameFingerprint(['consumption' => 1.0], ['consumption' => 1.0, 'priceA' => 5.0]), false);
check('Leser: leerer Bereich', ErArchiveReader::read($fetch, ErArchiveReader::HOURLY, 100, 50), []);

// ---------------------------------------------------------------- Formularzeilen
[$t, $warn] = ErTariff::fromFormRows([
    ['Id' => 'x1', 'ValidFrom' => '{"year":2025,"month":1,"day":1}', 'PriceHT' => 0.3, 'PriceNT' => 0.0, 'NtFrom1' => '{"hour":22,"minute":0,"second":0}', 'NtTo1' => '{"hour":6,"minute":0,"second":0}', 'BasePrice' => 100.0],
    ['Id' => 'x2', 'ValidFrom' => '{"year":2025,"month":1,"day":1}', 'PriceHT' => 0.4],
    ['Id' => 'x3', 'ValidFrom' => '']
]);
check('Formular: doppelte und fehlende Datumsangaben werden gemeldet', count($warn), 2);
check('Formular: NT-Preis 0 = HT-Preis', $t->segments()[0]->priceNt, 0.3);
check('Formular: NT-Fenster in Minuten', $t->segments()[0]->ntWindows, [[1320, 360]]);
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
check('Migration: HT/NT getrennt anzeigen', $settings['ShowHtNt'], true);
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
check('Migration Tarif: NT-Fenster übernommen', $tw->segments()[0]->hasNt(), true);
check('Migration Tarif: Nachtpreis ungenutzt -> kein NT-Fenster', $tn->segments()[0]->hasNt(), false);
$withSupplier = ErLegacy::convertTariffRows([$old[0] + ['ElectricitySuppliers' => 'Stadtwerke Beispiel']], true);
check('Migration Tarif: Stromanbieter wird zu Anbieter', $withSupplier[0]['Supplier'], 'Stadtwerke Beispiel');
[$ts] = ErTariff::fromFormRows($withSupplier);
check('Anbieter bleibt im Tarif erhalten', $ts->segments()[0]->supplier, 'Stadtwerke Beispiel');
check('Anbieter überlebt das Transportformat', ErTariff::fromArray($ts->toArray())->segments()[0]->supplier, 'Stadtwerke Beispiel');
check('Migration Tarif: Preise', [$tw->segments()[0]->priceHt, $tw->segments()[0]->priceNt, $tw->segments()[0]->advanceCount], [0.3, 0.2, 12]);

// ---------------------------------------------------------------- Dynamische Preise
require_once __DIR__ . '/../libs/ErPriceSeries.php';

$hourPrices = static function (int $from, int $to, callable $fn): ErPriceSeries
{
    return new ErPriceSeries(hourly($from, $to, $fn));
};
$series = $hourPrices(ts(2025, 3, 3), ts(2025, 3, 4), static fn (int $t): float => 10.0 + (int) date('G', $t));
check('Preisreihe: Preis zum Stundenbeginn', $series->priceAt(ts(2025, 3, 3, 5)), 15.0);
check('Preisreihe: Preis mitten in der Stunde', $series->priceAt(ts(2025, 3, 3, 5, 59)), 15.0);
check('Preisreihe: Ende ist exklusiv', $series->priceAt(ts(2025, 3, 4)), null);
check('Preisreihe: vor dem ersten Wert', $series->priceAt(ts(2025, 3, 2, 23)), null);
check('Preisreihe: Grenzen im Intervall', $series->boundariesWithin(ts(2025, 3, 3, 5, 30), ts(2025, 3, 3, 8)), [ts(2025, 3, 3, 6), ts(2025, 3, 3, 7)]);
check('Preisreihe: leer', (new ErPriceSeries([]))->priceAt(ts(2025, 3, 3)), null);

$dynSegment = static fn (array $o = []): ErTariffSegment => segment($o + ['id' => 'dyn', 'priceVariableId' => 42, 'priceFactor' => 0.01, 'surcharge' => 0.05, 'priceHt' => 0.30]);
$dyn = new ErTariff([$dynSegment()]);
$dayStart = ts(2025, 3, 3);
$dayEnd = ts(2025, 3, 4);
$consumption = hourly($dayStart, $dayEnd, $pattern);

$expected = 0.0;
foreach ($consumption as $iv) {
    $expected += $iv['value'] * (0.01 * (10 + (int) date('G', $iv['start'])) + 0.05);
}
$r = ErCalculator::calculate($consumption, $dyn, 1.0, false, $dayStart, $dayEnd, $dayEnd, true, ['dyn' => $series]);
check('Dynamisch: Kosten = Summe Verbrauch x Preis der Stunde', $r['costsWork'], $expected);
check('Dynamisch: Verbrauch unverändert', $r['consumption'], 9.6);
check('Dynamisch: keine Warnung bei vollständigen Preisen', $r['warnings'], []);

$missing = $hourPrices(ts(2025, 3, 3, 6), $dayEnd, static fn (int $t): float => 10.0 + (int) date('G', $t));
$r = ErCalculator::calculate($consumption, $dyn, 1.0, false, $dayStart, $dayEnd, $dayEnd, true, ['dyn' => $missing]);
$expectedFallback = 0.0;
foreach ($consumption as $iv) {
    $h = (int) date('G', $iv['start']);
    $expectedFallback += $iv['value'] * ($h < 6 ? 0.30 : 0.01 * (10 + $h) + 0.05);
}
check('Dynamisch: fehlende Preise -> fester Preis als Ausweichwert', $r['costsWork'], $expectedFallback);
check('Dynamisch: fehlende Preise werden gemeldet', $r['warnings'], ['priceFallback']);

$r = ErCalculator::calculate($consumption, $dyn, 1.0, false, $dayStart, $dayEnd, $dayEnd);
check('Dynamisch: ganz ohne Preisreihe gilt der feste Preis', $r['costsWork'], 9.6 * 0.30);
check('Dynamisch: ganz ohne Preisreihe wird gemeldet', $r['warnings'], ['priceFallback']);

// Verbrauch je Stunde, Preis je Viertelstunde: anteilig bewerten
$quarter = new ErPriceSeries([
    ['start' => ts(2025, 3, 3, 10, 0), 'end' => ts(2025, 3, 3, 10, 15), 'value' => 10.0],
    ['start' => ts(2025, 3, 3, 10, 15), 'end' => ts(2025, 3, 3, 10, 30), 'value' => 20.0],
    ['start' => ts(2025, 3, 3, 10, 30), 'end' => ts(2025, 3, 3, 10, 45), 'value' => 30.0],
    ['start' => ts(2025, 3, 3, 10, 45), 'end' => ts(2025, 3, 3, 11, 0), 'value' => 40.0]
]);
$oneHour = [['start' => ts(2025, 3, 3, 10), 'end' => ts(2025, 3, 3, 11), 'value' => 1.0]];
$r = ErCalculator::calculate($oneHour, new ErTariff([$dynSegment(['surcharge' => 0.0])]), 1.0, false, $dayStart, $dayEnd, $dayEnd, true, ['dyn' => $quarter]);
check('Dynamisch: Stunde mit 4 Viertelstunden-Preisen = Mittel der Preise', $r['costsWork'], 0.25);

// Mischung: fester Tarif, ab 2025-03-03 dynamisch
$mixed = new ErTariff([
    segment(['id' => 'fix', 'validFrom' => ts(2025, 1, 1), 'priceHt' => 0.30]),
    $dynSegment(['validFrom' => ts(2025, 3, 3), 'surcharge' => 0.0])
]);
$twoDays = hourly(ts(2025, 3, 2), ts(2025, 3, 4), static fn (): float => 1.0);
$r = ErCalculator::calculate($twoDays, $mixed, 1.0, false, ts(2025, 3, 2), ts(2025, 3, 4), ts(2025, 3, 4), true, ['dyn' => $series]);
$expectedMixed = 24 * 0.30;
for ($h = 0; $h < 24; $h++) {
    $expectedMixed += 0.01 * (10 + $h);
}
check('Mischtarif: erst fester, dann dynamischer Preis', $r['costsWork'], $expectedMixed);

check('Aggregation: dynamischer Preis je Stunde', ErArchiveReader::aggregationFor($dyn), ErArchiveReader::HOURLY);
check('Aggregation: dynamischer Preis je Viertelstunde', ErArchiveReader::aggregationFor(new ErTariff([$dynSegment(['priceResolution' => 15])])), ErArchiveReader::QUARTER_HOUR);
check('Aggregation: fester Preis täglich', ErArchiveReader::aggregationFor(new ErTariff([segment()])), ErArchiveReader::DAILY);
check('Split: zusätzlicher Schnittpunkt', count((new ErTariff([segment()]))->split(ts(2025, 3, 3, 10), ts(2025, 3, 3, 11), [ts(2025, 3, 3, 10, 30), ts(2025, 3, 3, 9)])), 2);

[$dynFromForm] = ErTariff::fromFormRows([
    ['Id' => 'd1', 'ValidFrom' => '{"year":2025,"month":1,"day":1}', 'PriceHT' => 0.3, 'PriceVariable' => 4711, 'PriceUnit' => 'CT_KWH', 'Surcharge' => 0.07, 'PriceResolution' => 15],
    ['Id' => 'd2', 'ValidFrom' => '{"year":2025,"month":6,"day":1}', 'PriceHT' => 0.3, 'PriceVariable' => 4712, 'PriceUnit' => 'EUR_MWH']
]);
$d1 = $dynFromForm->segments()[0];
check('Formular: dynamischer Tarif', [$d1->isDynamic(), $d1->priceVariableId, $d1->priceFactor, $d1->surcharge, $d1->priceResolution], [true, 4711, 0.01, 0.07, 15]);
check('Formular: €/MWh', $dynFromForm->segments()[1]->priceFactor, 0.001);
check('Formular: Standard-Auflösung', $dynFromForm->segments()[1]->priceResolution, 60);
check('Formular: fester Tarif ohne Preisvariable', (new ErTariff([segment()]))->hasDynamic(), false);
check('Tarif: dynamische Felder im Transportformat', ErTariff::fromArray($dynFromForm->toArray())->toArray(), $dynFromForm->toArray());

// ---------------------------------------------------------------- Kachel (Datenaufbereitung)
require_once __DIR__ . '/../libs/ErTile.php';

check('Kachel: Gruppe Monat aktuell', ErTile::group('Month_Current'), ['month', 'cur']);
check('Kachel: Gruppe Jahr zuletzt', ErTile::group('Year_Previous'), ['year', 'prev']);
check('Kachel: Gruppe Tag zuletzt', ErTile::group('Day_Previous'), ['day', 'prev']);
check('Kachel: Gruppe Tarifzeitraum', ErTile::group('Tariff_ab12cd34'), ['tariff', 'cur']);
check('Kachel: Gruppe eigener Zeitraum', ErTile::group('Custom_x1'), ['custom', 'cur']);
check('Kachel: Gruppe Summe', ErTile::group('Total'), ['total', 'cur']);

$tileNow = ts(2025, 3, 16, 12);
$tileDefs = [
    ['key' => 'Month_Current', 'label' => 'Aktueller Monat', 'start' => ts(2025, 3, 1), 'end' => ts(2025, 4, 1), 'forecast' => true, 'balance' => false],
    ['key' => 'Month_Previous', 'label' => 'Vormonat', 'start' => ts(2025, 2, 1), 'end' => ts(2025, 3, 1), 'forecast' => false, 'balance' => true],
    ['key' => 'Year_Current', 'label' => 'Aktuelles Jahr', 'start' => ts(2025, 1, 1), 'end' => ts(2026, 1, 1)]
];
$tileResults = [
    'Month_Current'  => ['costs' => 12.3456789, 'consumption' => 40.0, 'forecastCosts' => 90.0, 'forecastConsumption' => 250.0, 'balance' => -3.0, 'warnings' => ['noTariff']],
    'Month_Previous' => ['costs' => 20.0, 'consumption' => 70.0, 'forecastCosts' => 25.0, 'balance' => 4.5]
];
$tile = ErTile::build($tileDefs, $tileResults, ['title' => 'Haus', 'unit' => 'kWh', 'warnings' => ['Warnung A'], 'flags' => ['htnt' => true], 'labels' => ['costs' => 'Kosten']], $tileNow);
check('Kachel: nur Zeiträume mit Ergebnis', array_column($tile['periods'], 'key'), ['Month_Current', 'Month_Previous']);
check('Kachel: laufender Zeitraum ist offen', [$tile['periods'][0]['open'], $tile['periods'][1]['open']], [true, false]);
check('Kachel: Fortschritt des laufenden Monats (auf die Sekunde, März hat 743 Stunden)', $tile['periods'][0]['progress'], ($tileNow - ts(2025, 3, 1)) / (ts(2025, 4, 1) - ts(2025, 3, 1)), 1e-4);
check('Kachel: abgeschlossener Zeitraum hat Fortschritt 1', $tile['periods'][1]['progress'], 1.0);
check('Kachel: Werte werden gerundet', $tile['periods'][0]['v']['costs'], 12.3457);
check('Kachel: Prognose nur für Zeiträume mit Prognose-Variable', [isset($tile['periods'][0]['v']['forecastCosts']), isset($tile['periods'][1]['v']['forecastCosts'])], [true, false]);
check('Kachel: Saldo nur für Zeiträume mit Saldo-Variable', [isset($tile['periods'][0]['v']['balance']), isset($tile['periods'][1]['v']['balance'])], [false, true]);
$tileNull = ErTile::build([['key' => 'Month_Current', 'label' => 'M', 'start' => ts(2025, 3, 1), 'end' => ts(2025, 4, 1), 'forecast' => true, 'balance' => true]], ['Month_Current' => ['costs' => 1.0, 'forecastCosts' => null, 'balance' => null]], ['title' => '', 'unit' => '', 'warnings' => [], 'flags' => [], 'labels' => []], $tileNow);
check('Kachel: fehlende Werte (null) fehlen in den Daten', [isset($tileNull['periods'][0]['v']['forecastCosts']), isset($tileNull['periods'][0]['v']['balance'])], [false, false]);
check('Kachel: Rolle und Gruppe', [$tile['periods'][1]['group'], $tile['periods'][1]['role']], ['month', 'prev']);
check('Kachel: Warnungen, Titel, Einheit und Flags werden durchgereicht', [$tile['warnings'], $tile['title'], $tile['unit'], $tile['flags']['htnt']], [['Warnung A'], 'Haus', 'kWh', true]);
check('Kachel: Daten sind als JSON darstellbar', is_string(json_encode($tile)) && json_decode(json_encode($tile), true)['updated'] === $tileNow, true);
$info = ErExtras::currentTariff(new ErTariff([segment(['supplier' => 'Stadtwerke', 'priceHt' => 0.324, 'priceNt' => 0.2, 'ntWeekend' => true]), segment(['id' => 'b', 'validFrom' => ts(2026, 1, 1)])]), ts(2025, 6, 1));
check('Kachel: Tarifinfo Anbieter', $info['supplier'], 'Stadtwerke');
check('Kachel: Tarifinfo Preise in ct', [$info['ht'], $info['nt']], [32.4, 20.0]);
check('Kachel: Tarifinfo letzter Gültigkeitstag', date('Y-m-d', $info['until']), '2025-12-31');
check('Kachel: Tarifinfo offenes Ende', ErExtras::currentTariff(new ErTariff([segment()]), ts(2025, 6, 1))['until'], null);
check('Kachel: Tarifinfo ohne Niedertarif', ErExtras::currentTariff(new ErTariff([segment(['priceNt' => 0.3])]), ts(2025, 6, 1))['nt'], null);
check('Kachel: Tarifinfo vor dem ersten Tarif', ErExtras::currentTariff(new ErTariff([segment()]), ts(2024, 6, 1)), null);
$peakIntervals = daily(ts(2025, 3, 1), ts(2025, 3, 6), 2.0);
$peakIntervals[2]['value'] = 5.0;
$peak = ErExtras::peakDay($peakIntervals, new ErTariff([segment()]), 1.0, false, ts(2025, 3, 1), ts(2025, 3, 6), [], false);
check('Kachel: teuerster Tag', date('Y-m-d', $peak['day']), '2025-03-03');
check('Kachel: teuerster Tag, Kosten', $peak['costs'], 1.5);
check('Kachel: teuerster Tag ohne Verbrauch', ErExtras::peakDay([], new ErTariff([segment()]), 1.0, false, ts(2025, 3, 1), ts(2025, 3, 6), [], false), null);
check('Kachel: teuerster Tag mit Grundpreis zählt den Grundpreis mit', ErExtras::peakDay(daily(ts(2025, 3, 1), ts(2025, 3, 3), 1.0), new ErTariff([segment(['baseYear' => 365.0])]), 1.0, false, ts(2025, 3, 1), ts(2025, 3, 3), [], true)['costs'], 1.3);
$fixedSource = ['title' => 'Haus', 'periods' => [['key' => 'Month_Current', 'v' => ['costs' => 1.0]], ['key' => 'Year_Current', 'v' => ['costs' => 9.0]]]];
$fixed = ErTile::fixed($fixedSource, 'Year_Current', 'compact', '');
check('Anzeige-Instanz: nur der gewählte Zeitraum', array_column($fixed['periods'], 'key'), ['Year_Current']);
check('Anzeige-Instanz: Zeitraum und Darstellung', [$fixed['fixed'], $fixed['mode']], ['Year_Current', 'compact']);
check('Anzeige-Instanz: Titel der Quelle bleibt', $fixed['title'], 'Haus');
check('Anzeige-Instanz: eigener Titel', ErTile::fixed($fixedSource, 'Month_Current', 'auto', 'Strom Monat')['title'], 'Strom Monat');
check('Anzeige-Instanz: unbekannte Darstellung wird auto', ErTile::fixed($fixedSource, 'Month_Current', 'xyz', '')['mode'], 'auto');
check('Anzeige-Instanz: nicht aktivierter Zeitraum ergibt keine Zeiträume', ErTile::fixed($fixedSource, 'Day_Current', 'auto', '')['periods'], []);
check('Anzeige-Instanz: leere Quelle', ErTile::fixed([], 'Month_Current', 'auto', '')['periods'], []);
$ex = ErExtras::apply(['costs' => 30.0, 'days' => 10, 'consumption' => 90.0], ['lastYearConsumption' => 100.0, 'peakDay' => ts(2025, 3, 3), 'peakCosts' => 4.5]);
check('Zusatzwerte: Durchschnitt je Tag', $ex['avgPerDay'], 3.0);
check('Zusatzwerte: Vorjahresabweichung in Prozent', round($ex['vsLastYear'], 6), -10.0);
check('Zusatzwerte: teuerster Tag wird übernommen', [$ex['peakDay'], $ex['peakCosts']], [ts(2025, 3, 3), 4.5]);
$none = ErExtras::apply(['costs' => 5.0, 'days' => 1, 'consumption' => 3.0], []);
check('Zusatzwerte: ohne Daten alles 0', [$none['avgPerDay'], $none['lastYearConsumption'], $none['vsLastYear'], $none['peakDay'], $none['peakCosts']], [0.0, 0.0, 0.0, 0, 0.0]);
$ending = new ErTariff([segment(), segment(['id' => 'b', 'validFrom' => ts(2026, 1, 1)])]);
check('Zusatzwerte: Tage bis Tarifende', ErExtras::currentTariff($ending, ts(2025, 12, 20) + 3600)['daysLeft'], 11);
check('Zusatzwerte: letzter Tag des Tarifs ist 0 Tage', ErExtras::currentTariff($ending, ts(2025, 12, 31) + 3600)['daysLeft'], 0);
check('Zusatzwerte: offenes Tarifende', ErExtras::currentTariff($ending, ts(2026, 3, 1))['daysLeft'], null);
check('Zusatzwerte: Tage über die Zeitumstellung', ErExtras::currentTariff($ending, ts(2025, 10, 20))['daysLeft'], 72);
$tileEx = ErTile::build([['key' => 'Month_Current', 'label' => 'M', 'start' => ts(2025, 3, 1), 'end' => ts(2025, 4, 1)]], ['Month_Current' => $ex + ['costs' => 30.0]], ['title' => '', 'unit' => '', 'warnings' => [], 'flags' => [], 'labels' => []], ts(2025, 3, 11));
check('Kachel übernimmt Zusatzwerte aus dem Ergebnis', [$tileEx['periods'][0]['v']['avgPerDay'], (int) $tileEx['periods'][0]['v']['peakDay']], [3.0, ts(2025, 3, 3)]);
$noDynamic = static fn (): ?float => null;
$twoTariffs = new ErTariff([segment(['supplier' => 'Stadtwerke', 'priceHt' => 0.30, 'priceNt' => 0.20, 'ntWindows' => [[22 * 60, 6 * 60]]]), segment(['id' => 'b', 'validFrom' => ts(2026, 1, 1), 'priceHt' => 0.35, 'priceNt' => 0.22])]);
$p = ErPriceLookup::at($twoTariffs, ts(2025, 6, 4) + 12 * 3600, $noDynamic);
check('Preis tagsüber ist HT', [$p['price'], $p['type']], [0.30, 'HT']);
check('Preis Tarifinfo', [$p['supplier'], $p['dynamic'], $p['fallback']], ['Stadtwerke', false, false]);
check('Preis Gültigkeit endet am Vortag des nächsten Tarifs', date('Y-m-d', $p['validUntil']), '2025-12-31');
$p = ErPriceLookup::at($twoTariffs, ts(2025, 6, 4) + 23 * 3600, $noDynamic);
check('Preis nachts ist NT', [$p['price'], $p['type']], [0.20, 'NT']);
check('Preis nach Mitternacht im Nachtfenster ist NT', ErPriceLookup::at($twoTariffs, ts(2025, 6, 5) + 3 * 3600, $noDynamic)['type'], 'NT');
check('Preis um 06:00 ist wieder HT (Fenster endet exklusiv)', ErPriceLookup::at($twoTariffs, ts(2025, 6, 5) + 6 * 3600, $noDynamic)['type'], 'HT');
$p = ErPriceLookup::at($twoTariffs, ts(2026, 3, 1) + 12 * 3600, $noDynamic);
check('Preis im späteren Tarif, offenes Ende', [$p['price'], $p['validUntil']], [0.35, null]);
$p = ErPriceLookup::at($twoTariffs, ts(2024, 6, 1), $noDynamic);
check('Preis vor dem ersten Tarif: 0 und kein Typ', [$p['price'], $p['type']], [0.0, '']);
check('Preis ohne Tarif', ErPriceLookup::at(new ErTariff([]), ts(2025, 6, 1), $noDynamic)['price'], 0.0);
$dyn = new ErTariff([segment(['priceHt' => 0.30, 'priceVariableId' => 123, 'priceFactor' => 0.01, 'surcharge' => 0.05])]);
$p = ErPriceLookup::at($dyn, ts(2025, 6, 4) + 12 * 3600, static fn (): ?float => 8.5);
check('Dynamischer Preis aus Variablenwert, Faktor und Aufschlag', [round($p['price'], 4), $p['type'], $p['dynamic']], [0.135, 'dynamic', true]);
$p = ErPriceLookup::at($dyn, ts(2025, 6, 4) + 12 * 3600, $noDynamic);
check('Dynamischer Tarif ohne Preis: fester Preis als Ausweichwert', [$p['price'], $p['type'], $p['fallback']], [0.30, 'HT', true]);
check('Kachel: ohne Ergebnisse keine Zeiträume', ErTile::build($tileDefs, [], ['title' => '', 'unit' => '', 'warnings' => [], 'flags' => [], 'labels' => []], $tileNow)['periods'], []);

// Zusammenbau der Kachel-HTML aus Vorlage und Daten (mit einem Ersatz für IPSModuleStrict)
require_once __DIR__ . '/../libs/ErTileTrait.php';
if (!function_exists('IPS_GetName')) {
    function IPS_GetName(int $id): string
    {
        return 'Test';
    }
}
$tileHost = new class() {
    use ErTileTrait;

    public int $InstanceID = 0;
    public string $attribute = '{}';

    public function ReadAttributeString(string $n): string
    {
        return $this->attribute;
    }

    public function Translate(string $t): string
    {
        return $t;
    }
};
$emptyHtml = $tileHost->GetVisualizationTile();
check('Kachel-HTML: Platzhalter ersetzt (ohne Daten)', strpos($emptyHtml, '__TILE_DATA__') === false, true);
check('Kachel-HTML: Vorlage enthält handleMessage', strpos($emptyHtml, 'function handleMessage') !== false, true);
$tileHost->attribute = json_encode(['title' => 'A</script>B', 'periods' => []]);
$html = $tileHost->GetVisualizationTile();
check('Kachel-HTML: "</" in den Daten wird entschärft', substr_count($html, '</script>'), substr_count((string) file_get_contents(__DIR__ . '/../Energierechner2/tile.html'), '</script>'));
check('Kachel-HTML: Daten stehen im Skript', strpos($html, 'A<\/script>B') !== false, true);
check('GetTileData liefert die gespeicherten Daten', $tileHost->GetTileData(), $tileHost->attribute);

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

    $gas = new ErTariff([segment(['validFrom' => ts(2024, 1, 1), 'priceHt' => 0.10])]);
    foreach ([ErArchiveReader::DAILY, ErArchiveReader::HOURLY] as $agg) {
        $r = ErCalculator::calculate($read('T02_GasM3', $agg, $start, $end - 1), $gas, 1.0, false, $start, $end, $far);
        check("Archiv Gas 2025 (Aggregation $agg): 365 x 7,2 m³", $r['consumption'], 365 * 7.2, 1e-6);
    }

    $strom = new ErTariff([segment($night + ['validFrom' => ts(2024, 1, 1), 'baseYear' => 120.0])]);
    $agg = ErArchiveReader::aggregationFor($strom);
    $dayStart = ts(2025, 3, 3);
    $r = ErCalculator::calculate($read('T01_StromEinfach', $agg, $dayStart, ts(2025, 3, 4) - 1), $strom, 1.0, false, $dayStart, ts(2025, 3, 4), ts(2025, 3, 4));
    check('Archiv Strom: Tagesverbrauch', $r['consumption'], 9.6);
    check('Archiv Strom: Kosten HT/NT', $r['costsWork'], 16 * 0.5 * 0.30 + 8 * 0.2 * 0.20);

    // Dynamischer Preis aus dem Archiv (T06: 10 ct + Stunde des Tages), Verbrauch aus T01
    $priceVariable = $var('T06_PreisDynamisch');
    $dynamicTariff = new ErTariff([segment(['id' => 'dyn', 'validFrom' => ts(2024, 1, 1), 'priceVariableId' => $priceVariable, 'priceFactor' => 0.01, 'surcharge' => 0.05, 'priceHt' => 0.30])]);
    $dynAgg = ErArchiveReader::aggregationFor($dynamicTariff);
    check('Archiv dynamisch: Aggregation stündlich', $dynAgg, ErArchiveReader::HOURLY);
    $priceSeries = new ErPriceSeries(ErArchiveReader::read(
        static fn (int $a, int $f, int $t): array => AC_GetAggregatedValues($ac, $priceVariable, $a, $f, $t, 0),
        ErArchiveReader::HOURLY,
        $dayStart,
        ts(2025, 3, 4) - 1
    ));
    $dynIntervals = $read('T01_StromEinfach', $dynAgg, $dayStart, ts(2025, 3, 4) - 1);
    $expectedDynamic = 0.0;
    foreach ($dynIntervals as $iv) {
        $expectedDynamic += $iv['value'] * (0.01 * (10 + (int) date('G', $iv['start'])) + 0.05);
    }
    $dynResult = ErCalculator::calculate($dynIntervals, $dynamicTariff, 1.0, false, $dayStart, ts(2025, 3, 4), ts(2025, 3, 4), true, ['dyn' => $priceSeries]);
    check('Archiv dynamisch: Tagesverbrauch', $dynResult['consumption'], 9.6);
    check('Archiv dynamisch: Kosten aus Stundenpreisen des Archivs', $dynResult['costsWork'], $expectedDynamic);
    check('Archiv dynamisch: keine Ausweichpreise nötig', $dynResult['warnings'], []);

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
