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
        $selected = null;
        if ($testId !== null && $testId !== '' && $testId !== 'all') {
            foreach ($tests as $test) if ((string)$test['id'] === (string)$testId) $selected = $test;
            if (!$selected) throw new \InvalidArgumentException('Test not found');
        }
        $where = $selected ? ' AND r.testid=?' : '';
        $params = $selected ? [$selected['id']] : [];
        $columns = "r.id,r.profileid,r.testid,r.status,r.score,r.elapsed_seconds,r.error,r.created_at,r.finished_at,
            r.snapshot#>>'{profile,name}' AS profile_name,r.snapshot#>>'{test,name}' AS test_name,r.snapshot#>>'{test,revision}' AS test_revision,
            (SELECT count(*) FROM benchmark_results x WHERE x.benchmark_runid=r.id) AS recorded,
            jsonb_array_length(r.snapshot->'questions') AS questions";
        $latest = Database::rows('SELECT DISTINCT ON(r.profileid,r.testid) '.$columns.' FROM benchmark_runs r WHERE r.testid IS NOT NULL'.$where.' ORDER BY r.profileid,r.testid,r.id DESC',$params);
        // Keep the last completed score while a new run is queued/running, or definitions evolve.
        $completed = Database::rows('SELECT DISTINCT ON(r.profileid,r.testid) '.$columns." FROM benchmark_runs r WHERE r.status='completed' AND r.testid IS NOT NULL".$where.' ORDER BY r.profileid,r.testid,r.id DESC',$params);
        $aggregates = [];
        foreach ($completed as $run) {
            if ($run['score'] === null || !(int)$run['recorded']) continue;
            $id = $run['profileid'];
            if (!isset($aggregates[$id])) $aggregates[$id] = ['correct'=>0.0,'seen'=>0,'elapsed_seconds'=>0.0,'timed'=>true,'runs'=>[]];
            $count = (int)$run['recorded'];
            $aggregates[$id]['correct'] += (float)$run['score']*$count;
            $aggregates[$id]['seen'] += $count;
            $aggregates[$id]['timed'] = $aggregates[$id]['timed'] && $run['elapsed_seconds'] !== null;
            $aggregates[$id]['elapsed_seconds'] += (float)$run['elapsed_seconds'];
            $aggregates[$id]['runs'][] = $run;
        }
        $active = Database::rows("SELECT s.profileid,s.testid,s.status,COUNT(*) AS count FROM (
            SELECT r.profileid,r.testid,CASE WHEN r.status<>'running' AND NOT (".BenchmarkRunner::RUNNABLE.") THEN 'paused' ELSE r.status END AS status
            FROM benchmark_runs r WHERE r.dispatch_mode='queue' AND r.status IN ('queued','running','paused')".$where.") s GROUP BY s.profileid,s.testid,s.status",$params);
        $counts = ['queued'=>0,'running'=>0,'paused'=>0];
        $byProfile = [];
        foreach ($active as $run) {
            $status = $run['status'];
            $counts[$status] += (int)$run['count'];
            $byProfile[$run['profileid']][$status] = ($byProfile[$run['profileid']][$status] ?? 0)+(int)$run['count'];
        }
        $profiles = $this->profiles(); $points = [];
        $lastRuns = [];
        foreach ($latest as $run) if (!isset($lastRuns[$run['profileid']]) || $run['id']>$lastRuns[$run['profileid']]['id']) $lastRuns[$run['profileid']]=$run;
        foreach ($profiles as &$profile) {
            $aggregate = $aggregates[$profile['id']] ?? ['correct'=>0.0,'seen'=>0,'elapsed_seconds'=>0.0,'timed'=>false,'runs'=>[]];
            $aggregate['score'] = $aggregate['seen'] ? $aggregate['correct']/$aggregate['seen'] : null;
            if (!$aggregate['timed'] || !$aggregate['seen']) $aggregate['elapsed_seconds'] = null;
            unset($aggregate['timed']);
            $profile['benchmark'] = $aggregate;
            $profile['activity'] = $byProfile[$profile['id']] ?? [];
            $profile['last_run'] = $lastRuns[$profile['id']] ?? null;
            if ($aggregate['score'] !== null && $aggregate['elapsed_seconds'] !== null) $points[] = [
                'profileid'=>$profile['id'],'profile_name'=>$profile['name'],'score'=>$aggregate['score'],'elapsed_seconds'=>$aggregate['elapsed_seconds']
            ];
        }
        unset($profile);
        usort($profiles,function($a,$b) {
            return (($b['benchmark']['score'] ?? -1) <=> ($a['benchmark']['score'] ?? -1))
                ?: (($a['benchmark']['elapsed_seconds'] ?? INF) <=> ($b['benchmark']['elapsed_seconds'] ?? INF))
                ?: strnatcasecmp($a['name'],$b['name']) ?: ($a['id'] <=> $b['id']);
        });
        return ['profiles'=>$profiles,'tests'=>$tests,'selected'=>$selected,'latest'=>$latest,'completed'=>$completed,
            'points'=>$points,'frontier'=>self::frontier($points),'counts'=>$counts,
            'paused'=>BenchmarkRunner::paused(null,$selected ? $selected['id'] : null)];
    }

    private function profiles()
    {
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
        return $profiles;
    }

    public function metadata($profileId)
    {
        Pages::authorize($this->userId, 'benchmarks');
        foreach ($this->profiles() as $profile) if ((string)$profile['id'] === (string)$profileId) return $profile;
        throw new \InvalidArgumentException('Profile not found');
    }

    public static function frontier(array $points)
    {
        $front = [];
        foreach ($points as $point) {
            $dominated = false;
            foreach ($points as $other) {
                if (
                    $other['elapsed_seconds'] <= $point['elapsed_seconds'] &&
                    $other['score'] >= $point['score'] &&
                    ($other['elapsed_seconds'] < $point['elapsed_seconds'] || $other['score'] > $point['score'])
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
            return $a['elapsed_seconds'] <=> $b['elapsed_seconds'];
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
                : 'benchmarks'
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
        $testId = !empty($body['testid']) && $body['testid'] !== 'all' ? filter_var($body['testid'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : null;
        if ($testId === false) throw new \InvalidArgumentException('Invalid test ID');
        if ($testId !== null) Benchmarks::test($testId);
        if ($action === 'play') {
            return State::transaction(function () use ($profileId, $testId) {
                Database::row("SELECT scope FROM benchmark_controls WHERE scope='global' FOR UPDATE");
                $jobs = BenchmarkRunner::enqueue($profileId === null ? null : [$profileId],$testId === null ? null : [$testId],$this->userId,true);
                BenchmarkRunner::control('resume', $profileId, $testId);
                return ['queued' => count($jobs), 'resumed' => true];
            });
        }
        return BenchmarkRunner::control($action, $profileId, $testId);
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
                : '?view=benchmarks' . (!empty($result['testid']) || !empty($_POST['testid']) ? '&test='.(int)($result['testid'] ?? $_POST['testid']) : '') . (isset($_POST['return_to']) && $_POST['return_to']==='questions' ? '&questions=1' : '');
        header('Location: ' . Views::url($target), true, 303);
    }
}
