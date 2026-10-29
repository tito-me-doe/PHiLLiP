# PHiLLiP

**A PHP Harness incorporating Large Language in PHP.**

PHiLLiP is an open-source, local-first AI platform for PHP developers and the people using their applications. Standalone web chat, command-line agents, projects, and PHP integrations share the same underlying classes and workflow engine.

Run models on your own infrastructure and connect tools to self-hosted services. Custom endpoints also support hosted providers. PHiLLiP is under active development and [MIT licensed](LICENSE).

## Quick start

```sh
git clone https://github.com/tito-me-doe/PHiLLiP.git
cd PHiLLiP
./bin/install
```

The installer starts the application and its services, creates an administrator, and prints the web URL and temporary password. Open that URL, change the password, and follow web setup to discover/configure a model and begin chatting.

**Run your model server separately.** It must be reachable from PHiLLiP; model engines are not bundled. See [requirements and validated coverage](REQUIREMENTS.md) before installing and the [administration guide](docs/administration.md) for setup details.

## What PHiLLiP provides

- **Web chat:** streaming, saved conversations, Markdown/code rendering, attachments, selectable models/tools, personas, and expressive animated companions.
- **Local-first model configuration:** endpoint discovery, capability verification, and reusable model profiles.
- **Tools and knowledge:** search, fetching, browser interaction, conversation retrieval, and source-backed memory.
- **Durable agents:** queued assignments, separate conversations, progress events, and scoped tools for research and engineering work.
- **Projects:** planning conversations and recursive ToDos with readiness checks, explicit Start, escalation, and independently reviewed completion gates.
- **Benchmarks:** saved test suites, single-run and queued CLI execution, a Pareto report, process controls, and a test editor.
- **PHP classes and CLI:** use the same capabilities inline in application code or through `ok-phill`.

The web UI is server-rendered PHP; JavaScript handles streaming and interactivity. See the guides below for each feature's current boundaries and usage.

## Documentation

| I want to… | Read |
| --- | --- |
| Check dependencies, caveats, and tested coverage | [Requirements](REQUIREMENTS.md) |
| Install, configure models/services, or operate the system | [Installation and administration](docs/administration.md) |
| Ask questions or manage work from the terminal | [Command-line guide](docs/cli.md) |
| Evaluate model profiles and manage benchmark runs | [Benchmarks](docs/benchmarks.md) |
| Use PHiLLiP inside PHP code or write tools | [PHP class guide](docs/php-classes.md) |
| Coordinate engineering agents and personas | [Engineering agents and workflows](docs/engineering-team.md) |
| Plan and execute nested ToDos | [Projects](docs/projects.md) |
| Understand logging, attachments, and durable state | [Runtime reference](docs/runtime.md) |
| Understand best-effort input normalization | [Cleanup contracts](docs/cleanup.md) |
| Develop, test, or submit changes | [Contributing](CONTRIBUTING.md) |
| See discussed features that are not yet implemented | [Roadmap](ROADMAP.md) |
