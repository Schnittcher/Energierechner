# Energierechner Kachel

Reine Anzeige-Instanz für die Kachel-Visualisierung (experimentell). Sie zeigt **einen festen Zeitraum** eines [Energierechner 2](../Energierechner2/README.md) und rechnet nichts selbst. So legst du aus einem Energierechner mehrere Kacheln an, zum Beispiel je eine für Heute, Diesen Monat und Dieses Jahr nebeneinander.

### Inhaltsverzeichnis

1. [Voraussetzungen](#1-voraussetzungen)
2. [Einstellungen](#2-einstellungen)
3. [Funktionen](#3-funktionen)
4. [Hinweise](#4-hinweise)

### 1. Voraussetzungen

- IP-Symcon ab Version 8.1 mit Kachel-Visualisierung
- eine Instanz *Energierechner 2*, in der der gewünschte Zeitraum aktiviert ist

### 2. Einstellungen

| Einstellung | Bedeutung |
| --- | --- |
| Energierechner 2 | Die Instanz, deren Werte angezeigt werden. |
| Zeitraum | Heute, Gestern, Diese Woche, Letzte Woche, Dieser Monat, Letzter Monat, Dieses Jahr oder Letztes Jahr. Der Zeitraum muss im Energierechner 2 aktiviert sein, sonst zeigt die Kachel „Keine Zeiträume aktiviert". |
| Darstellung | *Automatisch* (kompakt bei kleinen Kacheln, sonst Standard), *Immer kompakt* oder *Immer Standard*. |
| Titel | Eigener Titel. Leer: der Name des Energierechner 2. |

### 3. Funktionen

```php
ERK_Refresh(int $InstanzID): void       // holt die Daten der Quelle sofort neu
ERK_GetTileData(int $InstanzID): string // die Kacheldaten als JSON
```

### 4. Hinweise

- Die Anzeige sieht aus wie die Kachel des Energierechner 2, aber ohne Chips und Pfeile, weil der Zeitraum fest ist. Ein Tippen auf den Hauptwert öffnet in der Standard-Darstellung die Details. In der kompakten Darstellung gibt es keine Details.
- Die Daten kommen von der Quelle und werden bei jeder Neuberechnung aktualisiert, spätestens nach einer Minute.
- Vorjahresvergleich und teuerster Tag stehen nur dann in der Anzeige, wenn die Quelle sie berechnet. Das tut sie, wenn ihre Einstellung *Zusatzwerte* eingeschaltet ist.
- Hat die Quelle noch nichts berechnet, stößt die Anzeige einmal eine Berechnung an. Rechnet die Quelle absichtlich nicht automatisch (Einstellung *Automatisch berechnen* aus), tut die Anzeige das nicht.
- Die Instanz gehört zur Kachel und lässt sich mit ihr entfernen: den Ordner `EnergierechnerKachel` löschen (siehe [Energierechner 2](../Energierechner2/README.md#kachel-visualisierung-experimentell)).
