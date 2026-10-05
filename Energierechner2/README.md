# Energierechner 2

Berechnet Verbrauch und Kosten eines im Archiv geloggten Zählers für beliebige Zeiträume. Tarifwechsel, Nachttarif, **dynamische Preise** (z. B. Börsenstrom), Grundpreis, Prognose und der Saldo der Abschlagszahlungen werden berücksichtigt.

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
| Gas: m³ in kWh umrechnen | Erscheint nur, wenn als Zählereinheit „m³" gewählt ist. Siehe [Gas](#gas-m³-in-kwh). |
| Zeiträume | Heute, Gestern, aktuelle Woche und Vorwoche, aktueller Monat und Vormonat, aktuelles Jahr und Vorjahr. |
| Je Tarifzeitraum | Ein Satz Werte pro Tarifabschnitt, dazu die Summe aller Tarifabschnitte. |
| Eigene Zeiträume | Beliebige Zeiträume mit Name, Start- und Enddatum (Enddatum inklusive). |
| Grundpreis einrechnen | Rechnet den Grundpreis in die Kosten ein. |
| Arbeitskosten und Grundpreis getrennt | Zusätzliche Variablen für beide Anteile. |
| Tag und Nacht getrennt | Verbrauch und Arbeitskosten getrennt nach Tag- und Nachtfenster. |
| Prognose | Hochrechnung für die laufende Woche, den Monat und das Jahr. Siehe [Prognose](#prognose). |
| Prognose mit Vorjahresverlauf | Für Monat und Jahr den Verlauf des Vorjahres nutzen, damit Winter- und Sommerverbrauch die Prognose nicht verzerren. Ohne Vorjahresdaten rechnet das Modul linear. Standardmäßig an. |
| Abgeschlossene Zeiträume loggen | Schaltet das Archiv-Logging für Kosten und Verbrauch von Gestern, Vorwoche, Vormonat und Vorjahr ein (nur für aktivierte Zeiträume). Diese Werte ändern sich nur einmal je Zeitraum und ergeben saubere Verlaufsdiagramme. Beim Ausschalten der Option wird nur das wieder abgeschaltet, was das Modul selbst eingeschaltet hat. Von Hand geloggte Variablen bleiben unberührt, und bereits archivierte Werte bleiben erhalten. |
| Saldo der Abschläge | Abschläge abzüglich Kosten, für das aktuelle Jahr, das Vorjahr, je Tarifzeitraum und als Summe. |
| Aktualisierungsintervall | Wie oft die laufenden Zeiträume neu berechnet werden (Minuten). |

### Statusmeldungen

Die Instanz zeigt im Formular, warum sie nicht rechnet:

| Status | Bedeutung | Abhilfe |
|---|---|---|
| Aktiv | Die Instanz rechnet. | |
| Inaktiv | „Aktiv" ist nicht angehakt. | |
| Bitte Verbrauchsvariable und Einheit wählen (201) | Es fehlt die Verbrauchsvariable oder die Zählereinheit. | Beides auswählen. |
| Kein Archiv gefunden (202) | Auf dem System gibt es keine Archive-Control-Instanz. | Archive Control anlegen. |
| Variable nicht im Archiv geloggt (203) | Die Verbrauchsvariable wird nicht geloggt. | Logging im Archiv einschalten. |
| Aggregationstyp muss „Zähler" sein (204) | Die Verbrauchsvariable ist als Standard-Wert geloggt. | Aggregationstyp auf *Zähler* stellen und neu aggregieren. |
| Kein Tarif verbunden oder keine Tarifzeiträume (205) | Es ist kein *Energierechner Tarif 2* verbunden, oder er enthält keine Zeile. | Tarif verbinden und mindestens eine Zeile eintragen. Sobald ein Tarif da ist, läuft die Instanz von selbst weiter, bereits angelegte Variablen bleiben in der Zwischenzeit erhalten. |

Nur die **Eingangsdaten** müssen geloggt sein (Zähler und, bei dynamischen Tarifen, die Preisvariable). Die berechneten Variablen brauchen kein Logging, es ist nur für Verläufe gedacht (siehe unten).

### Gas: m³ in kWh

Gas wird in m³ gemessen, aber in **kWh** abgerechnet. Der Preis auf der Rechnung ist ein kWh-Preis.

| Schalter | Wirkung |
|---|---|
| Aus | Der Preis des Tarifs wird direkt auf die m³ angewendet. Das stimmt nur, wenn im Tarif ein Preis je m³ steht. |
| An | Jeder Verbrauchswert wird mit **Umrechnungsfaktor × Zustandszahl × Brennwert** (aus der Tarifzeile) in kWh umgerechnet. Die Kosten sind kWh × Preis. Der Verbrauch bleibt in m³, die kWh stehen zusätzlich in der Variable `…_Energy`. |

Zustandszahl und Brennwert stehen auf der Gasrechnung und ändern sich mit dem Abrechnungszeitraum, deshalb gehören sie zur Tarifzeile. Ist einer der drei Werte 0, sind die Kosten 0. Dazu zeigt das Modul eine Warnung an (siehe unten).

### Warnungen

Stellt das Modul bei der Berechnung ein Problem fest, steht oben im Konfigurationsformular eine Warnung, und ein Eintrag im Meldungsfenster (einmal je neuer Warnung):

| Warnung | Ursache |
|---|---|
| Teil des Zeitraums liegt vor dem ersten Tarifzeitraum | Verbrauch aus der Zeit vor dem ersten Tarif hat keinen Preis und keinen Grundpreis. Lösung: einen früheren Tarifzeitraum eintragen. |
| Gas-Umrechnungswerte fehlen | Faktor, Zustandszahl oder Brennwert ist 0 (nur bei „Gas: m³ in kWh umrechnen"). |
| Für einen dynamischen Tarif fehlen Preisdaten | In einigen Intervallen gibt es keinen Preis. Dort gilt der feste Preis (Tag) als Ausweichwert. |
| Preisvariable nicht nutzbar | Die Variable eines dynamischen Tarifs existiert nicht oder wird nicht im Archiv geloggt. |
| Archivdaten haben sich geändert | Nachdem ein abgeschlossener Zeitraum berechnet wurde, wurden Verbrauchs- oder Preisdaten im Archiv nachträglich geändert (z. B. Lücke nachgetragen, Werte korrigiert). Die Warnung nennt die betroffenen Zeiträume. Mit *Neu berechnen* werden sie aktualisiert, danach verschwindet die Warnung. |

**Wie die Änderung erkannt wird:** Beim Zwischenspeichern eines abgeschlossenen Zeitraums merkt sich das Modul die Summe der Tageswerte aus dem Archiv, bei dynamischen Tarifen auch die der Preisvariablen. Bei jedem Durchlauf vergleicht es diese Summen mit dem aktuellen Stand. Das sind wenige Datensätze und kostet kaum Zeit. Eine Änderung, die die Summe über den ganzen Zeitraum nicht verändert (z. B. Verbrauch von einer Stunde in eine andere verschoben, was bei Nachttarif oder dynamischen Preisen die Kosten ändern würde), wird nicht erkannt.

Die Warnung verschwindet nach der nächsten Berechnung, wenn die Ursache behoben ist.

### Namen der Variablen

- Die **Idents** der Variablen sind fest (siehe unten). Die **Namen** vergibt das Modul nur beim **Anlegen** der Variable.
- Benennst du später einen Tarifzeitraum oder einen eigenen Zeitraum um, **ändern sich die Namen der bereits vorhandenen Variablen nicht**. Neue Variablen bekommen den neuen Namen.
- Du kannst Variablen jederzeit selbst umbenennen. Das Modul überschreibt Namen nicht.
- Wird ein **Tarifzeitraum, eine Zeile bei den eigenen Zeiträumen oder ein ganzer Zeitraum abgewählt**, werden die zugehörigen Variablen **gelöscht**. Verknüpfungen, Skripte oder Kacheln, die darauf zeigen, laufen ins Leere.
- Eine gelöschte und neu angelegte Zeile bekommt eine **neue Id** und damit neue Variablen. Wer Datum oder Preise ändern will, ändert deshalb besser die bestehende Zeile, statt sie zu löschen.

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

- **Archivwerte:** Ohne Nachtfenster und ohne dynamischen Preis im Tarif werden Tageswerte gelesen. Mit Nachtfenster oder dynamischem Preis sind es Stundenwerte, und bei Nachtgrenzen oder Preisen im Viertelstundenraster Viertelstundenwerte. Abfragen werden so geteilt, dass das Limit von 10.000 Datensätzen nie erreicht wird.
- **Tarifzeiträume:** Ein Tarifabschnitt gilt von seinem Datum bis zum Beginn des nächsten. Der letzte Abschnitt hat kein Ende. In den Variablen reicht er bis heute.
- **Tarifwechsel und Nachtfenster:** Jedes Intervall wird an Tarifwechseln und Grenzen des Nachtfensters geteilt und anteilig bewertet. Das Nachtfenster beginnt inklusive und endet exklusive: Bei 22:00 bis 06:00 gehört die Stunde ab 22:00 zur Nacht und die ab 06:00 zum Tag.
- **Grundpreis:** Jahrespreis geteilt durch die Tage des Kalenderjahres (365 oder 366), je Tag mit dem Tarif dieses Tages. Laufende Zeiträume zählen bis einschließlich heute, abgeschlossene vollständig. Eine Woche hat damit 7 Tage Grundpreis, ein Monat so viele wie er Tage hat.
- **Abschlag und Saldo:** Abschlag mal Zahlungen pro Jahr, tageweise aufgelaufen wie der Grundpreis. Der Saldo ist damit eine **gleichmäßige Hochrechnung**: aufgelaufener Abschlag minus aufgelaufene Kosten. Echte Zahlungen (Zeitpunkt, Betrag) fließen nicht ein. Positiv bedeutet Guthaben.
- **Sommer- und Winterzeit:** Ein Tag mit Zeitumstellung hat 23 oder 25 Stunden. Gerechnet wird mit den tatsächlichen Stunden aus dem Archiv.
- **Prognose:** siehe [Prognose](#prognose).
- **Abgeschlossene Zeiträume** werden zwischengespeichert und erst neu berechnet, wenn sich Tarif oder Einstellungen ändern. Laufende Zeiträume werden bei jeder Aktualisierung neu berechnet.
- **Neu berechnen:** Ändern sich **Archivdaten** nachträglich, bleiben abgeschlossene Zeiträume bei ihrem alten Wert. Das betrifft Verbrauchsdaten (Datenlücke nachgetragen, falsche Zählerwerte korrigiert oder gelöscht, neu aggregiert) genauso wie Preisdaten eines dynamischen Tarifs. Die Schaltfläche **Neu berechnen** am Ende des Konfigurationsformulars der Instanz (im Bereich der Aktionen) oder die Funktion `ER2_Recalculate($InstanzID)` verwirft den Zwischenspeicher und rechnet alles neu.

### Prognose

Die Prognose gibt es für die **aktuelle Woche, den aktuellen Monat und das aktuelle Jahr** (Variablen `ForecastConsumption` und `ForecastCosts`). Sie erscheint erst, wenn mindestens 6 Stunden des Zeitraums vergangen sind.

```
Prognose Verbrauch = bisheriger Verbrauch ÷ Anteil
Prognose Kosten   = bisherige Arbeitskosten ÷ Anteil + voller Grundpreis des Zeitraums
```

Der **Anteil** sagt, welcher Teil des Zeitraums schon erreicht ist. Er wird auf zwei Arten bestimmt:

| Methode | Wann | Anteil |
|---|---|---|
| **Vorjahresverlauf** | Monat und Jahr, wenn „Prognose mit Vorjahresverlauf" an ist und das Vorjahr brauchbare Daten hat | Welchen Anteil seines Verbrauchs der Zeitraum im Vorjahr zum **gleichen Zeitpunkt** schon erreicht hatte |
| **Linear** | Woche (immer), sowie Monat und Jahr, wenn der Vorjahresverlauf nicht nutzbar ist | Verstrichene Zeit ÷ Gesamtdauer des Zeitraums |

**Beispiel:** Ein Haus mit Wärmepumpe hatte im Vorjahr bis Ende März schon 50 % seines Jahresverbrauchs, obwohl erst 25 % der Zeit vorbei waren. Hat es dieses Jahr bis Ende März 2.700 kWh verbraucht, sagt die lineare Prognose 10.800 kWh voraus, der Vorjahresverlauf 5.400 kWh (2.700 ÷ 0,5). Das Verbrauchsniveau kommt aus dem laufenden Jahr, nur die Saisonform aus dem Vorjahr.

- **Fallback auf linear:** Das Modul nutzt den Vorjahresverlauf nur, wenn das Vorjahr Verbrauch enthält und der Anteil plausibel ist. Weicht er um mehr als das Dreifache vom zeitlichen Anteil ab (typisch bei einem Zähler, der erst im Vorjahr in Betrieb ging oder lückenhaft geloggt wurde), rechnet das Modul linear. Welche Methode benutzt wurde, zeigt die Debug-Ausgabe.
- **Woche:** bleibt linear, weil der Wochenrhythmus (Werktag, Wochenende) im Kalender-Vorjahr keine Entsprechung hat.
- **Arbeitskosten** werden mit demselben Anteil hochgerechnet, der **Grundpreis** zählt voll für den ganzen Zeitraum, tageweise mit dem Tarif des jeweiligen Tages (für künftige Tage mit dem zuletzt gültigen).
- **Dynamische Preise:** Hochgerechnet werden die bisher angefallenen Kosten zu den tatsächlichen Preisen, es gibt keine Preisvorhersage.
- **Tag und Nacht** werden nicht getrennt prognostiziert.
- Das Vorjahr wird je Zeitraum nur einmal am Tag aus dem Archiv gelesen, weil es sich nicht mehr ändert.

### Wann wird was neu berechnet

Ein Durchlauf startet alle *Aktualisierungsintervall* Minuten, nach jedem Übernehmen der Instanzeinstellungen, sobald sich der verbundene Tarif ändert und per *Neu berechnen* oder `ER2_UpdateCalculation` / `ER2_Recalculate`.

| Variablen | Neu berechnet |
|---|---|
| `Day_Current`, `Week_Current`, `Month_Current`, `Year_Current` | Bei jedem Durchlauf. |
| `Day_Previous`, `Week_Previous`, `Month_Previous`, `Year_Previous` | Bis eine Stunde nach Ende des Zeitraums, danach aus dem Zwischenspeicher. |
| `Tariff_<Id>_…` | Der laufende (letzte) Tarifzeitraum bei jedem Durchlauf, abgeschlossene aus dem Zwischenspeicher. |
| `Total_…` | Bei jedem Durchlauf (nur Summe der Tarifzeiträume). |
| `Custom_<Id>_…` | Solange der Zeitraum läuft oder nicht länger als eine Stunde vorbei ist, danach aus dem Zwischenspeicher. |
| `LastCalculation` | Bei jedem Durchlauf. |

Alle Variablen eines Zeitraums werden gemeinsam geschrieben. Abgeschlossene Zeiträume werden **automatisch** neu berechnet, wenn sich ein Tarifwert, die Verbrauchsvariable, die Einheit, die Impulse pro kWh, der Gas-Schalter oder „Grundpreis einrechnen" ändert. Reine Anzeige-Optionen (Prognose, Tag/Nacht usw.) legen nur Variablen an oder entfernen sie.

**Neu berechnen** ändert nichts an Einstellungen, Tarif, Variablen oder Archiv. Das Archiv schreibt Vergangenes nicht um: Ändert sich durch das Neuberechnen der Wert einer **geloggten** Ergebnisvariable, bleibt der alte Datenpunkt im Archiv, und der neue Wert kommt mit dem aktuellen Zeitstempel hinzu. Läuft gerade eine Berechnung, tut *Neu berechnen* nichts.

### Debug-Ausgabe

Die Debug-Ausgabe der Instanz zeigt, wie viele Archivwerte gelesen wurden, welche Aggregation gewählt wurde, Warnungen je Zeitraum und erkannte Archivänderungen. Sie hilft bei der Fehlersuche.

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
- **Zeiträume vor dem ersten Tarif** werden ohne Preis berechnet (Kosten 0). Dazu zeigt die Instanz eine Warnung an.
- **Prognose:** Sie ist eine Hochrechnung und keine Vorhersage. Sie kennt weder Wetter noch Verhaltensänderungen. Gerade kurz nach Beginn eines Zeitraums (Jahresanfang, Monatsanfang) schwankt sie stark, weil wenige Tage hochgerechnet werden. Künftige Tarifwechsel sind nicht bekannt.
- **Dynamische Preise:** Der Preis gilt für das Intervall, in dem er im Archiv steht. Hat das Archiv für ein Intervall keinen Wert, wird der feste Preis (Tag) verwendet und eine Warnung angezeigt. Änderungen an der Tarifzeile (Preis, Aufschlag, Grundpreis, Datum) werden automatisch auch für abgeschlossene Zeiträume übernommen. Werden dagegen **Preisdaten im Archiv** nachträglich geändert oder nachgeliefert (z. B. korrigierte Börsenpreise), bleiben bereits abgeschlossene Zeiträume bei ihrem alten Wert, bis du *Neu berechnen* drückst (siehe unten).

## 6. Umstieg vom alten Energierechner

Die neue Instanz lässt sich neben der alten betreiben, so lassen sich die Werte vergleichen. Unter *Aus dem alten Energierechner übernehmen* werden Einstellungen, eigene Zeiträume und die Tarife aus einer alten Instanz kopiert. Die neue Instanz bleibt danach inaktiv, bis du sie aktivierst.

Die Schaltfläche *Einstellungen übernehmen* kopiert Zählervariable, Einheit, Zeiträume, Optionen und eigene Zeiträume. Den Tarif übernimmt sie in die verbundene Tarif-Instanz. Ist keine verbunden, legt sie eine neue an. Enthält der verbundene Tarif schon Zeilen, bleibt er unverändert. Das Feld „Stromanbieter" wird zum Feld „Anbieter".

Dabei ändern sich einige Dinge in den **Beträgen**, weil das alte Modul sie falsch berechnet hat:

- Der Grundpreis zählt jetzt alle Tage des Zeitraums (früher fehlte ein Tag), und ein Tarifwechsel innerhalb eines Zeitraums wird berücksichtigt.
- Die Grenzen des Nachtfensters sind jetzt halboffen. Früher wurde die Stunde ab 22:00 als Tag und die ab 06:00 als Nacht gewertet.
- Das Enddatum eigener Zeiträume ist jetzt inklusive.
- Der Saldo rechnet mit dem bis heute aufgelaufenen Abschlag. Früher wurde der volle Jahresabschlag gegen die Kosten gerechnet.
- Bei der Einheit **Wh** werden die Werte jetzt in kWh umgerechnet, die Preise im Tarif müssen deshalb **je kWh** stehen. Das alte Modul hat Wh-Werte ohne Umrechnung mit dem eingetragenen Preis multipliziert. Wer dort einen Preis **je Wh** eingetragen hatte, muss ihn beim Umstieg mit 1000 multiplizieren, sonst sind die Kosten 1000-fach zu niedrig. Die Übernahme kopiert die Preise unverändert und weist darauf hin.

Weitere Unterschiede zum alten Modul:

- Die Schalter „Verbrauch Tag" und „Verbrauch Nacht" sind zu **Tag und Nacht getrennt** zusammengefasst. Der Nachtpreis wirkt immer, sobald im Tarif ein Nachtfenster steht. Beim Übernehmen entsteht nur dann ein Nachtfenster, wenn das alte Modul den Nachttarif benutzt hat, damit die Kosten gleich bleiben.
- Die Schalter für Monats-, Wochen- und Jahresaggregation und „Durch Parameteränderung aktualisieren" entfallen. Die Aggregation wird automatisch gewählt, und bei Änderungen wird immer neu gerechnet.
- Bei Gas bleibt der Verbrauch in m³, die kWh stehen in der Variable `…_Energy`.
- Das Aktualisierungsintervall ist in Minuten (früher Sekunden).
- Die Funktionen heißen `ER2_…`. Die alten Hilfsfunktionen `ER_calculate`, `ER_getPrice` und `ER_getGasCalculationValues` gibt es nicht mehr.
- Die Variablen haben **keine Archivhistorie** der alten Variablen. Sie beginnt neu.

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
