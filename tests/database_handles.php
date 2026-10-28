<?php
require __DIR__ . '/helpers.php';

class HandleStatement extends PDOStatement
{
    public static $beforeExecute;
    public static $closed = 0;
    protected function __construct() { }
    public function execute($params = null): bool
    {
        if (self::$beforeExecute) {
            $callback = self::$beforeExecute; self::$beforeExecute = null;
            $callback();
        }
        return parent::execute($params);
    }
    public function closeCursor(): bool { self::$closed++; return parent::closeCursor(); }
}
class HandleConnection extends PDO
{
    public $prepared = [];
    public function prepare($sql, $options = []): PDOStatement
    {
        $this->prepared[$sql] = isset($this->prepared[$sql]) ? $this->prepared[$sql] + 1 : 1;
        return parent::prepare($sql, $options);
    }
}
function handleConnection()
{
    return new HandleConnection(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STATEMENT_CLASS => [HandleStatement::class]
    ]);
}
$connection = new ReflectionProperty(Agent\Database::class, 'connection');
if (PHP_VERSION_ID < 80100) $connection->setAccessible(true);
$original = Agent\Database::connection(); $db = handleConnection(); $connection->setValue(null, $db);
$state = null; $thread = null;
try {
    $sql = 'SELECT ?::integer AS value'; $closed = HandleStatement::$closed;
    $current = true;
    for ($i = 0; $i < 100; $i++) $current = Agent\Database::row($sql, [$i])['value'] === $i && $current;
    check($current, '100 reused reads execute current parameters');
    check($db->prepared[$sql] === 1 && HandleStatement::$closed - $closed === 100, '100 reads prepare once and close every cursor');
    $many = 'SELECT generate_series(1, 3) AS value';
    check(count(Agent\Database::rows($many)) === 3 && Agent\Database::row($many)['value'] === 1, 'Materialized rows and partial reads safely share a handle');
    $nested = null;
    HandleStatement::$beforeExecute = function () use ($sql, &$nested) { $nested = Agent\Database::row($sql, [321]); };
    check(Agent\Database::row($sql, [123])['value'] === 123 && $nested['value'] === 321, 'Reentrant SQL uses independent active handles');
    check($db->prepared[$sql] === 2 && Agent\Database::row($sql, [9])['value'] === 9 && $db->prepared[$sql] === 2, 'Reentrant temporary handle does not replace the reusable handle');

    $db->exec('CREATE TEMP TABLE handle_fixture (id integer PRIMARY KEY, value text)');
    $insert = 'INSERT INTO handle_fixture VALUES (?, ?)';
    check(Agent\Database::execute($insert, [1, 'first']) === 1, 'Execute returns affected row count');
    $read = 'SELECT value FROM handle_fixture WHERE id = ?';
    check(Agent\Database::row($read, [1])['value'] === 'first', 'Initial value is read from database');
    $db->beginTransaction();
    Agent\Database::execute('UPDATE handle_fixture SET value = ? WHERE id = ?', ['transaction', 1]);
    check(Agent\Database::row($read, [1])['value'] === 'transaction', 'Reused reads see current transaction changes');
    $db->rollBack();
    check(Agent\Database::row($read, [1])['value'] === 'first', 'Reused reads honor rollback');
    $db->beginTransaction();
    expectException(function () use ($insert) { Agent\Database::execute($insert, [1, 'duplicate']); }, 'duplicate key');
    $db->rollBack();
    check(Agent\Database::execute($insert, [2, 'after error']) === 1 && $db->prepared[$insert] === 1, 'Failed execute is reusable after rollback');
    Agent\Database::execute('DELETE FROM handle_fixture WHERE id = ?', [1]);
    check(Agent\Database::row($read, [1]) === false, 'Deleted rows never survive in reused handles');

    $state = Agent\State::open('fixture.handles', bin2hex(random_bytes(8)), ['value' => 1]);
    $state->read(); $state->patch(['value' => 2]);
    check($state->read()['value']['value'] === 2, 'State reads show changed values with prepared handle reuse');
    Agent\Database::execute('DELETE FROM states WHERE id = ?', [$state->id]);
    expectException(function () use ($state) { $state->read(); }, 'State no longer exists');

    $alice = Agent\Identity::authenticate('alice', 'phillip-demo');
    $thread = Agent\Thread::create($alice['id'], 'Handle reuse fixture', ['manual' => true]);
    $sources = [];
    for ($i = 0; $i < 3; $i++) $sources[] = Agent\Database::row("INSERT INTO messages (thread_id, author_id, role, raw) VALUES (?, ?, 'user', ?::jsonb) RETURNING id", [$thread->id, $alice['id'], json_encode(['role' => 'user', 'content' => 'source ' . $i])])['id'];
    $db->prepared = [];
    Agent\Knowledge::remember($thread->id, $alice['id'], 'three sources', 'Fixture subject', $sources, 'uses', 'Fixture object');
    check(array_sum($db->prepared) === 6, 'Three-source graph memory prepares six statements rather than eleven');
    $db->prepared = [];
    Agent\Knowledge::remember($thread->id, $alice['id'], 'three sources', 'Fixture subject', $sources);
    check(array_sum($db->prepared) === 3, 'Existing three-source memory prepares three statements rather than seven');
    $thread->delete(); $thread = null;

    $db->beginTransaction(); $db->prepared = [];
    $models = [];
    for ($i = 0; $i < 3; $i++) $models[] = ['model' => 'handles-' . $i, 'status' => 'loaded', 'metadata' => []];
    (new Agent\Registry($db))->record(['url' => 'http://handles-fixture.invalid', 'engine' => 'fixture', 'status' => 'online', 'metadata' => []], $models);
    check(array_sum($db->prepared) === 4, 'Three-model registry scan prepares four statements rather than eight');
    $db->rollBack();

    for ($i = 0; $i < 40; $i++) Agent\Database::row('SELECT ' . $i . ' AS bounded');
    $cache = new ReflectionProperty(Agent\Database::class, 'statements');
    if (PHP_VERSION_ID < 80100) $cache->setAccessible(true);
    check(count($cache->getValue()) === 32, 'Long-running workers retain at most 32 prepared handles');
    $other = handleConnection(); $connection->setValue(null, $other);
    check(Agent\Database::row($sql, [7])['value'] === 7 && $other->prepared[$sql] === 1 && count($cache->getValue()) === 1, 'Changing PDO identity discards all handles from the previous connection');
} finally {
    HandleStatement::$beforeExecute = null;
    $connection->setValue(null, $db);
    if ($db->inTransaction()) $db->rollBack();
    if ($state) Agent\Database::execute('DELETE FROM states WHERE id = ?', [$state->id]);
    if ($thread) $thread->delete();
    $connection->setValue(null, $original);
}
