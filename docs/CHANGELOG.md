# 07.10.2026 - Version 3.0
## Neu
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

## Fixes
- Der Grundpreis wird taggenau gerechnet, auch in Schaltjahren und bei Tarifwechseln mitten im Zeitraum.
- Zeiträume über Mitternacht und mit mehreren Tarifwechseln werden bei Nachttarif korrekt gerechnet.
