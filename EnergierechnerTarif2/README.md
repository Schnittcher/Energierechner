# Energierechner Tarif 2

Hält die Tarifabschnitte für einen oder mehrere [Energierechner 2](../Energierechner2/README.md). Ein Tarifabschnitt gilt ab seinem Datum bis zum nächsten Eintrag.

## Wie Tarifzeilen gelten

- Eine Zeile gilt **ab ihrem Datum bis zum Beginn der nächsten Zeile**. Dieser Tag gehört schon zum neuen Tarif.
- Die **letzte Zeile** hat kein Ende und gilt für alle späteren Tage weiter, auch in der Zukunft. Soll ein Tarif enden, trägst du die Zeile des Folgetarifs ein. Ein Enddatum gibt es nicht, also auch keine Lücken zwischen Tarifen.
- Die **Reihenfolge in der Liste** ist egal, sortiert wird nach „Gültig ab".
- **Vor der ersten Zeile** gibt es keinen Tarif. Verbrauch aus dieser Zeit hat dann keinen Preis und keinen Grundpreis, und der Energierechner zeigt eine Warnung.
- Zwei Zeilen mit **demselben Datum** sind nicht möglich: Die zweite wird verworfen.
- Preis, Grundpreis, Nachtfenster, Abschlag und Gaswerte wechseln **gemeinsam** mit der Zeile.
- Mehrere Energierechner können sich einen Tarif teilen. Ändert sich der Tarif, rechnen alle neu.

### Statusmeldungen

| Status | Bedeutung |
|---|---|
| Aktiv | Mindestens eine Zeile ist eingetragen. |
| Keine Tarifzeiträume angelegt (201) | Die Liste ist leer. Ein verbundener Energierechner zeigt dann seinerseits „kein Tarif". |

## Einstellungen

| Spalte | Beschreibung |
|---|---|
| Name | Frei wählbar, erscheint im Namen der Variablen (leer = Datum). |
| Anbieter | Frei wählbar, nur zur Anzeige. Wird aus dem Feld „Stromanbieter" des alten Tarifs übernommen. |
| Gültig ab | Beginn des Tarifs. |
| Preis (Tag) | Arbeitspreis je Einheit des Zählers (kWh, m³ oder Liter). |
| Preis (Nacht) | Arbeitspreis in der Nachtzeit. 0 verwendet den Tagespreis. |
| Preisvariable (dynamisch) | Optional. Eine Variable mit dem aktuellen Preis, z. B. aus Tibber oder aWATTar. Sie muss im Archiv mit dem Aggregationstyp *Standard* geloggt werden. Siehe [Dynamische Preise](#dynamische-preise). |
| Einheit der Preisvariable | €/kWh (bzw. €/m³, €/L), ct/kWh oder €/MWh. Rechnet den Wert der Variable in Euro je Einheit um. |
| Aufschlag je Einheit | Fester Betrag, der auf den dynamischen Preis aufgeschlagen wird (z. B. Netzentgelte, Steuern, Marge des Anbieters). |
| Preisauflösung | 1 Stunde oder 15 Minuten, je nachdem, in welchem Takt der Preis wechselt. |
| Nacht von / bis | Nachtfenster. Beide Zeiten gleich lassen (z. B. 00:00), wenn es keinen Nachttarif gibt. Das Fenster darf über Mitternacht gehen (22:00 bis 06:00). |
| Grundpreis pro Jahr | Wird je Tag mit 1/365 (Schaltjahr 1/366) gerechnet. |
| Abschlag / Zahlungen pro Jahr | Grundlage für den Saldo. |
| Gas-Umrechnungsfaktor / Zustandszahl / Brennwert | Rechnen m³ in kWh um. Das Produkt der drei Werte ergibt die kWh je m³. |

## Dynamische Preise

Ist bei einer Tarifzeile eine **Preisvariable** gewählt, kommt der Arbeitspreis nicht aus der Spalte „Preis (Tag)", sondern aus dem Archiv dieser Variable:

```
Preis = Wert der Preisvariable × Einheitenfaktor + Aufschlag
```

- Der Verbrauch wird mit dem Preis bewertet, der in seinem Zeitraum galt. Wechselt der Preis innerhalb eines Verbrauchsintervalls (z. B. Verbrauch je Stunde, Preis je Viertelstunde), wird der Verbrauch anteilig aufgeteilt.
- **Preis (Tag)** dient als Ausweichwert, wenn das Archiv für ein Intervall keinen Preis hat. Dazu erscheint eine Warnung im Energierechner. Dort kannst du z. B. einen Durchschnittspreis eintragen.
- **Preis (Nacht)** wird bei dynamischem Preis nur als Ausweichwert in der Nachtzeit benutzt. Das Nachtfenster teilt weiterhin Verbrauch und Kosten in Tag und Nacht auf.
- **Grundpreis, Abschlag und Gaswerte** gelten wie bei festen Tarifen.
- Preise und Verbrauch werden mit Stunden- oder Viertelstundenwerten gelesen, nie mit Tageswerten. Das macht die Berechnung etwas aufwendiger als bei festen Preisen.
- Die Preisvariable muss **geloggt** sein, und das Archiv sollte die Preise **nicht verdichten oder löschen**, solange du Zeiträume daraus berechnen willst.
- Man kann feste und dynamische Tarife mischen: Jede Zeile entscheidet selbst, ob sie eine Preisvariable hat.

## Namen und Ids

Jede Zeile bekommt automatisch eine stabile, versteckte **Id**. Sie ist Teil der Idents der Variablen im Energierechner und bleibt gleich, wenn du Datum oder Preise änderst.

Der **Name** der Zeile geht in den Namen der Variablen im Energierechner ein, aber **nur beim Anlegen** der Variable. Benennst du eine Zeile später um, behalten die vorhandenen Variablen ihren Namen. Wird eine Zeile gelöscht, werden ihre Variablen im Energierechner gelöscht. Eine gelöschte und neu angelegte Zeile bekommt eine neue Id. Ändere deshalb besser die bestehende Zeile (Details im README des Energierechners).

## Funktionen

```php
ER2T_GetTariff(int $InstanceID): string
```
Liefert die Tarifabschnitte als JSON, aufsteigend nach Gültigkeitsbeginn.

Ändert sich ein Tarif, rechnen die verbundenen Energierechner automatisch neu.
