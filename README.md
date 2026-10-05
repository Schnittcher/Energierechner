[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-8.1%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Check Style](https://github.com/Schnittcher/IPS-Shelly/workflows/Check%20Style/badge.svg)](https://github.com/Schnittcher/Energierechner/actions)

# Energierechner
   Dieses IP-Symcon Modul ermöglicht eine Berechnung der gesamten Verbrauchskosten über mehrere Zeiträume mit unterschiedlichen Arbeitspreisen.
 
   ## Inhaltverzeichnis
   1. [Voraussetzungen](#1-voraussetzungen)
   2. [Enthaltene Module](#2-enthaltene-module)
   3. [Installation](#3-installation)
   4. [Konfiguration in IP-Symcon](#4-konfiguration-in-ip-symcon)
   5. [Spenden](#5-spenden)
   6. [Lizenz](#6-lizenz)
   
## 1. Voraussetzungen

* mindestens IPS Version 8.1 (ab Library-Version 3.0)
* geloggte Zähler Variable für den Gesamtverbrauch (Aggregationstyp Zähler)

## 2. Enthaltene Module

* [Energierechner 2](Energierechner2/README.md) und [Energierechner Tarif 2](EnergierechnerTarif2/README.md): das neue Modul mit Tarifwechseln, Nachttarif, Grundpreis, Prognose und Abschlags-Saldo. Zum Umstieg gibt es eine Übernahme der Einstellungen aus dem alten Modul.
* [Energierechner](Energierechner/README.md) und [EnergierechnerTarif](EnergierechnerTarif/README.md): die bisherigen Module. Sie bleiben unverändert erhalten, damit bestehende Installationen weiterlaufen.

Für neue Installationen wird *Energierechner 2* empfohlen.

## 3. Installation
Installation über den IP-Symcon Module Store.

## 4. Konfiguration in IP-Symcon

Als erstes wird die Geräteinstanz angelegt, dann die Instanz für die Tarife. Die weitere Dokumentation bitte den einzelnen Modulen entnehmen.

## Tests

Die Rechenlogik liegt in `libs/` und wird mit `php tests/run.php` geprüft. Auf einem Symcon-System laufen zusätzlich Integrationstests gegen das Archiv (siehe Kopf von `tests/run.php`).

## 5. Spenden

Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:    

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a> <a href="https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share" target="_blank">Amazon Wunschzettel</a>

## 6. Lizenz

Dieses Modul wurde im Auftrag von 12systems GmbH entwickelt.

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)