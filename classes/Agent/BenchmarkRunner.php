<?php
namespace Agent;

/** One durable queue for CLI/UI. Pause finishes the active question; Stop cancels it. */
class BenchmarkRunner
{
    public static function paused($profileId)
    {
        return (bool)Database::row("SELECT scope FROM benchmark_controls WHERE paused=TRUE AND scope IN ('global',?) LIMIT 1", ['profile:' . (int)$profileId]);
    }

    public static function enqueue($profiles = null, $tests = null, $owner = 'benchmark-runner')
    {
        $profiles = $profiles === null ? array_column((new Registry())->profiles(), 'id') : self::references($profiles);
        if ($tests === null) {
            $tests=[];
            foreach(Database::rows('SELECT id FROM benchmark_tests ORDER BY id') as $row) {
                try {
                    $suite=Benchmarks::test($row['id']);
                    if(!$suite['questions'])throw new \InvalidArgumentException('Test has no questions');
                    foreach($suite['questions'] as $question)Benchmarks::question($question,$suite['model_type']);
                    $tests[]=$row['id'];
                }catch(\InvalidArgumentException $e){Log::writeWarning('Skipping draft benchmark test',['testid'=>$row['id'],'reason'=>$e->getMessage()]);}
            }
        } else $tests=self::references($tests);
        if (!$profiles || !$tests) throw new \InvalidArgumentException('Choose at least one profile and test');
        if (count($profiles) * count($tests) > 1000) throw new \InvalidArgumentException('Queue at most 1000 profile/test pairs per request');
        return State::transaction(function () use ($profiles, $tests, $owner) {
            $jobs = [];
            foreach ($profiles as $profile) foreach ($tests as $test) {
                $job = Benchmarks::enqueue($profile, $test, $owner);
                $kind = $job['snapshot']['test']['model_type'];
                $capability = ['chat'=>'supports_chat','classify'=>'supports_chat','extract'=>'supports_chat','embed'=>'supports_embeddings','rerank'=>'supports_rerank','vision'=>'supports_vision'][$kind];
                if (isset($job['snapshot']['profile']['capabilities'][$capability]) && $job['snapshot']['profile']['capabilities'][$capability] === false) {
                    Database::execute("UPDATE benchmark_runs SET status='skipped',error=?,finished_at=NOW() WHERE id=?", ['Profile is known not to support ' . $kind, $job['id']]);
                    Log::writeWarning('Skipping incompatible benchmark pair', ['benchmark_id'=>$job['id'],'model_type'=>$kind]);
                }
                $jobs[] = Benchmarks::summary(Benchmarks::get($job['id']));
            }
            return $jobs;
        });
    }

    private static function references($input)
    {
        if (is_string($input)) $input = explode(',', $input);
        if (!is_array($input)) $input = [$input];
        $result = [];
        foreach ($input as $value) {
            if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException('Use saved profile/test names or IDs');
            $value = trim((string)$value);
            if ($value === '') throw new \InvalidArgumentException('Profile/test reference is empty');
            $result[$value] = $value;
        }
        return array_values($result);
    }

    public static function control($action, $profileId = null)
    {
        if (!in_array($action, ['pause','resume','stop'], true)) throw new \InvalidArgumentException('Unknown benchmark control');
        if ($profileId !== null && !(new Registry())->profile(['id'=>$profileId])) throw new \InvalidArgumentException('Profile not found');
        return State::transaction(function () use ($action, $profileId) {
            $scope = $profileId === null ? 'global' : 'profile:' . (int)$profileId;
            Database::execute('INSERT INTO benchmark_controls(scope) VALUES (?) ON CONFLICT DO NOTHING', [$scope]);
            Database::row('SELECT scope FROM benchmark_controls WHERE scope=? FOR UPDATE', [$scope]);
            Database::execute('UPDATE benchmark_controls SET paused=? WHERE scope=?', [$action !== 'resume' ? 'true' : 'false', $scope]);
            $where = $profileId === null ? '' : ' AND profileid=' . (int)$profileId;
            if ($action === 'resume') Database::execute("UPDATE benchmark_runs SET status='queued',worker_pid=NULL WHERE status='paused'" . $where);
            if ($action === 'stop') {
                $rows = Database::rows("UPDATE benchmark_runs SET status='stopped',error='Stopped by operator',finished_at=NOW() WHERE status IN ('queued','paused','running')" . $where . ' RETURNING id');
                foreach ($rows as $row) foreach (Database::rows("SELECT runid FROM benchmark_results WHERE benchmark_runid=? AND status='running'", [$row['id']]) as $case) {
                    Run::requestStop($case['runid']); Run::requestStop($case['runid']);
                    Run::finishStopped($case['runid'], 'Stopped by benchmark operator');
                }
                foreach ($rows as $row) Database::execute("UPDATE benchmark_results SET status='stopped',score=0,error='Stopped by operator' WHERE benchmark_runid=? AND status='running'", [$row['id']]);
            }
            Log::writeSuccess('Benchmark control applied', ['action'=>$action,'profileid'=>$profileId]);
            return ['action'=>$action,'scope'=>$scope];
        });
    }

    public static function recover()
    {
        return State::transaction(function () {
            $rows = Database::rows("SELECT id FROM benchmark_runs WHERE (status='running' AND heartbeat_at < NOW()-INTERVAL '90 seconds') OR (status='queued' AND dispatch_mode='manual' AND created_at < NOW()-INTERVAL '90 seconds') FOR UPDATE SKIP LOCKED");
            foreach ($rows as $row) {
                foreach (Database::rows("SELECT runid FROM benchmark_results WHERE benchmark_runid=? AND status='running'", [$row['id']]) as $case) Run::finishStopped($case['runid'], 'Benchmark worker heartbeat expired; inspect before rerunning');
                Database::execute("UPDATE benchmark_results SET status='stopped',error='Worker heartbeat expired',score=0 WHERE benchmark_runid=? AND status='running'", [$row['id']]);
                Database::execute("UPDATE benchmark_runs SET status='failed',error='Worker heartbeat expired; not replayed',finished_at=NOW() WHERE id=?", [$row['id']]);
                Log::writeWarning('Recovered abandoned benchmark without replay', ['benchmark_id'=>$row['id']]);
            }
            return count($rows);
        });
    }

