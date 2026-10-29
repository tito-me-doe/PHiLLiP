<?php
namespace Agent;

/** Administrator adapter. Benchmark definitions/results may contain business data. */
class BenchmarkFrontend
{
    private $userId;

    public function __construct($user)
    {
        $this->userId = is_array($user) ? $user['id'] : $user;
        Access::authenticated($this->userId);
    }

    public function report($testId = null)
    {
        Pages::authorize($this->userId, 'benchmarks');
        $tests = Benchmarks::tests();
        if ($testId === null && $tests) {
            $testId = $tests[0]['id'];
        }
        $selected = null;
        foreach ($tests as $test) {
            if ((string) $test['id'] === (string) $testId) {
                $selected = $test;
            }
        }
        if ($testId !== null && !$selected) {
            throw new \InvalidArgumentException('Test not found');
        }
        $profiles = (new Registry())->profiles();
        $models = (new Registry())->models();
        foreach ($profiles as &$profile) {
            $config = Config::profile($profile);
            $profile['model'] = isset($config['model']) ? $config['model'] : $config['body']['model'];
            $base = rtrim(preg_replace('~/v1/(?:chat/completions|responses|embeddings|rerank)$~', '', $config['url']), '/');
            $profile['metadata'] = [];
            $profile['engine_status'] = 'custom / unknown';
            foreach ($models as $model) {
                if (
                    (string) $model['id'] === (string) $profile['model_id'] ||
                    ($model['model'] === $profile['model'] && rtrim($model['url'], '/') === $base)
                ) {
                    $profile['metadata'] = Config::object($model['metadata'], 'Model metadata');
                    $profile['engine_status'] = $model['endpoint_status'] . ' / ' . $model['status'];
                    break;
                }
            }
            // Do not send credentials/configuration to a report or browser state payload.
            unset($profile['config'], $profile['test_results'], $profile['overrides']);
        }
        unset($profile);
        $columns = "r.id,r.profileid,r.testid,r.status,r.score,r.elapsed_seconds,r.error,r.created_at,r.finished_at,
            r.snapshot#>>'{profile,name}' AS profile_name,r.snapshot#>>'{test,name}' AS test_name,r.snapshot#>>'{test,revision}' AS test_revision,
            (SELECT count(*) FROM benchmark_results x WHERE x.benchmark_runid=r.id) AS recorded,
            jsonb_array_length(r.snapshot->'questions') AS questions,
            (SELECT CASE WHEN SUM(elapsed_seconds)>0 THEN CASE WHEN r.snapshot#>>'{test,model_type}' IN ('embed','rerank') THEN COUNT(*)/SUM(elapsed_seconds) WHEN count(completion_tokens)=count(*) THEN SUM(completion_tokens)/SUM(elapsed_seconds) END END FROM benchmark_results x WHERE x.benchmark_runid=r.id) AS speed";
        $latest = Database::rows(
            'SELECT DISTINCT ON(r.profileid,r.testid) ' . $columns . ' FROM benchmark_runs r ORDER BY r.profileid,r.testid,r.id DESC'
        );
        $recent = Database::rows('SELECT ' . $columns . ' FROM benchmark_runs r ORDER BY r.id DESC LIMIT 40');
        $points = !$selected
            ? []
            : Database::rows(
                'SELECT DISTINCT ON(r.profileid) ' .
                    $columns .
                    " FROM benchmark_runs r WHERE r.status='completed' AND r.testid=? AND r.snapshot#>>'{test,revision}'=? ORDER BY r.profileid,r.id DESC",
                [$selected['id'], (string) $selected['revision']]
            );
        $points = array_values(
            array_filter($points, function ($point) {
                return $point['speed'] !== null && $point['score'] !== null;
            })
        );
        return [
            'profiles' => $profiles,
            'tests' => $tests,
            'selected' => $selected,
            'latest' => $latest,
            'recent' => $recent,
            'points' => $points,
            'frontier' => self::frontier($points),
            'paused' => BenchmarkRunner::paused(null),
            'controls' => Database::rows('SELECT * FROM benchmark_controls'),
            'defaults' => Database::rows('SELECT * FROM profile_defaults ORDER BY default_for')
        ];
    }

    public static function frontier(array $points)
    {
        $front = [];
        foreach ($points as $point) {
            $dominated = false;
            foreach ($points as $other) {
                if (
                    $other['speed'] >= $point['speed'] &&
                    $other['score'] >= $point['score'] &&
                    ($other['speed'] > $point['speed'] || $other['score'] > $point['score'])
                ) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $front[] = $point;
            }
        }
        usort($front, function ($a, $b) {
            return $a['speed'] <=> $b['speed'];
        });
        return $front;
    }

    public function details($id)
    {
        Pages::authorize($this->userId, 'benchmarks');
        return Benchmarks::get($id);
    }

    public function action($action, array $body = [])
    {
        Pages::authorize(
            $this->userId,
            $action === 'default'
                ? 'models'
                : (in_array($action, ['save-test', 'save-question', 'remove-question'], true)
                    ? 'test-suites'
                    : 'benchmarks')
        );
        if ($action === 'save-test') {
            if (!empty($body['testid']) && !isset($body['revision'])) {
                throw new \InvalidArgumentException('Current test revision is required');
            }
            $row = Benchmarks::saveTest(
                $body,
                !empty($body['testid']) ? $body['testid'] : null,
                isset($body['revision']) ? $body['revision'] : null
            );
            Log::writeSuccess('Benchmark test saved', ['testid' => $row['id']]);
            return ['testid' => $row['id']];
        }
        if (in_array($action, ['save-question', 'remove-question'], true)) {
            if (!isset($body['testid'], $body['revision'])) {
                throw new \InvalidArgumentException('Test ID and current revision are required');
            }
            if ($action === 'save-question') {
                $row = Benchmarks::saveQuestion(
                    $body['testid'],
                    $body,
                    !empty($body['questionid']) ? $body['questionid'] : null,
                    $body['revision']
                );
            } else {
                if (!isset($body['confirm']) || !Config::boolean($body['confirm'], 'Deletion confirmation')) {
                    throw new \InvalidArgumentException('Confirm question deletion');
                }
                Benchmarks::removeQuestion($body['testid'], $body['questionid'], $body['revision']);
            }
            Log::writeSuccess('Benchmark question updated', [
                'testid' => $body['testid']
            ]);
            return ['testid' => $body['testid']];
        }
        if ($action === 'default') {
            Profiles::setDefault($body['default_for'], !empty($body['profileid']) ? ['id' => $body['profileid']] : null);
            return ['ok' => true];
        }
        $profileId = !empty($body['profileid'])
            ? filter_var($body['profileid'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1]
            ])
            : null;
        if ($profileId === false) {
            throw new \InvalidArgumentException('Invalid profile ID');
        }
        if ($action === 'play') {
            return State::transaction(function () use ($profileId) {
                Database::row("SELECT scope FROM benchmark_controls WHERE scope='global' FOR UPDATE");
                $params = $profileId === null ? [] : [$profileId];
                $where = $profileId === null ? '' : ' AND profileid=?';
                $existing = Database::row(
                    "SELECT id FROM benchmark_runs WHERE dispatch_mode='queue' AND status IN ('queued','paused','running')" .
                        $where .
                        ' LIMIT 1',
                    $params
                );
                $jobs = $existing ? [] : BenchmarkRunner::enqueue($profileId === null ? null : [$profileId], null, $this->userId);
                BenchmarkRunner::control('resume', $profileId);
                // Global pause remains authoritative; per-profile Play must not launch unrelated models.
                return ['queued' => count($jobs), 'resumed' => true];
            });
        }
        return BenchmarkRunner::control($action, $profileId);
    }

    public static function form(array $state)
    {
        if (!hash_equals($state['csrf'], isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            throw new \RuntimeException('Invalid CSRF token');
        }
        $adapter = new self($state['user']);
        $action = substr($_POST['action'], 10);
        $result = $adapter->action($action, $_POST);
        Log::flash(Log::writeSuccess('Benchmark ' . $action . ' accepted'));
        $target =
            isset($_POST['return_to']) && $_POST['return_to'] === 'models'
                ? '?view=settings&tab=models'
                : '?view=benchmarks' . (isset($result['testid']) ? '&edit_test=' . (int) $result['testid'] : '');
        header('Location: ' . Views::url($target), true, 303);
    }
}
