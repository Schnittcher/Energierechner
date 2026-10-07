<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Führt die Prüfungen aus tests/run.php unter PHPUnit aus: Rechenlogik, Tarife, Archivleser, Migration,
 * Kachel-Daten und Preisabfrage. Die Integrationstests gegen das Archiv laufen nur auf einem Symcon-System.
 */
final class RechnerTest extends TestCase
{
    public function testAllePruefungenBestehen(): void
    {
        ob_start();
        require __DIR__ . '/run.php';
        $output = (string) ob_get_clean();

        $this->assertGreaterThan(100, $GLOBALS['er_pass'], 'Es wurden zu wenige Prüfungen ausgeführt');
        $this->assertSame(0, $GLOBALS['er_fail'], $output);
    }
}