    public static function work($worker = false, $cancel = null)
    {
        $scope = 'phillip-benchmark-runner:' . Database::row('SELECT current_schema() AS name')['name'];
        $lock = Database::row('SELECT pg_try_advisory_lock(hashtext(?)) AS locked', [$scope]);
        if (!$lock['locked']) {
            if ($worker) throw new \RuntimeException('Another benchmark runner already owns the queue');
            return null; // CLI submissions cooperate with the already-running supervisor.
        }
        $summaries = []; $lastRecovery = 0;
        try {
            do {
                if ($cancel && call_user_func($cancel)) break;
                if (time()-$lastRecovery >= 10) { self::recover(); $lastRecovery=time(); }
                $next = Database::row("SELECT r.id FROM benchmark_runs r WHERE r.status='queued' AND r.dispatch_mode='queue'
                    AND NOT EXISTS(SELECT 1 FROM benchmark_controls c WHERE c.paused AND c.scope IN ('global','profile:'||r.profileid::text)) ORDER BY r.id LIMIT 1");
                if ($next) {
                    try { $summaries[] = Benchmarks::summary(Benchmarks::execute($next['id'], $cancel)); }
                    catch (\Throwable $e) { Log::writeError('Benchmark dispatch failed', $e->getMessage()); if (!$worker) throw $e; }
                    if ($worker && count($summaries) > 100) array_shift($summaries);
                } elseif ($worker) usleep(250000);
            } while ($worker || $next);
        } finally { Database::row('SELECT pg_advisory_unlock(hashtext(?))', [$scope]); }
        return $summaries;
    }

    public static function cli(array $args)
    {
        Log::configure(['cli_stream'=>'stderr']);
        try {
            $worker = in_array('--worker',$args,true); $drain=in_array('--drain',$args,true); $json=in_array('--json',$args,true);
            $args=array_values(array_diff($args,['--worker','--drain']));
            $options=BenchmarkCli::options($args);
            if (isset($options['help'])) { Terminal::write("Usage: phill-bench-run [-p PROFILES_CSV] [-b TESTS_CSV] [--json]\n       phill-bench-run --drain [--json]\n       phill-bench-run --worker [--debug|--verbose]\n\nDefault: queue all saved profiles/tests, then drain runnable work. Known incompatible pairs are skipped.\n--drain runs existing queued jobs without adding any. --worker supervises the queue continuously.\nPause completes the current question; Stop cancels it. Interrupted work is never silently replayed.\n",'stdout'); return 0; }
            if (isset($options['list']) || (($worker || $drain) && (isset($options['profile']) || isset($options['test'])))) throw new \InvalidArgumentException('Worker/drain cannot enqueue profiles or tests');
            $stop=false;
            if(function_exists('pcntl_signal')) foreach([SIGINT,SIGTERM] as $signal) pcntl_signal($signal,function()use(&$stop){$stop=true;});
            $queued = !$worker && !$drain ? self::enqueue(isset($options['profile'])?$options['profile']:null,isset($options['test'])?$options['test']:null,Cli::identity()['owner']) : [];
            $cancel=function()use(&$stop){if(function_exists('pcntl_signal_dispatch'))pcntl_signal_dispatch();return $stop;};
            $results=self::work($worker,$cancel);
            if($results===null) {
                Log::writeInfo('Existing benchmark worker owns the queue; observing submitted work');
                $watch=$queued?:Database::rows("SELECT id FROM benchmark_runs WHERE dispatch_mode='queue' AND status IN ('queued','running','paused')");
                $ids=array_column($watch,'id');$retryAt=microtime(true)+2;
                while($ids && !$cancel()) {
                    $pending=Database::row("SELECT r.id FROM benchmark_runs r WHERE r.id IN (".implode(',',array_fill(0,count($ids),'?')).") AND (r.status='running' OR (r.status='queued' AND NOT EXISTS(SELECT 1 FROM benchmark_controls c WHERE c.paused AND c.scope IN ('global','profile:'||r.profileid::text)))) LIMIT 1",$ids);
                    if(!$pending)break;
                    usleep(250000);
                    if(microtime(true)>=$retryAt) {
                        // Take over if the existing worker exited, rather than waiting forever on queued work.
                        if(self::work(false,$cancel)!==null)break;
                        $retryAt=microtime(true)+2;
                    }
                }
                $results=array_map(function($job){return Benchmarks::summary(Benchmarks::get($job['id']));},$watch);
            }
            if ($queued) $results = array_map(function($job){return Benchmarks::summary(Benchmarks::get($job['id']));},$queued);
            if (!$worker) Terminal::write(json_encode($results,$json?JSON_PRETTY_PRINT:0)."\n",'stdout');
            Log::writeSuccess('Benchmark runner finished', ['processed'=>count($results)]);
            if ($stop) return 130;
            if (count(array_filter($results,function($r){return $r['status']==='failed';}))) return 1;
            return Database::row("SELECT id FROM benchmark_runs WHERE dispatch_mode='queue' AND status IN ('queued','running','paused') LIMIT 1") ? 3 : 0;
        } catch(\Throwable $e){Log::writeError($e->getMessage());Log::trace('Benchmark runner exception',$e);return $e instanceof \InvalidArgumentException?2:1;}
    }
}
