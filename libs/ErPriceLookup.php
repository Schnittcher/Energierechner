<?php

declare(strict_types=1);

require_once __DIR__ . '/ErTariff.php';

/**
 * Preis je Einheit (bei Gas mit Umrechnung je kWh), der zu einem Zeitpunkt gilt: Hochtarif, Niedertarif oder
 * der aktuelle Preis eines dynamischen Tarifs. Grundlage für ER2_GetPrice und ER2T_GetPrice.
 * Die Auswahl des Preises ist reine Rechenlogik; nur dynamicValue() liest über IPS die Preisvariable.
 */
final class ErPriceLookup
{
    /**
     * @param callable(ErTariffSegment,int):?float $dynamicValue liefert den Rohwert der Preisvariable eines dynamischen
     *        Tarifabschnitts zum Zeitpunkt, oder null, wenn es keinen gibt (dann gilt der feste Preis)
     * @return array{price:float,type:string,dynamic:bool,fallback:bool,tariff:string,supplier:string,validFrom:int,validUntil:?int}
     *         type: "HT", "NT", "dynamic" oder "" ohne gültigen Tarif; validUntil = letzter Gültigkeitstag (null = unbefristet)
     */
    public static function at(ErTariff $tariff, int $timestamp, callable $dynamicValue): array
    {
        $none = [
            'price'      => 0.0,
            'type'       => '',
            'dynamic'    => false,
            'fallback'   => false,
            'tariff'     => '',
            'supplier'   => '',
            'validFrom'  => 0,
            'validUntil' => null
        ];
        $i = $tariff->indexAt($timestamp);
        if ($i < 0) {
            return $none;
        }
        $segment = $tariff->segments()[$i];

        $nt = $segment->isNt($timestamp);
        $price = $nt ? $segment->priceNt : $segment->priceHt;
        $type = $nt ? 'NT' : 'HT';
        $dynamic = false;
        $fallback = false;
        if ($segment->isDynamic()) {
            $raw = $dynamicValue($segment, $timestamp);
            if ($raw === null) {
                $fallback = true; // kein Preis zu diesem Zeitpunkt: fester Preis wie bei der Berechnung
            } else {
                $price = $raw * $segment->priceFactor + $segment->surcharge;
                $type = 'dynamic';
                $dynamic = true;
            }
        }

        $end = $tariff->segmentEnd($i, 0);
        return [
            'price'      => $price,
            'type'       => $type,
            'dynamic'    => $dynamic,
            'fallback'   => $fallback,
            'tariff'     => $segment->name,
            'supplier'   => $segment->supplier,
            'validFrom'  => $segment->validFrom,
            'validUntil' => $end > 0 ? $end - 1 : null
        ];
    }

    /**
     * Rohwert der Preisvariable eines dynamischen Tarifabschnitts zum Zeitpunkt.
     * Für "jetzt" (bis zu einer Stunde zurück) gilt der aktuelle Wert der Variable, für frühere Zeitpunkte der Archivwert
     * des Stunden- oder Viertelstundenintervalls. Für die Zukunft gibt es keinen Wert.
     * Nutzt IPS-Funktionen und wird deshalb nur aus den Modulen aufgerufen.
     */
    public static function dynamicValue(ErTariffSegment $segment, int $timestamp, int $archiveID, int $now): ?float
    {
        $variableID = $segment->priceVariableId;
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return null;
        }
        if ($timestamp > $now) {
            return null;
        }
        if ($now - $timestamp <= 3600) {
            return (float) GetValue($variableID);
        }
        if ($archiveID === 0 || !AC_GetLoggingStatus($archiveID, $variableID)) {
            return null;
        }
        $quarter = $segment->priceResolution === 15;
        $length = $quarter ? 900 : 3600;
        $start = $timestamp - ($timestamp % $length);
        $rows = AC_GetAggregatedValues($archiveID, $variableID, $quarter ? 8 : 0, $start, $start + $length - 1, 0);
        return is_array($rows) && count($rows) > 0 ? (float) $rows[0]['Avg'] : null;
    }
}
