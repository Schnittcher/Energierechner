# Energierechner
   Dieses IP-Symcon Modul ermöglicht eine Berechnung der gesamten Verbrauchskosten über mehrere Zeiträume mit unterschiedlichen Arbeitspreisen.
     
   ## Inhaltverzeichnis
   1. [Konfiguration](#1-konfiguration)
   2. [Funktionen](#2-funktionen)
   
   ## 1. Konfiguration
   
   Feld | Beschreibung
   ------------ | ----------------
   Aktiv | Mit dieser CheckBox kann die Instanz in- bzw. aktiv geschaltet werden. 
   Verbrauchsvariable | Hier wird die Variable angegeben, welche den gesamten Verbrauch enthält, diese Variable muss als Zäher geloggt sein.
   Täglich |Verbrauchs- und Kostenstatistik Täglich
   Vortag | Verbrauchs- und Kostenstatistik Vortag
   Vorwoche | Verbrauchs- und Kostenstatistik Vorwoche
   Aktueller Monat | Verbrauchs- und Kostenstatistik aktueller Monat
   Letzter Monat | Verbrauchs- und Kostenstatistik letzter Monat
   Aktualisierungsintervall | Intervall, wie oft die Werte aktualisiert werden sollen.

   **Experteneinstellungen** 
   Feld | Beschreibung
   ------------ | ----------------
   Impulse/kWh Berechnung | Hier kann angegeben werden, wie viele Impulse eine kWh sind, falls die Zählervariable von einem Impulsezähler ist.
      
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