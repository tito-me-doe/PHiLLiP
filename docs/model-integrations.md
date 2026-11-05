# Remote model integrations

`phill-scan` discovers endpoints that are already running on a local network. Its records feed PHiLLiP's runnable model registry, the same registry the Scan form on System Settings (`/models`, Models card) queues through `/api/admin/scan`. Options: `--cidr`, `--ports` (defaults from `SCAN_CIDRS`/`SCAN_PORTS`), `-a` (all ports), `--url BASE` (inspect one endpoint), `--json`, `--no-save` (do not write the registry). `phill-scan-hf` queries the public Hugging Face Hub catalog; its results are remote repository metadata and never become runnable PHiLLiP models or profiles just by being listed. Neither the Hub catalog nor Unsloth Studio has a page; both are CLI-only.

The Hub catalog defaults to the 100 highest trending models and accepts a documented sort override and a page size (`--limit`, 1..1000). It always prints JSON on stdout (diagnostics on stderr; exit 0 OK, 1 runtime/HTTP error, 2 usage error):

```sh
./bin/phill-scan-hf
./bin/phill-scan-hf --sort downloads
./bin/phill-scan-hf --limit 20
```

`--sort` accepts `author`, `downloads`, `downloadsAllTime`, `gated`, `lastModified`, `likes`, `trendingScore`, `usedStorage`. Tests: `php tests/huggingface_catalog.php`, `php tests/unsloth_studio.php`, `php tests/scanner.php`.

The implementation uses Hugging Face's public model listing endpoint with `sort=trendingScore`, `direction=-1`, and `limit=100`. The Hub client documents `trending_score` as a model-list sort and `limit` as the result cap; model information includes repository IDs and optional metadata such as downloads, likes, tags, pipeline task, and gated status. Fields that the Hub omits remain absent rather than being guessed. See [Hugging Face Hub model search](https://huggingface.co/docs/huggingface_hub/en/guides/search) and the [HfApi `list_models` reference](https://huggingface.co/docs/huggingface_hub/en/package_reference/hf_api).

## Unsloth Studio

Configure PHiLLiP's read-only connection with environment variables:

```sh
export UNSLOTH_STUDIO_URL=http://127.0.0.1:8888
export UNSLOTH_STUDIO_API_KEY='sk-unsloth-…'   # optional; sent as a Bearer token when set
./bin/phill-unsloth status
./bin/phill-unsloth settings
```

Status uses Unsloth's documented authenticated `GET /v1/models` endpoint to show the model IDs reported by that Studio instance. PHiLLiP does not start downloads or load models during status checks. The URL must be an http(s) origin without credentials, query, or fragment. The key is read from the environment and is never printed by `settings`.

Unsloth documents model loading in the Studio interface or with its `unsloth run --model MODEL_ID` command, followed by its OpenAI-compatible `/v1/*` inference API. Its public API guide does not document stable Studio REST operations for loading or unloading models. The upstream application source currently contains internal `/api/inference/load`, `/unload`, and `/status` routes, but those are not part of the published API contract; this integration does not call them. As a result `phill-unsloth load --model MODEL_ID` and `unload --model MODEL_ID` validate the requested model identifier and then report the unsupported control path. Use Studio's UI or the documented `unsloth run` command to load a model, and stop that process or unload it in Studio to release it.

Unsloth documents `unsloth run --model ...` for launching a model and exposes current model IDs through `GET /v1/models`. For the full options and security behavior, see [How to use Unsloth as an API endpoint](https://unsloth.ai/docs/basics/api). The REST guide notes that `/docs` and `/openapi.json` on a specific Studio instance include version-specific routes; a future adapter can add control operations only after a configured live endpoint provides a validated contract.
