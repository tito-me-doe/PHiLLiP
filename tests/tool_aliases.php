<?php
namespace AliasAlpha {
/** Fixture whose reflected short alias is Ledger_entry. */
class Ledger
{
    public static $calls = 0;
    /** Append the entry identified by $id to the alpha ledger. */
    public static function entry(string $id): string
    {
        self::$calls++;
        return 'alpha:' . $id;
    }
}
}
namespace AliasBeta {
/** Second Ledger class, same basename/method, deliberately ambiguous. */
class Ledger
{
    public static $calls = 0;
    /** Append the entry identified by $id to the beta ledger. */
    public static function entry(string $id): string
    {
        self::$calls++;
        return 'beta:' . $id;
    }
}
}
namespace AliasReader {
/** Fixture whose unique reflected short alias is Repo_read. */
class Repo
{
    public static $calls = 0;
    /** Read the latest snapshot of $repo. */
    public static function read(string $repo): string
    {
        self::$calls++;
        return 'repo:' . $repo;
    }
}
}
namespace {
/** Root-namespaced tool whose canonical key Ledger_entry collides with a short alias. */
class Ledger
{
    public static $calls = 0;
    /** Touch the direct ledger account $account. */
    public static function entry(string $account): string
    {
        self::$calls++;
        return 'direct:' . $account;
    }
}

require __DIR__ . '/helpers.php';

/****** short alias regression: source() and execute() share one resolution path ******/
$registry = new Agent\ToolRegistry();
$registry->register(AliasAlpha\Ledger::class);
$registry->register(AliasReader\Repo::class);

$canonical = 'AliasAlpha_Ledger_entry';
$registered = $registry->source($canonical);
check($registered === [AliasAlpha\Ledger::class, 'entry'], 'Exact canonical name resolves through source');

$defined = [];
foreach ($registry->definitions() as $definition) $defined[] = $definition['function']['name'];
check(in_array($canonical, $defined, true), 'Exact canonical name stays in definitions');
check(!in_array('Ledger_entry', $defined, true), 'Short aliases are not added to definitions');

$result = $registry->execute(['id' => 'retain-1', 'function' => ['name' => $canonical, 'arguments' => json_encode(['id' => '7'])]]);
check($result['tool_call_id'] === 'retain-1' && $result['content'] === 'alpha:7',
    'Exact canonical executes and retains tool_call_id/provider pairing');

// Unique short alias resolves to the same callable via source and behaves identically via execute.
check($registry->source('Ledger_entry') === $registered, 'Unique short alias Ledger_entry resolves to the registered callable');
$result = $registry->execute(['id' => 'retain-2', 'function' => ['name' => 'Ledger_entry', 'arguments' => json_encode(['id' => '9'])]]);
check($result['tool_call_id'] === 'retain-2' && $result['content'] === 'alpha:9',
    'Unique short alias executes through execute with the retained tool_call_id');

// Unknown aliases stay errors and never execute.
check($registry->source('Workspace_read') === null, 'Nonexistent Workspace_read stays unknown through source');
check($registry->source('Source_list') === null, 'Nonexistent Source_list stays unknown through source');
$result = $registry->execute(['id' => 'ghost', 'function' => ['name' => 'Source_list', 'arguments' => '{}']]);
check($result['_tool_error'] === true && strpos($result['content'], 'Tool error: Unknown tool: Source_list') !== false,
    'Unknown tool execute returns recoverable _tool_error + Unknown tool content, count ' . AliasAlpha\Ledger::$calls);

// Ambiguous shared basename/method pair is rejected wholesale; canonical keys survive.
$ambiguous = new Agent\ToolRegistry();
$ambiguous->register(AliasAlpha\Ledger::class);
$ambiguous->register(AliasBeta\Ledger::class);
check($ambiguous->source('Ledger_entry') === null, 'Ambiguous alias Ledger_entry is rejected by source');
$result = $ambiguous->execute(['id' => 'amb', 'function' => ['name' => 'Ledger_entry', 'arguments' => '{}']]);
check($result['_tool_error'] === true && strpos($result['content'], 'Tool error: Unknown tool: Ledger_entry') !== false,
    'Ambiguous execute returns recoverable _tool_error + Unknown tool content, count ' . AliasAlpha\Ledger::$calls);
$result = $ambiguous->execute(['id' => 'amb-2', 'function' => ['name' => 'AliasBeta_Ledger_entry', 'arguments' => json_encode(['id' => 'b'])]]);
check($result['tool_call_id'] === 'amb-2' && $result['content'] === 'beta:b',
    'Canonical members of an ambiguous alias still execute by exact name');

// Exact-name collision takes precedence over short-alias resolution.
$precise = new Agent\ToolRegistry();
$precise->register(Ledger::class);
$precise->register(AliasAlpha\Ledger::class);
check($precise->source('Ledger_entry') === [Ledger::class, 'entry'],
    'Exact canonical key Ledger_entry beats the short alias of another class');
$result = $precise->execute(['id' => 'prec', 'function' => ['name' => 'Ledger_entry', 'arguments' => json_encode(['account' => '1'])]]);
check($result['tool_call_id'] === 'prec' && $result['content'] === 'direct:1',
    'Precedent exact name executes its own callable and retains tool_call_id');

// before_tool permission gate resolves the alias to the same protected callable.
$guarded = null;
$beforeTool = function ($call, $registry) use (&$guarded) {
    $source = $registry->source($call['function']['name']);
    if ($source === null) throw new \RuntimeException('revoked: unknown tool');
    $class = is_array($source) ? (is_object($source[0]) ? get_class($source[0]) : $source[0]) : null;
    if ($class !== AliasAlpha\Ledger::class) throw new \RuntimeException('revoked: unexpected tool class ' . var_export($class, true));
    $guarded = $source;
};
$beforeTool(['id' => 'bt', 'function' => ['name' => 'Ledger_entry', 'arguments' => '{}']], $registry);
check($guarded === [AliasAlpha\Ledger::class, 'entry'], 'before_tool source resolves the same protected callable via alias');
expectException(function () use ($beforeTool, $registry) {
    $beforeTool(['id' => 'bt-2', 'function' => ['name' => 'Nobody_read', 'arguments' => '{}']], $registry);
}, 'revoked: unknown tool');

// Malformed and malicious names never execute; the target callable is not invoked.
$invoked = AliasAlpha\Ledger::$calls;
foreach ([
    'Ledger_entry;system',
    '../Ledger_entry',
    '.../.../Ledger_entry',
    "\x00Ledger_entry",
    'Ledger::entry',
    ':Ledger_entry',
    '',
    null,
] as $bad) {
    $result = $registry->execute(['id' => 'bad', 'function' => ['name' => $bad, 'arguments' => '{}']]);
    check($result['_tool_error'] === true && strpos($result['content'], 'Tool error: Unknown tool') !== false,
        'Malformed name ' . var_export($bad, true) . ' returns recoverable _tool_error, count ' . AliasAlpha\Ledger::$calls);
    check($registry->source($bad) === null, 'source refuses ' . var_export($bad, true));
}
check(AliasAlpha\Ledger::$calls === $invoked, 'Malformed and malicious names never reach the callable');

$result = $registry->execute(['id' => 'rep', 'function' => ['name' => 'Repo_read', 'arguments' => json_encode(['repo' => 'main'])]]);
check($result['tool_call_id'] === 'rep' && $result['content'] === 'repo:main',
    'Unique short alias Repo_read executes through execute');
check($registry->source('Repo_read') === $registry->source('AliasReader_Repo_read'),
    'Alias and canonical source() agree on the same callable');

echo "ALL PASS\n";
}
