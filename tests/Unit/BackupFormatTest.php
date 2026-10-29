<?php
namespace Planquadro\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/Backup.php';

/** GFS-Aufbewahrung, Archivnamen, tar, Zeilenzähler, S3-Signatur. */
final class BackupFormatTest extends TestCase
{
    private static function at(string $d): int { return (new \DateTimeImmutable($d, new \DateTimeZone('UTC')))->getTimestamp(); }

    public function testGfsRetentionKeepsDailyWeeklyMonthly(): void
    {
        /* 120 Tage lang je ein Backup um 02:30, zusätzlich zwei manuelle am letzten Tag */
        $items = [];
        $end = self::at('2026-10-29 02:30');
        for ($i = 0; $i < 120; $i++) $items["d$i"] = $end - $i * 86400;
        $items['m1'] = $end + 3600;
        $items['m2'] = $end + 7200;
        $keep = \BackupRetention::keep($items, 7, 4, 12, 1);
        $keepSet = array_flip($keep);
        /* jüngstes immer; täglich: 7 verschiedene Tage, am letzten Tag das jüngste (m2) */
        $this->assertArrayHasKey('m2', $keepSet);
        $this->assertArrayNotHasKey('m1', $keepSet);
        $this->assertArrayNotHasKey('d0', $keepSet, 'pro Tag nur das jüngste');
        foreach (range(1, 6) as $i) $this->assertArrayHasKey("d$i", $keepSet);
        /* wöchentlich: jüngstes je ISO-Woche, 4 Wochen; monatlich: jüngstes je Monat, bis 12 */
        $weeks = []; $months = [];
        foreach ($keep as $k) { $weeks[gmdate('o-W', $items[$k])] = 1; $months[gmdate('Y-m', $items[$k])] = 1; }
        $this->assertGreaterThanOrEqual(4, count($weeks));
        $this->assertCount(4, $months, 'Juli bis Oktober');
        $this->assertArrayHasKey('d90', $keepSet, 'Juli: jüngstes Backup des Monats (31.07.)');
        $this->assertArrayHasKey('d29', $keepSet, 'September: 30.09.');
        $this->assertArrayNotHasKey('d119', $keepSet, '02.07. ist nicht das jüngste im Juli');
        $this->assertLessThanOrEqual(7 + 4 + 12, count($keep));
        /* Ältere Tagesbackups ohne Wochen-/Monatsfunktion werden gelöscht */
        $this->assertArrayNotHasKey('d20', $keepSet);
    }

    public function testRetentionEdgeCases(): void
    {
        $this->assertSame([], \BackupRetention::keep([], 7, 4, 12));
        $this->assertSame(['a'], \BackupRetention::keep(['a' => 100], 0, 0, 0), 'jüngstes bleibt immer');
        $items = ['a' => self::at('2026-10-29 01:00'), 'b' => self::at('2026-10-29 02:00'), 'c' => self::at('2026-10-29 03:00')];
        $this->assertEqualsCanonicalizing(['b', 'c'], \BackupRetention::keep($items, 1, 0, 0, 2), 'keep_last schützt Backups desselben Tages');
    }

    public function testArchiveNames(): void
    {
        $t = self::at('2026-10-29 02:30:05');
        $n = \Backup::archiveName('acme-gmbh', 'scheduled', $t);
        $this->assertSame('planquadro_acme-gmbh_20261029-023005Z_scheduled.pqbak', $n);
        $this->assertSame(['scope' => 'acme-gmbh', 'time' => $t, 'kind' => 'scheduled'], \Backup::parseName($n));
        $this->assertSame('_platform', \Backup::parseName(\Backup::archiveName('_platform', 'manual', $t))['scope']);
        foreach (['backup_auto_2025.epbak', 'planquadro_x_20261029-023005Z_manual.pqbak.part', '../planquadro_a_b.pqbak', 'planquadro_Acme_20261029-023005Z_manual.pqbak'] as $bad) {
            $this->assertNull(\Backup::parseName($bad), $bad);
        }
    }

