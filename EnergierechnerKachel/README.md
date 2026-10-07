# Energierechner Kachel
Reine Anzeige-Instanz für die Kachel-Visualisierung (experimentell). Sie zeigt **einen festen Zeitraum** eines [Energierechner 2](../Energierechner2/README.md) und rechnet nichts selbst. So legst du aus einem Energierechner mehrere Kacheln an, zum Beispiel je eine für Heute, Diesen Monat und Dieses Jahr nebeneinander.

Voraussetzung ist eine Instanz *Energierechner 2*, in der der gewünschte Zeitraum aktiviert ist.

## Inhaltsverzeichnis
- [Energierechner Kachel](#energierechner-kachel)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Funktionen](#2-funktionen)
  - [3. Hinweise](#3-hinweise)
  - [4. Spenden](#4-spenden)
  - [5. Lizenz](#5-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Energierechner 2 | Die Instanz, deren Werte angezeigt werden.
Zeitraum | Heute, Gestern, Diese Woche, Letzte Woche, Dieser Monat, Letzter Monat, Dieses Jahr oder Letztes Jahr. Der Zeitraum muss im Energierechner 2 aktiviert sein, sonst zeigt die Kachel „Keine Zeiträume aktiviert".
Darstellung | *Automatisch* (kompakt bei kleinen Kacheln, sonst Standard), *Immer kompakt* oder *Immer Standard*.
Titel | Eigener Titel. Leer: der Name des Energierechner 2.

## 2. Funktionen

`void ERK_Refresh(integer $InstanzID);`
Holt die Daten der Quelle sofort neu. Sonst geschieht das bei jeder Berechnung der Quelle, spätestens nach einer Minute.

`string ERK_GetTileData(integer $InstanzID);`
Liefert die Kacheldaten als JSON.

## 3. Hinweise

- Die Anzeige sieht aus wie die Kachel des Energierechner 2, aber ohne Chips und Pfeile, weil der Zeitraum fest ist. Ein Tippen auf den Hauptwert öffnet in der Standard-Darstellung die Details. In der kompakten Darstellung gibt es keine Details.
- Vorjahresvergleich und teuerster Tag stehen nur dann in der Anzeige, wenn die Quelle sie berechnet. Das tut sie, wenn ihre Einstellung *Zusatzwerte* eingeschaltet ist.
- Die Anzeige rechnet nie selbst und stößt auch keine Berechnung der Quelle an. Hat die Quelle noch nichts berechnet oder ist der gewählte Zeitraum dort nicht aktiviert, zeigt die Kachel einen Hinweis. Dann in der Quelle den Zeitraum aktivieren oder eine Berechnung starten.

## 4. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a> <a href="https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share" target="_blank">Amazon Wunschzettel</a>

## 5. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
