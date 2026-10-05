# Energierechner 2

Berechnet Verbrauch und Kosten eines im Archiv geloggten Zählers für beliebige Zeiträume. Tarifwechsel, Nachttarif, Grundpreis, Prognose und der Saldo der Abschlagszahlungen werden berücksichtigt.

Voraussetzung: Symcon 8.1 oder neuer. Die Tarife liegen in einer eigenen Instanz, dem [Energierechner Tarif 2](../EnergierechnerTarif2/README.md). Mehrere Zähler können sich einen Tarif teilen.

## Inhalt

1. [Einrichtung](#1-einrichtung)
2. [Einstellungen](#2-einstellungen)
3. [Variablen](#3-variablen)
4. [So wird gerechnet](#4-so-wird-gerechnet)
5. [Grenzen](#5-grenzen)
6. [Umstieg vom alten Energierechner](#6-umstieg-vom-alten-energierechner)
7. [Funktionen](#7-funktionen)

## 1. Einrichtung

1. Die Zählervariable im Archiv loggen, mit dem Aggregationstyp **Zähler**.
2. Eine Instanz *Energierechner 2* anlegen. Das Tarif-Gateway *Energierechner Tarif 2* wird dabei mit angeboten oder angelegt.
3. Im Tarif die Tarifzeiträume eintragen (siehe README des Tarifs).
4. Verbrauchsvariable und Einheit wählen, Zeiträume ankreuzen und die Instanz aktivieren.

## 2. Einstellungen

| Feld | Beschreibung |
|---|---|
| Aktiv | Schaltet die Berechnung ein oder aus. |
| Verbrauchsvariable | Zähler, der im Archiv mit Aggregationstyp *Zähler* geloggt wird. |
| Zählereinheit | kWh, Wh, Impulse, m³ oder Liter. Wh und Impulse werden in kWh umgerechnet, damit der Preis je kWh passt. |
| Impulse pro kWh | Erscheint nur, wenn als Zählereinheit „Impulse" gewählt ist. |
| Gas: m³ in kWh umrechnen | Rechnet m³ mit Umrechnungsfaktor, Zustandszahl und Brennwert des Tarifs in kWh um. Der Verbrauch bleibt in m³, die Kosten entstehen aus den kWh. |
| Zeiträume | Heute, Gestern, aktuelle Woche und Vorwoche, aktueller Monat und Vormonat, aktuelles Jahr und Vorjahr. |
| Je Tarifzeitraum | Ein Satz Werte pro Tarifabschnitt, dazu die Summe aller Tarifabschnitte. |
| Eigene Zeiträume | Beliebige Zeiträume mit Name, Start- und Enddatum (Enddatum inklusive). |
| Grundpreis einrechnen | Rechnet den Grundpreis in die Kosten ein. |
| Arbeitskosten und Grundpreis getrennt | Zusätzliche Variablen für beide Anteile. |
| Tag und Nacht getrennt | Verbrauch und Arbeitskosten getrennt nach Tag- und Nachtfenster. |
| Prognose | Hochrechnung für die laufende Woche, den Monat und das Jahr. |
| Abgeschlossene Zeiträume loggen | Schaltet das Archiv-Logging für Kosten und Verbrauch von Gestern, Vorwoche, Vormonat und Vorjahr ein (nur für aktivierte Zeiträume). Diese Werte ändern sich nur einmal je Zeitraum und ergeben saubere Verlaufsdiagramme. Beim Ausschalten der Option wird nur das wieder abgeschaltet, was das Modul selbst eingeschaltet hat. Von Hand geloggte Variablen bleiben unberührt, und bereits archivierte Werte bleiben erhalten. |
| Saldo der Abschläge | Abschläge abzüglich Kosten, für das aktuelle Jahr, das Vorjahr, je Tarifzeitraum und als Summe. |
| Aktualisierungsintervall | Wie oft die laufenden Zeiträume neu berechnet werden (Minuten). |

## 3. Variablen

Die Idents sind stabil und enthalten **kein Datum**. Ändert sich ein Datum im Tarif, bleiben die Variablen samt Archivdaten erhalten.

Schema: `<Zeitraum>_<Art>`

| Zeitraum | Ident-Präfix |
|---|---|
| Heute / Gestern | `Day_Current` / `Day_Previous` |
| Aktuelle Woche / Vorwoche | `Week_Current` / `Week_Previous` |
| Aktueller Monat / Vormonat | `Month_Current` / `Month_Previous` |
| Aktuelles Jahr / Vorjahr | `Year_Current` / `Year_Previous` |
| Tarifzeitraum | `Tariff_<Id>` (Id des Tarifzeitraums, bleibt bei Datumsänderung gleich) |
| Summe aller Tarifzeiträume | `Total` |
| Eigener Zeitraum | `Custom_<Id>` |

| Art | Inhalt |
|---|---|
| `Costs` / `Consumption` | Kosten und Verbrauch |
| `CostsWork` / `CostsBase` | Arbeitskosten und Grundpreis getrennt |
| `ConsumptionDay` / `CostsDay` / `ConsumptionNight` / `CostsNight` | Tag und Nacht (Kosten ohne Grundpreis) |
| `Energy` | kWh bei Gas mit Umrechnung |
| `ForecastCosts` / `ForecastConsumption` | Prognose (nur laufende Woche, Monat, Jahr) |
| `Balance` | Saldo der Abschläge (aktuelles Jahr, Vorjahr, Tarifzeiträume und Summe; positiv = Guthaben) |

Außerdem gibt es `LastCalculation` mit dem Zeitpunkt der letzten Berechnung.

## 4. So wird gerechnet

- **Archivwerte:** Ohne Nachtfenster im Tarif werden Tageswerte gelesen, mit Nachtfenster Stundenwerte (bei Grenzen auf Viertelstunden Viertelstundenwerte). Abfragen werden so geteilt, dass das Limit von 10.000 Datensätzen nie erreicht wird.
- **Tarifwechsel und Nachtfenster:** Jedes Intervall wird an Tarifwechseln und Grenzen des Nachtfensters geteilt und anteilig bewertet. Das Nachtfenster beginnt inklusive und endet exklusive: Bei 22:00 bis 06:00 gehört die Stunde ab 22:00 zur Nacht und die ab 06:00 zum Tag.
- **Grundpreis:** Jahrespreis geteilt durch die Tage des Kalenderjahres (365 oder 366), je Tag mit dem Tarif dieses Tages. Laufende Zeiträume zählen bis einschließlich heute, abgeschlossene vollständig. Eine Woche hat damit 7 Tage Grundpreis, ein Monat so viele wie er Tage hat.
- **Abschlag und Saldo:** Abschlag mal Zahlungen pro Jahr, tageweise aufgelaufen wie der Grundpreis.
- **Prognose:** Lineare Hochrechnung nach verstrichener Zeit. Arbeitskosten werden hochgerechnet, der Grundpreis gilt für den ganzen Zeitraum. Sie erscheint erst, wenn mindestens 6 Stunden des Zeitraums vergangen sind.
- **Abgeschlossene Zeiträume** werden zwischengespeichert und erst neu berechnet, wenn sich Tarif oder Einstellungen ändern. *Neu berechnen* verwirft den Zwischenspeicher.

### Welche Werte sich zum Loggen eignen

- **Abgeschlossene Zeiträume** (`Day_Previous`, `Week_Previous`, `Month_Previous`, `Year_Previous`, jeweils `Costs` und `Consumption`): ein Wert je Zeitraum. Dafür gibt es die Option oben. Die geloggten Werte halten außerdem fest, wie die Abrechnung damals war, auch wenn sich später der Tarif ändert.
- **Verläufe:** `Year_Current_Balance` und die Prognosen ändern sich bei jeder Aktualisierung (bei 10 Minuten rund 144 Werte pro Tag). Nur gezielt von Hand loggen.
- **Nicht nötig:** die laufenden Zeiträume (`…_Current`), sie lassen sich jederzeit aus dem Zählerarchiv neu berechnen.
- Berechnete Werte werden mit dem Aggregationstyp *Standard* geloggt, nicht als Zähler.
- Den Zähler selbst nicht verdichten oder Rohdaten löschen lassen: Für Nacht und Tarifwechsel sind die Stundenwerte nötig.

## 5. Grenzen

- **Datenlücken im Archiv:** Das Archiv füllt Lücken mit Nullwerten auf. Der Zählerstand-Zuwachs der Lücke erscheint gesammelt in der ersten Stunde danach. Die **Summe stimmt**, aber die Zuordnung zu Tag/Nacht und Tarifwechseln innerhalb der Lücke nicht.
- **Zählerreset:** Ein Rücksetzen auf einen kleineren Wert ignoriert das Archiv. Der Verbrauch der Stunde, in der es passiert, fehlt.
- **Erster Archivwert:** Der erste geloggte Wert eines Zählers ist nur Referenz und wird nicht als Verbrauch gezählt.
- **Zeiträume vor dem ersten Tarif** werden ohne Preis berechnet (Kosten 0). Dazu schreibt die Instanz eine Meldung in die Debug-Ausgabe.

## 6. Umstieg vom alten Energierechner

Die neue Instanz lässt sich neben der alten betreiben, so lassen sich die Werte vergleichen. Unter *Aus dem alten Energierechner übernehmen* werden Einstellungen, eigene Zeiträume und die Tarife aus einer alten Instanz kopiert. Die neue Instanz bleibt danach inaktiv, bis du sie aktivierst.

Dabei ändern sich zwei Dinge in den **Beträgen**, weil das alte Modul sie falsch berechnet hat:

- Der Grundpreis zählt jetzt alle Tage des Zeitraums (früher fehlte ein Tag), und ein Tarifwechsel innerhalb eines Zeitraums wird berücksichtigt.
- Die Grenzen des Nachtfensters sind jetzt halboffen. Früher wurde die Stunde ab 22:00 als Tag und die ab 06:00 als Nacht gewertet.

Umbenennung der Variablen:

| Alt | Neu |
|---|---|
| `TodayCosts`, `TodayConsumption` | `Day_Current_Costs`, `Day_Current_Consumption` |
| `PreviousDay…` | `Day_Previous_…` |
| `CurrentWeek…`, `PreviousWeek…` | `Week_Current_…`, `Week_Previous_…` |
| `CurrentMonth…`, `LastMonth…` | `Month_Current_…`, `Month_Previous_…` |
| `CurrentYear…`, `LastYear…` | `Year_Current_…`, `Year_Previous_…` |
| `…Daytime`, `…Nighttime` | `ConsumptionDay`, `CostsDay`, `ConsumptionNight`, `CostsNight` |
| `totalCosts`, `totalConsumption` | `Total_Costs`, `Total_Consumption` |
| `Total_costs_period1_1_2024` | `Tariff_<Id>_Costs` |
| `Balance_period1_1_2024` | `Tariff_<Id>_Balance` |

## 7. Funktionen

```php
ER2_UpdateCalculation(int $InstanceID): bool
```
Berechnet alle aktivierten Zeiträume. Abgeschlossene Zeiträume kommen aus dem Zwischenspeicher.

```php
ER2_Recalculate(int $InstanceID): bool
```
Verwirft den Zwischenspeicher und berechnet alles neu.

```php
ER2_ImportLegacy(int $InstanceID, int $LegacyInstanceID): string
```
Übernimmt Einstellungen und Tarife aus einer Instanz des alten Energierechners und gibt eine Meldung zurück.