    public function testTarRoundTripWithLongNamesAndRejectsTraversal(): void
    {
        $data = '';
        $tar = new \BackupTar(function ($d) use (&$data) { $data .= $d; });
        $long = str_repeat('verzeichnis/', 12) . 'datei mit leerzeichen.txt';
        $tar->addString('manifest.json', '{"a":1}');
        $tar->addDir('logos');
        $tar->addString($long, str_repeat('x', 1500));
        $tar->addString('leer.txt', '');
        $tar->finish();
        $this->assertSame(0, strlen($data) % 512);
        $dir = sys_get_temp_dir() . '/optar' . bin2hex(random_bytes(3));
        $pos = 0;
        $files = \BackupTar::extract(function () use ($data, &$pos) { if ($pos >= strlen($data)) return null; $c = substr($data, $pos, 1000); $pos += 1000; return $c; }, $dir);
        $this->assertSame(['manifest.json' => 7, $long => 1500, 'leer.txt' => 0], $files);
        $this->assertSame(str_repeat('x', 1500), file_get_contents("$dir/$long"));
        \Backup::rmTree($dir);

        /* GNU tar kann das Format lesen (falls vorhanden) */
        if (is_executable('/usr/bin/tar') || is_executable('/bin/tar')) {
            $f = tempnam(sys_get_temp_dir(), 'optar');
            file_put_contents($f, $data);
            exec('tar -tf ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
            unlink($f);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertContains($long, $out);
        }

        $evil = '';
        $t2 = new \BackupTar(function ($d) use (&$evil) { $evil .= $d; });
        $t2->addString('../../etc/x', 'boom');
        $t2->finish();
        $this->expectException(\BackupException::class);
        $done = false;
        \BackupTar::extract(function () use ($evil, &$done) { if ($done) return null; $done = true; return $evil; }, sys_get_temp_dir() . '/optar-evil' . bin2hex(random_bytes(3)));
    }

    public function testRowCounterHandlesBothDumpFormats(): void
    {
        $dump = "CREATE TABLE `a` (\n  `id` int\n);\nCREATE TABLE `leer` (`id` int);\n"
              . "INSERT INTO `a` VALUES (1,'x'),(2,'(),(\\'),(\\\\'),(3,'it''s; (')," . "(4,NULL);\n"
              . "INSERT INTO `b` VALUES\n(1,'a'),\n(2,'b;'),\n(3,'c\\n(');\n"
              . "INSERT INTO `a` (`id`,`t`) VALUES (5,'y');\n-- Dump completed\n";
        $c = new \BackupRowCounter();
        $rest = '';
        foreach (str_split($dump, 7) as $chunk) $rest = $c->feed($rest . $chunk);
        $c->feed($rest . "\n");
        $this->assertSame(['a' => 5, 'leer' => 0, 'b' => 3], $c->tables);
        $this->assertTrue($c->sawEnd);
    }

    public function testDefinerIsStrippedOnlyOutsideData(): void
    {
        $this->assertSame('/*!50003 CREATE*/ /*!50003 TRIGGER t BEFORE INSERT ON x FOR EACH ROW SET @a=1 */;;',
            \BackupSqlLoader::filterLine('/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`%`*/ /*!50003 TRIGGER t BEFORE INSERT ON x FOR EACH ROW SET @a=1 */;;'));
        $this->assertSame('CREATE PROCEDURE `p`() SELECT 1', \BackupSqlLoader::filterLine('CREATE DEFINER=`ops`@`localhost` PROCEDURE `p`() SELECT 1'));
        $data = "INSERT INTO `x` VALUES ('DEFINER=`a`@`b`');";
        $this->assertSame($data, \BackupSqlLoader::filterLine($data));
    }

    /**
     * AWS Signature V4: Referenzwerte mit botocore 1.x (S3SigV4Auth) für dieselben
     * Eingaben erzeugt – Pfad mit Sonderzeichen, Port, Query-Parameter.
     */
    public function testS3SignatureMatchesReferenceImplementation(): void
    {
        $t = new \BackupS3Target('https://s3.example.test:8443', 'eu-central-1', 'bucket-1', 'planquadro', 'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', true, gmmktime(12, 0, 0, 10, 29, 2026));
        $h = $t->sign('PUT', 's3.example.test', '/bucket-1/planquadro/default/a b+c.pqbak', ['x-id' => 'PutObject'], [], hash('sha256', 'hello planquadro'));
        $this->assertSame('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261029/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f6d0cdfb0d5af8b0e6ec3cb4cfd1ccbea34855e918c72262fa2cee5fdf934442', $h['authorization']);
        $this->assertSame('20261029T120000Z', $h['x-amz-date']);
        $h = $t->sign('PUT', 's3.example.test', '/bucket-1/planquadro/default/abc.pqbak', ['x-id' => 'PutObject'], [], hash('sha256', 'hello planquadro'));
        $this->assertStringEndsWith('Signature=527dfa25517c456c2f1eb4d0e8812ea3f6d566f93b4c24e9e621f0f5bd003581', $h['authorization']);
    }
}
