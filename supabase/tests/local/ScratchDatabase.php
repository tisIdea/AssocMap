<?php

declare(strict_types=1);

final class ScratchDatabase
{
    public readonly array $run;
    public readonly string $directory;
    public readonly PDO $pdo;

    public function __construct(string $manifest)
    {
        $path = realpath($manifest);
        if ($path === false || ! preg_match('~^C:\\\\dev\\\\\.codex-tmp\\\\assocmap-pg-([0-9]{8}_[a-f0-9]{8})\\\\run\.json$~i', $path, $match)) {
            throw new RuntimeException('Only this workflow\'s explicit disposable manifest is accepted.');
        }
        $this->directory = dirname($path);
        $this->run = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
        if (! is_int($this->run['Port'] ?? null) || $this->run['Port'] < 1024 || $this->run['Port'] > 65535) {
            throw new RuntimeException('Scratch port must be an integer between 1024 and 65535.');
        }
        $data = realpath($this->directory.'/data');
        if ($data === false || ! is_dir($data) || strcasecmp(dirname($data), $this->directory) !== 0
            || ! is_string($this->run['Data'] ?? null) || realpath($this->run['Data']) !== $data) {
            throw new RuntimeException('Scratch data directory must resolve inside the verified run directory.');
        }
        if ($this->run['Host'] !== '127.0.0.1' || $this->run['Database'] !== 'assocmap_gis_test_'.$match[1]
            || $this->run['User'] !== 'assocmap_test_owner') {
            throw new RuntimeException('Scratch manifest identity mismatch.');
        }
        $pid = file($this->directory.'/data/postmaster.pid', FILE_IGNORE_NEW_LINES);
        if ((int) $pid[3] !== (int) $this->run['Port'] || $pid[5] !== '127.0.0.1' || trim($pid[7]) !== 'ready') {
            throw new RuntimeException('Scratch server is not ready at the explicit loopback port.');
        }
        foreach (['PGHOST','PGHOSTADDR','PGPORT','PGDATABASE','PGUSER','PGPASSWORD','PGSERVICE','PGSERVICEFILE',
            'PGOPTIONS','PGPASSFILE','PGSSLMODE','PGSSLROOTCERT','PGSSLCERT','PGSSLKEY','PGCONNECT_TIMEOUT'] as $name) putenv($name);
        putenv('PGPASSFILE='.$this->directory.'/pgpass.conf');
        putenv('PGCONNECT_TIMEOUT=5');
        putenv('PGSSLMODE=disable');
        $this->pdo = $this->connect();
        $this->pdo->exec("SET statement_timeout='15s'; SET lock_timeout='5s'; SET idle_in_transaction_session_timeout='60s'");
        $this->pdo->exec("SELECT set_config('assocmap.expected_database', ".$this->pdo->quote($this->run['Database']).", false),
            set_config('assocmap.expected_port', '".(int)$this->run['Port']."', false), set_config('assocmap.synthetic_only','yes',false)");
    }

    public function password(): string
    {
        // Newly generated local-only credential; never use project configuration.
        return trim(file_get_contents($this->directory.'/bootstrap-password.txt'));
    }

    public function connect(): PDO
    {
        $pdo = new PDO('pgsql:host=127.0.0.1;hostaddr=127.0.0.1;port='.(int) $this->run['Port'].';dbname='.$this->run['Database'].";sslmode=disable;options=''",
            $this->run['User'], $this->password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $row = $pdo->query("SELECT current_database() AS db, current_user AS actor, host(inet_server_addr()) AS host,
            inet_server_port() AS port, current_setting('data_directory') AS data")->fetch(PDO::FETCH_ASSOC);
        if ($row['db'] !== $this->run['Database'] || $row['actor'] !== $this->run['User'] || $row['host'] !== '127.0.0.1'
            || (int) $row['port'] !== (int) $this->run['Port'] || realpath($row['data']) !== realpath($this->run['Data'])) {
            throw new RuntimeException('Connected server/database does not match the newly created private cluster.');
        }
        return $pdo;
    }

    public function asyncConnection(): PgSql\Connection
    {
        $connection = pg_connect('host=127.0.0.1 hostaddr=127.0.0.1 port='.(int) $this->run['Port'].' dbname='.$this->run['Database']
            .' user='.$this->run['User']." passfile='".str_replace('\\','/',$this->directory)."/pgpass.conf' options='' sslmode=disable connect_timeout=5", PGSQL_CONNECT_FORCE_NEW);
        if ($connection === false) throw new RuntimeException('Cannot connect to verified scratch server.');
        $row = pg_fetch_assoc(pg_query($connection, "SELECT current_database() AS db, host(inet_server_addr()) AS host, inet_server_port() AS port, current_setting('data_directory') AS data"));
        if ($row['db'] !== $this->run['Database'] || $row['host'] !== '127.0.0.1' || (int) $row['port'] !== (int) $this->run['Port']
            || realpath($row['data']) !== realpath($this->run['Data'])) throw new RuntimeException('Async connection isolation mismatch.');
        pg_query($connection, "SET statement_timeout='15s'; SET lock_timeout='5s'");
        return $connection;
    }
}
