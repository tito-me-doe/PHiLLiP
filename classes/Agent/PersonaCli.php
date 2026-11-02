<?php
namespace Agent;

/** Saved-persona management without model calls or a web session. */
class PersonaCli
{
    public static function run(array $args)
    {
        Log::configure(['cli_stream' => 'stderr']);
        try {
            $command = $args ? array_shift($args) : 'help';
            if (in_array($command, ['help', '--help', '-h'], true)) { Terminal::write(self::help(), 'stdout'); return 0; }
            if (!in_array($command, ['list', 'show', 'save', 'delete', 'install', 'adult'], true)) throw new \InvalidArgumentException('Unknown personas command; use ok-phill personas --help');
            if ($command === 'install' || $command === 'adult') {
                $result = self::manage($command, $args);
                Terminal::write(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", 'stdout');
                Log::writeSuccess('Persona %s completed', $command);
                return 0;
            }
            $expected = $command === 'list' ? 0 : 1;
            $id = null;
            if ($command === 'save' && count($args) === 3 && $args[1] === '--id') {
                $id = $args[2]; $args = [$args[0]];
                if (!preg_match('/^[1-9][0-9]*$/D', $id)) throw new \InvalidArgumentException('--id requires a positive integer persona ID');
            }
            if (count($args) !== $expected) throw new \InvalidArgumentException('Invalid personas arguments; use ok-phill personas --help');
            $identity = Cli::identity();
            Log::writeDebug('Persona command', ['command' => $command, 'owner' => $identity['owner']]);
            if ($command === 'list') $result = Personas::listLocal($identity);
            elseif ($command === 'show') $result = Personas::resolve($args[0], $identity['owner']);
            elseif ($command === 'delete') $result = ['deleted' => Personas::deleteLocal($identity, $args[0])];
            else {
                $input = $args[0];
                if ($input === '-') {
                    $input = stream_get_contents(Terminal::stream('stdin'), 1048577);
                    if (strlen($input) > 1048576) throw new \InvalidArgumentException('Persona JSON exceeds 1 MiB; supply a smaller definition');
                }
                if (strlen($input) > 1048576) throw new \InvalidArgumentException('Persona JSON exceeds 1 MiB; supply a smaller definition');
                // JSON objects only: never interpret untrusted input as a server-side filename.
                if (substr(ltrim($input), 0, 1) !== '{') throw new \InvalidArgumentException('Persona save requires a JSON object or - for stdin');
                $result = Personas::saveLocal($identity, Config::object($input, 'Persona'), $id);
            }
            // Keep Unicode escapes so terminal controls/bidirectional text stay inert in CLI JSON.
            $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) throw new \RuntimeException('Could not encode persona result');
            Terminal::write($json . "\n", 'stdout');
            Log::writeSuccess('Persona %s completed', $command);
            return 0;
        } catch (\Throwable $e) {
            Log::writeError($e->getMessage());
            Log::writeVerbose($e);
            return $e instanceof \InvalidArgumentException ? 2 : 1;
        }
    }

    /** Installation-level commands: install the shipped cast, and read or change whether adult-oriented personas are available. */
    private static function manage($command, array $args)
    {
        $identity = Cli::identity();
        if (!empty($identity['unix']['root']) || !isset($identity['unix']['uid'])) throw new \RuntimeException('Root or unidentified processes cannot manage personas; run as an unprivileged Unix user');
        if ($command === 'install') {
            if ($args && $args !== ['--replace']) throw new \InvalidArgumentException('Usage: ok-phill personas install [--replace]');
            return PersonaSheet::install($args === ['--replace']);
        }
        if (count($args) !== 1 || !in_array($args[0], ['on', 'off', 'status'], true)) throw new \InvalidArgumentException('Usage: ok-phill personas adult on|off|status');
        if ($args[0] !== 'status') {
            if (empty($identity['user']['is_admin'])) throw new \RuntimeException('Only an administrator can change this');
            Personas::setAdultEnabled($args[0] === 'on', $identity['user']['id']);
        }
        return ['adult_personas' => Personas::adultEnabled()];
    }

    public static function help()
    {
        return "Usage: ok-phill personas COMMAND\n\nlist                         List visible saved personas\nshow ID_OR_NAME              Show one visible persona\nsave JSON_OR_- [--id ID]     Create or update; - reads up to 1 MiB from stdin\ndelete ID_OR_NAME            Delete an editable persona\ninstall [--replace]          Install the shipped persona cast (--replace restores shipped sheets)\nadult on|off|status          Whether adult-oriented personas are available (administrators)\n\nDefinitions use name, prompt, optional tools and contract. Omitted update fields are preserved.\nNames must resolve uniquely among visible personas; use an ID if ambiguous.\nResults are JSON on stdout; diagnostics use stderr. Exit codes: 0 success, 1 access/runtime, 2 invalid input.\nUnmapped Unix users own private personas only. Root can read, but cannot modify personas.\n--debug and --verbose use the common CLI logging switches.\n";
    }
}
