# Energierechner
   Dieses IP-Symcon Modul ermöglicht eine Berechnung der gesamten Verbrauchskosten über mehrere Zeiträume mit unterschiedlichen Arbeitspreisen.
     
   ## Inhaltverzeichnis
   1. [Konfiguration](#1-konfiguration)
   2. [Funktionen](#2-funktionen)
   
   ## 1. Konfiguration
   
   Feld | Beschreibung
   ------------ | ----------------
   Verbrauchsvariable | Hier wird die Variable angegeben, welche den gesamten Verbrauch enthält.
   Preise | Hier wird für die jeweiligen Verbrauchszeiträume der Arbeitspreis als Variable hinterlegt.
   
   ## 2. Funktionen

   ```php
   ER_updateCalculation($InstanceID);
   ```
   Mit dieser Funktion kann die Berechnung angestoßen werden.

   **Beispiel:**
   
   InstanzID Status: 12345
   ```php
   ER_updateCalculation(12345);
   ```