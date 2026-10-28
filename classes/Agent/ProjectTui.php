<?php
namespace Agent;

/** Read-only terminal project monitor. No model calls, tools, or state mutations. */
class ProjectTui
{
    public static function run(array $argv)
    {
        Log::configure(['cli_stream' => 'stderr']);
        try {
            $options = self::parse($argv);
        } catch (\InvalidArgumentException $error) {
            Log::writeError($error->getMessage());
            return 2;
        }
        if ($options['help']) {
            Terminal::write(self::help(), 'stdout');
            Log::writeVerbose('tui help rendered');
            return 0;
        }
        try {
            $identity = Cli::identity();
            if ($options['once'] || !Terminal::isTTY('stdout')) {
                $projects = self::listProjects($identity, $options['project']);
                $code = self::renderOnce($projects, $options['project']);
                Log::writeSuccess('Project monitor displayed', ['project_count' => count($projects), 'filtered' => $options['project'] !== null]);
                return $code;
            }
            $code = self::interactive($identity, $options['project']);
            Log::writeSuccess('Project monitor closed');
            return $code;
        } catch (\Throwable $error) {
            Log::writeError($error->getMessage());
            Log::writeVerbose($error);
            return 1;
        }
    }

    /** Public for tests. Accepts --project ID|NAME, --once, --help; --debug/--verbose are bootstrap-owned. */
    public static function parse(array $argv)
    {
        $result = ['project' => null, 'once' => false, 'help' => false];
        $projectSeen = false;
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '--') { continue; }
            if ($arg === '--help' || $arg === '-h') { $result['help'] = true; continue; }
            if ($arg === '--once') { $result['once'] = true; continue; }
            if ($arg === '--debug' || $arg === '--verbose') continue; // handled by shared bootstrap
            if ($arg === '--project') {
                if ($projectSeen) throw new \InvalidArgumentException('Duplicate --project option');
                $value = isset($argv[$i + 1]) ? $argv[++$i] : null;
                if ($value === null || $value === '') throw new \InvalidArgumentException('--project requires a nonempty project id or name');
                $result['project'] = $value;
                $projectSeen = true;
                continue;
            }
            if (strpos($arg, '--project=') === 0) {
                if ($projectSeen) throw new \InvalidArgumentException('Duplicate --project option');
                $result['project'] = substr($arg, strlen('--project='));
                if ($result['project'] === '') throw new \InvalidArgumentException('--project requires a nonempty project id or name');
                $projectSeen = true;
                continue;
            }
            throw new \InvalidArgumentException('Unknown option: ' . $arg);
        }
        return $result;
    }

    public static function stripControl(string $text)
    {
        return Terminal::strip($text);
    }

    /** Render plain, terminal-safe lines from shared snapshot data. No raw ANSI here. */
    public static function buildLines(array $displays, $selectedId = null)
    {
        $lines = ['Projects (read-only, local owner)'];
        foreach ($displays as $display) {
            $marker = ($selectedId !== null && (string)$display['id'] === (string)$selectedId) ? '> ' : '  ';
            $persona = $display['persona'] === null ? '-' : self::label((string)$display['persona']);
            $decisions = $display['decisions'] === null ? '?' : (string)$display['decisions'];
            $label = self::label($display['id'] . ' ' . $display['title']);
            $lines[] = sprintf(
                '%s%s  %-12s  rev=%s  persona=%s  attempts=%s  decisions=%s',
                $marker,
                $label,
                self::label($display['status']),
                (string)$display['revision'],
                $persona,
                (string)$display['attempts'],
                $decisions
            );
            if (!empty($display['todos'])) {
                self::appendTodoLines($lines, $display['todos']);
            }
            foreach (array_slice($display['latest'], -2) as $event) {
                $lines[] = sprintf('    └ %s · %s', self::label($event['type']), self::label((string)($event['created_at'] ?? '')));
            }
        }
        return $lines;
    }

    private static function label(string $text)
    {
        return str_replace(["\n", "\r"], ' ', Terminal::strip($text));
    }

    private static function todoLine(array $todo)
    {
        $title = self::label((string)($todo['title'] ?? ''));
        $status = self::label((string)($todo['status'] ?? ''));
        $revision = isset($todo['revision']) ? (string)$todo['revision'] : '-';
        $attempts = isset($todo['attempts']) ? (string)$todo['attempts'] : '-';
        $wait = isset($todo['wait_reason']) && $todo['wait_reason'] !== null ? ' wait=' . self::label((string)$todo['wait_reason']) : '';
        return sprintf('- todo %s [%s rev=%s attempts=%s%s]', $title, $status, $revision, $attempts, $wait);
    }

    private static function appendTodoLines(array &$lines, array $todos, $depth = 0)
    {
        foreach ($todos as $todo) {
            $lines[] = str_repeat(' ', 4 + $depth * 2) . self::todoLine($todo);
            if ($depth < ProjectWorkflow::MAX_DEPTH && !empty($todo['children'])) self::appendTodoLines($lines, $todo['children'], $depth + 1);
        }
    }

    public static function listProjects(array $identity, $reference = null)
    {
        if ($reference !== null) {
            // Resolve by id or exact name, then enforce local-owner access.
            $project = new Project($reference);
            ProjectAccess::local($project); // throws unless current CLI identity owns it
            return [$project];
        }
        $rows = Database::rows('SELECT p.id, t.owner_id FROM projects p JOIN threads t ON t.id = p.thread_id WHERE t.owner_id = ? ORDER BY p.id', [(string)$identity['owner']]);
        $projects = [];
        foreach ($rows as $row) {
            $project = new Project($row);
            try {
                ProjectAccess::local($project);
            } catch (\Throwable $e) {
                continue;
            }
            $projects[] = $project;
        }
        usort($projects, function ($a, $b) { return $a->id <=> $b->id; });
        return $projects;
    }

    public static function displayData(Project $project)
    {
        return State::transaction(function () use ($project) {
        // Cached selections carry identity only. Recheck current ownership on every poll,
        // in the same snapshot as the displayed data so an ownership transfer cannot leak it.
        ProjectAccess::local($project);
        $snapshot = $project->snapshot(); // shared read-only domain query
        $row = $snapshot['project'];
        $settings = is_array($row['settings']) ? $row['settings'] : [];
        $persona = isset($settings['persona']) ? $settings['persona'] : (isset($settings['persona_id']) ? $settings['persona_id'] : null);
        $attempts = 0;
        $todos = [];
        foreach ($snapshot['todos'] as $todo) {
            $attempts += (int)$todo['attempts'];
            $todos[] = $todo;
        }
        $todos = self::nestTodos($todos);
        $decisions = 0;
        foreach ($snapshot['decisions'] as $decision) if ($decision['answer'] === null) $decisions++;
        return [
            'id' => (string)$row['id'],
            'title' => $row['title'],
            'status' => $row['status'],
            'revision' => (int)$row['revision'],
            'persona' => $persona,
            'attempts' => $attempts,
            'decisions' => $decisions,
            'latest' => array_slice($snapshot['events'], -2),
            'todos' => $todos,
        ];
        }, true);
    }

    private static function nestTodos(array $todos)
    {
        $byId = []; $roots = [];
        foreach ($todos as $todo) {
            $todo['children'] = [];
            $byId[(string)$todo['id']] = $todo;
        }
        foreach ($todos as $todo) {
            $parent = $todo['parent_id'] !== null ? (string)$todo['parent_id'] : null;
            if ($parent !== null && isset($byId[$parent])) {
                $byId[$parent]['children'][] = &$byId[(string)$todo['id']];
            } else {
                $roots[] = &$byId[(string)$todo['id']];
            }
        }
        return $roots;
    }

    private static function renderOnce(array $projects, $reference)
    {
        $displays = [];
        foreach ($projects as $project) {
            $displays[] = self::displayData($project);
        }
        self::writeLines(self::buildLines($displays, $reference !== null ? $projects[0]->id : null), null, false);
        return 0;
    }

    private static function interactive(array $identity, $reference)
    {
        $projects = self::listProjects($identity, $reference);
        if (count($projects) === 0) {
            Terminal::write('No projects visible to the current owner.' . "\n", 'stdout');
            return 0;
        }
        $selectedIndex = null;
        if ($reference !== null) {
            foreach ($projects as $i => $project) {
                if ((string)$project->id === (string)$projects[0]->id) { $selectedIndex = $i; break; }
            }
        }
        while (true) {
            // Re-read shared snapshots every poll so status/decisions stay current.
            $displays = [];
            foreach ($projects as $project) {
                $displays[] = self::displayData($project);
            }
            $selectedId = $selectedIndex !== null ? $projects[$selectedIndex]->id : null;
            self::writeLines(self::buildLines($displays, $selectedId), $selectedIndex, true);
            $read = [STDIN];
            $write = null;
            $except = null;
            $count = @stream_select($read, $write, $except, 1, 0);
            if ($count === false || $count === null) break;
            if ($count > 0) {
                $line = @fgets(STDIN);
                if ($line === false) break;
                $cmd = trim($line);
                if ($cmd === 'q') break;
                if ($cmd === 'r') continue;
                if ($cmd !== '' && ctype_digit($cmd)) {
                    $want = (string)(int)$cmd;
                    foreach ($projects as $i => $project) {
                        if ((string)$project->id === $want) { $selectedIndex = $i; break; }
                    }
                    continue;
                }
            }
        }
        return 0;
    }

    private static function writeLines(array $lines, $selectedIndex, $tty)
    {
        $out = Terminal::stream('stdout');
        if ($tty) fwrite($out, "\033[2J\033[H");
        foreach ($lines as $i => $line) {
            $text = ($tty && $selectedIndex !== null && $i === $selectedIndex) ? Terminal::format($line, 'bold') : $line;
            Terminal::write($text . "\n", 'stdout');
        }
    }

    private static function help()
    {
        return "Usage: phill [--project ID|NAME] [--once] [--help]\n\n"
            . "--project   Show only this project (id or exact name)\n"
            . "--once      Render once and exit; automatic when output is not a TTY\n"
            . "--help      Show this help\n\n"
            . "Read-only project monitor: lists local owner projects, no model calls or mutations.\n"
            . "Interactive TTY: q quits, r refreshes, a project number selects it.\n";
    }
}
