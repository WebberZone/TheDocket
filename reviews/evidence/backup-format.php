<?php
require '/app/vendor/autoload.php';
$db = new PDO('sqlite:/tmp/docket-review-source.sqlite');
$db->exec('CREATE TABLE IF NOT EXISTS probe (value TEXT)');
$db->exec("INSERT INTO probe VALUES ('review fixture')");
\Spatie\DbDumper\Databases\Sqlite::create()->setDbName('/tmp/docket-review-source.sqlite')->dumpToFile('/tmp/docket-review-dump.sql');
echo 'Dump prefix: ', substr(file_get_contents('/tmp/docket-review-dump.sql'), 0, 70), PHP_EOL;
try {
    $copy = new PDO('sqlite:/tmp/docket-review-dump.sql');
    $copy->query('SELECT * FROM probe');
} catch (Throwable $e) {
    echo 'Using dump as database: ', $e->getMessage(), PHP_EOL;
}
