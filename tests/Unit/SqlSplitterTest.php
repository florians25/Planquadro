<?php
namespace Planquadro\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/lib/SqlSplitter.php';

final class SqlSplitterTest extends TestCase
{
    public function testSplitsOnSemicolonOutsideStringsAndComments(): void
    {
        $sql = "INSERT INTO t VALUES('a;b', \"c;d\");\n-- Kommentar; mit Semikolon\nUPDATE t SET x='it''s; ok' /* ; */ WHERE 1;\n# auch;\nSELECT 1";
        $this->assertSame([
            "INSERT INTO t VALUES('a;b', \"c;d\")",
            "UPDATE t SET x='it''s; ok'  WHERE 1",
            'SELECT 1',
        ], \SqlSplitter::split($sql));
    }

    public function testHandlesDelimiterBlocks(): void
    {
        $sql = "DELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END//\nDELIMITER ;\nCALL p();";
        $this->assertSame(['CREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END', 'CALL p()'], \SqlSplitter::split($sql));
    }

    public function testKeepsVersionComments(): void
    {
        $this->assertSame(['/*!40101 SET NAMES utf8mb4 */'], \SqlSplitter::split("/*!40101 SET NAMES utf8mb4 */;"));
    }

    public function testBackslashEscapes(): void
    {
        $this->assertSame(["SELECT 'a\\';b'", 'SELECT 2'], \SqlSplitter::split("SELECT 'a\\';b'; SELECT 2;"));
    }

    public function testAllMigrationFilesSplitCleanly(): void
    {
        $files = array_merge(glob(__DIR__ . '/../../migrations/*.sql'), glob(__DIR__ . '/../../migrations/app/*.sql'));
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            foreach (\SqlSplitter::split(file_get_contents($f)) as $stmt) {
                $this->assertDoesNotMatchRegularExpression('/^\s*DELIMITER/i', $stmt, basename($f));
            }
        }
    }
}
