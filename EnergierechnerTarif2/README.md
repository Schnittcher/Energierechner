# Energierechner Tarif 2

Hält die Tarifabschnitte für einen oder mehrere [Energierechner 2](../Energierechner2/README.md). Ein Tarifabschnitt gilt ab seinem Datum bis zum nächsten Eintrag.

## Einstellungen

| Spalte | Beschreibung |
|---|---|
| Name | Frei wählbar, erscheint im Namen der Variablen (leer = Datum). |
| Anbieter | Frei wählbar, nur zur Anzeige. Wird aus dem Feld „Stromanbieter" des alten Tarifs übernommen. |
| Gültig ab | Beginn des Tarifs. |
| Preis (Tag) | Arbeitspreis je Einheit des Zählers (kWh, m³ oder Liter). |
| Preis (Nacht) | Arbeitspreis in der Nachtzeit. 0 verwendet den Tagespreis. |
| Nacht von / bis | Nachtfenster. Beide Zeiten gleich lassen (z. B. 00:00), wenn es keinen Nachttarif gibt. Das Fenster darf über Mitternacht gehen (22:00 bis 06:00). |
| Grundpreis pro Jahr | Wird je Tag mit 1/365 (Schaltjahr 1/366) gerechnet. |
| Abschlag / Zahlungen pro Jahr | Grundlage für den Saldo. |
| Gas-Umrechnungsfaktor / Zustandszahl / Brennwert | Rechnen m³ in kWh um. Das Produkt der drei Werte ergibt die kWh je m³. |

Jede Zeile bekommt automatisch eine stabile, versteckte **Id**. Sie ist Teil der Idents der Variablen im Energierechner und bleibt gleich, wenn du Datum oder Preise änderst.

## Funktionen

```php
ER2T_GetTariff(int $InstanceID): string
```
Liefert die Tarifabschnitte als JSON, aufsteigend nach Gültigkeitsbeginn.

Ändert sich ein Tarif, rechnen die verbundenen Energierechner automatisch neu.
