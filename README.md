[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Check Style](https://github.com/Schnittcher/Energierechner/workflows/Check%20Style/badge.svg)](https://github.com/Schnittcher/Energierechner/actions)
[![Tests](https://github.com/Schnittcher/Energierechner/actions/workflows/tests.yml/badge.svg)](https://github.com/Schnittcher/Energierechner/actions/workflows/tests.yml)

# Energierechner
Diese IP-Symcon Bibliothek berechnet Verbrauch und Kosten eines im Archiv geloggten Zählers über beliebige Zeiträume, mit Tarifwechseln, Hoch- und Niedertarif, Grundpreis, Prognose und dem Saldo der Abschläge. Das Modul *Energierechner 2* ersetzt das bisherige Modul *Energierechner*, das aber erhalten bleibt.

## Inhaltsverzeichnis
- [Energierechner](#energierechner)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Voraussetzungen](#1-voraussetzungen)
  - [2. Funktionsumfang](#2-funktionsumfang)
  - [3. Enthaltene Module](#3-enthaltene-module)
  - [4. Installation](#4-installation)
  - [5. Konfiguration in IP-Symcon](#5-konfiguration-in-ip-symcon)
  - [6. Spenden](#6-spenden)
  - [7. Lizenz](#7-lizenz)

## 1. Voraussetzungen

* mindestens IPS Version 9.0 (ab Library-Version 3.0)
* eine Instanz Archive Control
* ein im Archiv geloggter Zähler für den Verbrauch (Aggregationstyp *Zähler*)
* bei dynamischen Preisen zusätzlich die im Archiv geloggte Preisvariable (Aggregationstyp *Standard*)

## 2. Funktionsumfang
* Verbrauch und Kosten für Heute, Gestern, Woche, Monat, Jahr, einzelne Tarifzeiträume und eigene Zeiträume
* Tarifwechsel, Hoch- und Niedertarif (mehrere Zeitfenster, Wochenende ganztägig) und dynamische Preise, zum Beispiel Börsenstrom
* Grundpreis taggenau, Abschläge und Saldo
* Prognose für Woche, Monat und Jahr, für Monat und Jahr mit dem Verlauf des Vorjahres
* Zählereinheiten kWh, Wh, Impulse, m³ (mit Umrechnung in kWh bei Gas) und Liter
* Zusatzwerte wie Durchschnittskosten je Tag, Vergleich mit dem Vorjahr, teuerster Tag und die Daten des aktuellen Tarifs
* Warnungen bei geänderten Archivdaten, fehlenden Preisen und vor dem Ende eines Tarifzeitraums
* Abfrage des aktuell gültigen Preises für Skripte
* Kachel-Visualisierung (experimentell)
* Übernahme der Einstellungen und Tarife aus dem bisherigen Energierechner

Die einzelnen Funktionen stehen bei den Instanzen unter "Enthaltene Module" und in der README des jeweiligen Moduls.

## 3. Enthaltene Module

* [Energierechner 2](Energierechner2/README.md)
  * Berechnet Verbrauch und Kosten eines Zählers für die gewählten Zeiträume und legt dafür Variablen an.
  * Geräteinstanz, der Tarif kommt aus dem Energierechner Tarif 2.
* [Energierechner Tarif 2](EnergierechnerTarif2/README.md)
  * Hält die Tarifabschnitte mit Preisen, Niedertarif, Grundpreis, Abschlag und Gaswerten.
  * Splitterinstanz, mehrere Energierechner können sich einen Tarif teilen.
* [Energierechner Kachel](EnergierechnerKachel/README.md)
  * Zeigt einen festen Zeitraum eines Energierechner 2 als Kachel in der Kachel-Visualisierung.
  * Anzeigeinstanz, experimentell.
* [Energierechner](Energierechner/README.md)
  * Berechnet die Verbrauchskosten über mehrere Zeiträume mit unterschiedlichen Arbeitspreisen.
  * Geräteinstanz, bisheriges Modul. Es bleibt unverändert erhalten, damit bestehende Installationen weiterlaufen.
* [EnergierechnerTarif](EnergierechnerTarif/README.md)
  * Hält den Tarif für den bisherigen Energierechner.
  * Splitterinstanz, bisheriges Modul.

Für neue Installationen wird *Energierechner 2* empfohlen.

## 4. Installation
Installation über den IP-Symcon Module Store.

## 5. Konfiguration in IP-Symcon

Als Erstes wird die Geräteinstanz *Energierechner 2* angelegt. Die Tarif-Instanz *Energierechner Tarif 2* wird dabei angeboten oder angelegt. In der Tarif-Instanz werden die Tarifzeiträume eingetragen, in der Geräteinstanz Verbrauchsvariable und Zählereinheit gewählt, die gewünschten Zeiträume angekreuzt und die Instanz aktiviert. Danach legt der Energierechner die Variablen an. Wer den bisherigen Energierechner nutzt, kann dessen Einstellungen und Tarife übernehmen (siehe [Umstieg](Energierechner2/README.md#7-umstieg-vom-alten-energierechner)).

Die weitere Dokumentation steht in den einzelnen Modulen.

## 6. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a> <a href="https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share" target="_blank">Amazon Wunschzettel</a>

## 7. Lizenz

Dieses Modul wurde im Auftrag von 12systems GmbH entwickelt.

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
