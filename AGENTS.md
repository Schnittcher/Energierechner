# Projektregeln

Lies zuerst das zentrale Regelwerk `E:\IP-Symcon\KI\AGENTS.md` und die für die Aufgabe relevanten Dokumente unter `E:\IP-Symcon\KI\standards\`. Es gilt für dieses Repository.

## Projektspezifischer Kontext

- Zweck: Verbrauch und Kosten eines im Archiv geloggten Zählers über beliebige Zeiträume, mit Tarifwechseln, Hoch- und Niedertarif, dynamischen Preisen, Grundpreis, Prognose und Saldo der Abschläge.
- Relevante Module: `Energierechner2` (Rechner), `EnergierechnerTarif2` (Tarif-Instanz), `EnergierechnerKachel` (Anzeige-Instanz für die Kachel-Visualisierung). Die alten Module `Energierechner` und `EnergierechnerTarif` bleiben erhalten.
- Zusätzliche Einstiegspunkte: `libs/` (Rechenklassen ohne Symcon-Bezug), `tests/run.php` (Prüfungen), `docs/CHANGELOG.md`.

## Ergänzungen oder Abweichungen

Keine Abweichung vom Regelwerk. Diese Regeln gelten zusätzlich:

- **Alte Module unverändert lassen.** `Energierechner` und `EnergierechnerTarif` sind veröffentlicht und laufen bei Nutzern. Sie werden nur auf ausdrücklichen Auftrag geändert, auch nicht umformatiert. Die neuen Module (`…2`, `EnergierechnerKachel`) hängen nicht von ihnen ab, außer der Übernahme in `ErLegacy` und `ER2_ImportLegacy`.
- **Idents ohne Datum.** Die Variablen-Idents des Energierechner 2 (`<Zeitraum>_<Art>`, Tarifzeiträume mit stabiler Zeilen-Id) sind ein öffentlicher Vertrag und bleiben stabil. Neue Arten kommen dazu, vorhandene werden nicht umbenannt.
- **Rechenlogik in `libs/`.** Berechnungen liegen in Klassen ohne IPS-Aufrufe und sind über `tests/run.php` prüfbar. Das Modul liest nur Archiv und Einstellungen und schreibt Variablen.
- **Die Kachel ist abtrennbar.** Sie besteht aus `libs/ErTile.php`, `libs/ErTileTrait.php`, `Energierechner2/tile.html` und dem Ordner `EnergierechnerKachel`. Im Rechner hängen nur die mit `// KACHEL` markierten Zeilen in `Energierechner2/module.php` daran. Neuer Kachel-Code gehört in diese Dateien, nicht in den Rechenkern.
- **Modulfunktionen ohne optionale Parameter.** Symcon erzeugt `ER2_…` und `ER2T_…` ohne Standardwerte. Parameter sind Pflicht (`ER2_GetPrice($id, 0)` für „jetzt").
- **Tests.** `tests/run.php` läuft direkt (`php tests/run.php`), unter PHPUnit (`tests/RechnerTest.php`) und auf `symcon-dev` mit zusätzlichen Archiv-Tests. Deren Testdaten liegen in einer Kategorie „Energierechner Demodaten“ auf `symcon-dev` und werden über den Namen gefunden, nie über ObjectIDs.
