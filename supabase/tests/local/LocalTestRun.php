<?php

declare(strict_types=1);

final class LocalTestRun
{
    public array $results = [];
    private int $executed = 0;
    public function __construct(private string $report, private string $filter = '')
    {
        if (is_file($report)) $this->results = json_decode(file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
    }

    public function check(string $name, callable $test): void
    {
        if ($this->filter !== '' && ! str_contains($name, $this->filter)) return;
        $this->executed++;
        $this->results = array_values(array_filter($this->results, fn ($row) => $row['test'] !== $name));
        try {
            $evidence = $test();
            $this->results[] = ['test'=>$name,'status'=>'PASS','evidence'=>$evidence];
            echo 'PASS '.$name.PHP_EOL;
        } catch (Throwable $error) {
            $this->results[] = ['test'=>$name,'status'=>'FAIL','error'=>$error->getMessage(),'location'=>$error->getFile().':'.$error->getLine()];
            echo 'FAIL '.$name.': '.strtok($error->getMessage(), "\n").PHP_EOL;
        }
        file_put_contents($this->report, json_encode($this->results, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }

    public static function assert(bool $condition, string $message): void
    {
        if (! $condition) throw new RuntimeException($message);
    }

    public static function sqlState(PDO $pdo, string $sql, array $expected = ['23514'], ?string $messageContains = null): string
    {
        $pdo->exec('SAVEPOINT expected_rejection');
        try {
            $pdo->exec($sql);
        } catch (PDOException $error) {
            $pdo->exec('ROLLBACK TO SAVEPOINT expected_rejection');
            $pdo->exec('RELEASE SAVEPOINT expected_rejection');
            self::assert(in_array($error->getCode(), $expected, true), 'Unexpected SQLSTATE '.$error->getCode().': '.$error->getMessage());
            if ($messageContains !== null) self::assert(str_contains($error->getMessage(), $messageContains), 'Expected a specific integrity failure: '.$error->getMessage());
            return $error->getCode();
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT expected_rejection');
        $pdo->exec('RELEASE SAVEPOINT expected_rejection');
        throw new RuntimeException('Expected rejection but SQL succeeded: '.$sql);
    }

    public static function rollback(PDO $pdo, callable $test): mixed
    {
        $pdo->beginTransaction();
        try { return $test(); }
        finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }

    public static function appRollback(callable $test): mixed
    {
        Illuminate\Support\Facades\DB::beginTransaction();
        try { return $test(); }
        finally { Illuminate\Support\Facades\DB::rollBack(); }
    }

    public static function denied(callable $test): void
    {
        try { $test(); }
        catch (Illuminate\Validation\ValidationException $error) { return; }
        catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
            self::assert(in_array($error->getStatusCode(), [403,404,409,422], true), 'Unexpected application denial status.');
            return;
        }
        throw new RuntimeException('Application mutation unexpectedly succeeded.');
    }

    public function finish(): never
    {
        $failures = count(array_filter($this->results, fn ($row) => $row['status'] === 'FAIL'));
        echo count($this->results).' checks, '.$failures.' failures, '.$this->executed.' executed this invocation; '.$this->report.PHP_EOL;
        exit($failures === 0 && $this->executed > 0 ? 0 : 1);
    }
}
