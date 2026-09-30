<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testFreshSchemaRecordsInitialMigrationAsApplied(): void
    {
        $this->assertTrue(MigrationRunner::trackingTableExists());
        $status = MigrationRunner::status();
        $byName = array_column($status, null, 'filename');
        $this->assertArrayHasKey('001_initial_schema.sql', $byName);
        $this->assertTrue($byName['001_initial_schema.sql']['applied']);
        $this->assertSame([], MigrationRunner::pendingFilenames(), 'a fresh install has nothing pending');
    }

    public function testFilenamesAreNaturallySortedAndOnlyNumberedSqlFilesCount(): void
    {
        $files = MigrationRunner::allFilenames();
        $this->assertContains('001_initial_schema.sql', $files);
        $this->assertNotContains('README.md', $files);
        $this->assertNotContains('migrate.sh', $files);
        $sorted = $files;
        natsort($sorted);
        $this->assertSame(array_values($sorted), $files);
    }

    public function testSchemaRecordsEveryMigrationAsAlreadyApplied(): void
    {
        // schema.sql is the complete current structure, so a fresh install must
        // mark every db_migrations file as done or Admin -> Migrations would
        // offer to re-run them.
        $schema = (string)file_get_contents(dirname(__DIR__, 3) . '/www/schema.sql');
        $byName = array_column(MigrationRunner::status(), null, 'filename');
        foreach (MigrationRunner::allFilenames() as $file) {
            $this->assertStringContainsString("('" . $file . "')", $schema, "$file must be listed in schema.sql's schema_migrations insert");
            $this->assertTrue($byName[$file]['applied'], "$file must be recorded as applied");
        }
    }

    public function testApplyRequiresAdminAndIgnoresUnknownFiles(): void
    {
        $user = new UserContext(999, false);
        try {
            MigrationRunner::apply(['001_initial_schema.sql'], $user);
            $this->fail('non-admins must not apply migrations');
        } catch (RuntimeException $e) {
            $this->assertSame('Admins only', $e->getMessage());
        }
        $result = MigrationRunner::apply(['../../etc/passwd', '999_nope.sql', '001_initial_schema.sql'], new UserContext(1, true));
        $this->assertSame([], $result['applied']);
        $this->assertSame(['001_initial_schema.sql'], $result['skipped']);
        $this->assertNull($result['failed']);
    }
}
