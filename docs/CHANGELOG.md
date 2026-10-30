# Changelog

## 26.10.2026 - Version 3.0.3

### Neu
- Die Energierechner Kachel kann auch Tarifzeiträume, die Summe und eigene Zeiträume anzeigen: Die Zeitraum-Auswahl listet alle Zeiträume des gewählten Energierechners.

## 25.10.2026 - Version 3.0.2

### Neu
- Neue Option "Ertrag statt Kosten" für Einspeisung, zum Beispiel einer PV-Anlage: Variablen und Kachel heißen dann Ertrag statt Kosten.

## 25.10.2026 - Version 3.0.1

### Neu
- Neue Variable "Durchschnittsverbrauch je Tag" bei den Zusatzwerten, auch in der Kachel.

## 07.10.2026 - Version 3.0

### Neu
- Neues Modul Energierechner 2 mit eigener Tarif-Instanz (Energierechner Tarif 2): Tarifwechsel, Hoch- und Niedertarif mit bis zu zwei Zeitfenstern und Wochenende, taggenauer Grundpreis, Abschläge und Saldo.
- Dynamische Preise (zum Beispiel Börsenstrom) aus einer geloggten Preisvariable.
- Prognose für Woche, Monat und Jahr, für Monat und Jahr mit dem Verlauf des Vorjahres.
- Zählereinheiten kWh, Wh, Impulse, m³ (mit Umrechnung in kWh bei Gas) und Liter.
- Warnungen im Formular und im Meldungsfenster, zum Beispiel bei geänderten Archivdaten oder fehlenden Preisen.
- Zusatzwerte als Variablen (Option): Durchschnittskosten je Tag, Vergleich mit dem Vorjahr, teuerster Tag sowie Preise und Ende des aktuellen Tarifs.
- Warnung vor dem Ende des Tarifzeitraums, einstellbar in Tagen.
- Die Funktionen `ER2_GetPrice` und `ER2T_GetPrice` liefern den aktuell gültigen Preis (HT, NT oder dynamisch) als Ersatz für `ER_getPrice`.
- Kachel-Visualisierung (experimentell) mit Zeitraumwechsel, Kosten, Verbrauch, Prognose, Saldo, Vorjahresvergleich, Tarif und Details.
- Die Kachel zeigt bei Gas den Verbrauch in m³ und kWh und den Preis je kWh.
- Neue Instanz Energierechner Kachel: zeigt einen festen Zeitraum eines Energierechners, so lassen sich mehrere Kacheln mit verschiedenen Zeiträumen anlegen.
- Die automatische Berechnung lässt sich abschalten, dann rechnet das Modul nur auf Anforderung (*Jetzt berechnen*, `ER2_UpdateCalculation`).
- Die Einstellungen und Tarife eines alten Energierechners lassen sich übernehmen.
- Achtung: Die Bibliothek braucht ab Version 3.0 IP-Symcon 9.0.
- Achtung: Der Energierechner 2 ist ein neues Modul neben dem unveränderten alten, und weil seine Variablen neu heißen (ohne Datum), müssen Verknüpfungen und Skripte angepasst werden.
- Achtung: Bei laufenden Zeiträumen zählt der Grundpreis nur bis heute, der Saldo nutzt den bis heute aufgelaufenen Abschlag, und das Aktualisierungsintervall steht in Minuten.
- Achtung: Bei Gas setzt die Übernahme die Zählereinheit m³ mit Umrechnung in kWh, bereits übernommene Instanzen müssen einmal von Hand auf m³ umgestellt werden.

### Fixes
- Die Kachel nennt beim Effektivpreis die Einheit des Zählers (zum Beispiel je m³ bei Wasser) statt immer kWh.
- Der Grundpreis wird taggenau gerechnet, auch in Schaltjahren und bei Tarifwechseln mitten im Zeitraum.
- Zeiträume über Mitternacht und mit mehreren Tarifwechseln werden bei Nachttarif korrekt gerechnet.

## 07.05.2026 - Version 2.0.5 (Beta Version)

### Neu
- Methode getGasCalculationValues öffentlich gesetzt

## 16.01.2024 - Version 2.0.4 (Beta Version)

### Neu
- Experten Einstellungen "Durch Parameteränderung aktualisieren"

### Fixes
- Übersetzungen

## 30.05.2023 - Version 2.0.3

### Neu
- Abschläge pro Jahr können in der Tarife Instanz hinterlegt werden
- Calculate Funktion auf öffentlich gesetzt
- Nach Änderung der Tarife Instanz wird nun die Berechnung erneut durchgeführt

### Fixes
- Berechnung aktuelle Woche: Der Sonntag fehlte
- Bei der Gas zu kWh Berechnung konnte es zu Fehlern kommen
- Nachkommastellen für Tagespreis, Nachtpreis und Grundpreis geändert
- Wenn keine Perioden im Buffer waren gab es bei der Gas Berechnung ein Problem

## 15.12.2022 - Version 2.0.2

### Neu
- Aktuelle Woche
- Funktion getPrice
- Gaspreisberechnung m² to kWh
- Zeitraum für das letzte Jahr
- Profil Liter

### Fixes
- Bei indivudelle Perioden wurden bei einer Änderung die Kostenvariablen neu angelegt
- Letzte Woche funktioniert wieder
- Übersetzung für Current Year Consumption (nighttime)
- Für die Benennung des tägliche und nächtlichen Verbrauch in der Form
- Tarife Instanz: Die Anzeige der Liste wurde auf 30 Einträge vergrößert
- Tarife Instanz: Die Anzeige der Liste wird nach Startdatum sortiert

## 14.03.2022 - Version 2.0.1

### Neu
- Berechnungszeitraum aktuelles Jahr
- Grundpreis Berechnung ist standardmäßig deaktiviert.
- Profil für Wh hinzugefügt

### Fixes
- Nach einem Neustart von IP-Symcon funktioniert die Berechnung nun korrekt.

## 24.02.2022 - Version 2.0

### Neu
- Energierechner mit Nachtstunden Tarif
- Auswahl von Berechnungszeiträumen (Zeitraumberechnung anhand der Perioden, Täglich, Vortag, Vorwoche, Aktueller Monat, Letzter Monat, Individuelle Zeiträume)
- Aktualisierungszeit kann eingestellt werden
- Integervariablen können auch als Verbrauchsvariable hinterlegt werden
- Umrechnung von Impulszählern (Experteneinstellungen)
- Die Tarife werden in einem Splitter gepflegt, somit können mehrere Zähler überwacht werden, ohne das die Tarife immer erneut gepflegt werden müssen
- Pro Zeitraum kann ein Grundpreis hinterlegt werden
- Der Name des Anbieters kann hinterlegt werden
- Es kann ausgewählt werden, welche Einheit genutzt werden soll (kWh, m³)
- Verbräuche können tags- und nachtsüber ermittelt werden

## 03.12.2021 - Version 1.0 (Beta)
