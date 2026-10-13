<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Storage;

use PhpMiniSearch\Storage\Log;
use PhpMiniSearch\Storage\LogFormat;
use PhpMiniSearch\Storage\LogScan;
use PhpMiniSearch\Storage\LogScanner;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mini-search-log-' . bin2hex(random_bytes(6));
        $this->path = $this->directory . '/search.log';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAFirstStartCreatesAnEmptyLog(): void
    {
        $log = Log::open($this->path, fn () => null);
        $log->close();

        $this->assertSame(LogScan::MISSING, $log->recovery()->status);
        $this->assertSame(LogFormat::header(), file_get_contents($this->path));
    }

    public function testRecordsComeBackInOrder(): void
    {
        $this->write([['op' => 'put', 'id' => 'a'], ['op' => 'delete', 'id' => 'кабель-1']]);

        $this->assertSame([['op' => 'put', 'id' => 'a'], ['op' => 'delete', 'id' => 'кабель-1']], $this->replay()[0]);
        $this->assertSame(LogScan::CLEAN, $this->replay()[1]->status);
    }

    /**
     * A crash can stop the last write at any byte. Wherever it stops, the
     * earlier records survive, the torn record is gone, and the log accepts
     * new records again.
     */
    public function testATornLastRecordIsCutOffAtEveryPossibleByte(): void
    {
        $this->write([['op' => 'put', 'id' => 'a'], ['op' => 'put', 'id' => 'b']]);
        $complete = (string) file_get_contents($this->path);
        $last = strlen(LogFormat::frame(['op' => 'put', 'id' => 'b']));
        $lastStart = strlen($complete) - $last;

        for ($cut = $lastStart + 1; $cut < strlen($complete); $cut++) {
            file_put_contents($this->path, substr($complete, 0, $cut));

            [$records, $scan] = $this->replay();
            $this->assertSame(LogScan::TORN_TAIL, $scan->status, "cut at byte {$cut}");
            $this->assertSame([['op' => 'put', 'id' => 'a']], $records, "cut at byte {$cut}");
            $this->assertSame($lastStart, filesize($this->path), "cut at byte {$cut}");

            $this->write([['op' => 'put', 'id' => 'c']]);
            $this->assertSame([['op' => 'put', 'id' => 'a'], ['op' => 'put', 'id' => 'c']], $this->replay()[0], "cut at byte {$cut}");
        }
    }

    public function testALastRecordWithABadChecksumIsATornTail(): void
    {
        $this->write([['op' => 'put', 'id' => 'a'], ['op' => 'put', 'id' => 'b']]);
        $this->flipLastByte();

        [$records, $scan] = $this->replay();

        $this->assertSame(LogScan::TORN_TAIL, $scan->status);
        $this->assertSame([['op' => 'put', 'id' => 'a']], $records);
    }

    public function testADamagedBodyInTheMiddleIsKeptAsideAndTheLogStartsOver(): void
    {
        $this->write([['op' => 'put', 'id' => 'a'], ['op' => 'put', 'id' => 'b'], ['op' => 'put', 'id' => 'c']]);
        $bodyOfA = strlen(LogFormat::header()) + LogFormat::FRAME_HEADER + 3;
        $this->corruptByteAt($bodyOfA);

        $log = Log::open($this->path, fn () => null);
        $log->close();

        $this->assertSame(LogScan::DAMAGED, $log->recovery()->status);
        $this->assertNotNull($log->damagedCopy());
        $this->assertFileExists($log->damagedCopy());
        $this->assertSame(LogFormat::header(), file_get_contents($this->path));
    }

    /**
     * A damaged length field can point past the end of the file. Taken for a
     * torn tail, it would cut off every acknowledged record after it.
     */
    public function testADamagedLengthFieldIsDamageNotATornTail(): void
    {
        $this->write([['op' => 'put', 'id' => 'a'], ['op' => 'put', 'id' => 'b']]);
        $lengthOfA = strlen(LogFormat::header());
        $this->corruptByteAt($lengthOfA);

        $scan = LogScanner::scan($this->path, fn () => null);

        $this->assertSame(LogScan::DAMAGED, $scan->status);
    }

    public function testAHeaderCutOffWhileBeingCreatedCountsAsNoLog(): void
    {
        mkdir($this->directory);
        file_put_contents($this->path, substr(LogFormat::header(), 0, 3));

        $log = Log::open($this->path, fn () => null);
        $log->close();

        $this->assertSame(LogScan::MISSING, $log->recovery()->status);
        $this->assertSame(LogFormat::header(), file_get_contents($this->path));
    }

    public function testAFileThatIsNotALogIsDamage(): void
    {
        mkdir($this->directory);
        file_put_contents($this->path, 'hello, this is not a log');

        $this->assertSame(LogScan::DAMAGED, LogScanner::scan($this->path, fn () => null)->status);
    }

    public function testScanningNeverChangesTheFile(): void
    {
        $this->write([['op' => 'put', 'id' => 'a'], ['op' => 'put', 'id' => 'b']]);
        file_put_contents($this->path, substr((string) file_get_contents($this->path), 0, -2));
        $before = file_get_contents($this->path);

        LogScanner::scan($this->path, fn () => null);

        $this->assertSame($before, file_get_contents($this->path));
    }

    public function testRewriteReplacesTheWholeLog(): void
    {
        $this->write([['op' => 'put', 'id' => 'a'], ['op' => 'put', 'id' => 'a'], ['op' => 'delete', 'id' => 'a']]);

        Log::rewrite($this->path, [['op' => 'checkpoint'], ['op' => 'delete', 'id' => 'a']]);

        $this->assertSame([['op' => 'checkpoint'], ['op' => 'delete', 'id' => 'a']], $this->replay()[0]);
        $this->assertFileDoesNotExist($this->path . '.compact');
    }

    /**
     * A crash before the rename leaves the old log whole and a stray
     * temporary file, which the next rewrite simply overwrites.
     */
    public function testAnInterruptedRewriteLeavesTheOldLogWhole(): void
    {
        $this->write([['op' => 'put', 'id' => 'a']]);
        file_put_contents($this->path . '.compact', LogFormat::header() . 'half a rec');

        $this->assertSame([['op' => 'put', 'id' => 'a']], $this->replay()[0]);

        Log::rewrite($this->path, [['op' => 'put', 'id' => 'b']]);
        $this->assertSame([['op' => 'put', 'id' => 'b']], $this->replay()[0]);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function write(array $records): void
    {
        $log = Log::open($this->path, fn () => null);
        foreach ($records as $record) {
            $log->append($record);
        }
        $log->close();
    }

    /**
     * Opens the log the way the service does, and returns what it replayed.
     *
     * @return array{list<array<string, mixed>>, LogScan}
     */
    private function replay(): array
    {
        $records = [];
        $log = Log::open($this->path, function (array $record) use (&$records): void {
            $records[] = $record;
        });
        $log->close();

        return [$records, $log->recovery()];
    }

    private function corruptByteAt(int $offset): void
    {
        $bytes = (string) file_get_contents($this->path);
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0xFF);
        file_put_contents($this->path, $bytes);
    }

    private function flipLastByte(): void
    {
        $this->corruptByteAt((int) filesize($this->path) - 1);
    }
}
