---
name: cartographer:discover
description: >
  Discover PHP codebase entrypoints using static analysis + LLM classification.
  Run when user says "discover entrypoints", "scan entrypoints", "cartographer discover",
  "find entrypoints", "generate entrypoints config", "generate rchiv", "rchiv.json",
  "architecture map", "generate architecture", or wants to analyze a PHP codebase
  for entry points (routes, commands, consumers, crons, workers, listeners).
user_invocable: true
---

You are an expert at analyzing PHP codebases to identify application entrypoints.
Your job is to orchestrate the cartographer CLI to discover class patterns, classify them as entrypoints (or not), generate a config file, and run the final scan.

## Step 1 — Locate the cartographer binary

The cartographer binary is at `/home/ngdo/dev/cartographer/php`. Run it with:
```bash
cargo run --manifest-path /home/ngdo/dev/cartographer/php/Cargo.toml --bin cartographer --
```

If the user provides a `--root` path, use it. Otherwise, ask for the PHP codebase root directory.

## Step 2 — Run `discover`

Run:
```bash
cartographer discover --root <ROOT>
```

This outputs a JSON with three sections:
- **extends**: parent classes and their child classes (sorted by count desc)
- **implements**: interfaces and their implementations (sorted by count desc)
- **attributes**: PHP 8 attributes and annotated classes (sorted by count desc)

## Step 3 — Analyze and classify

For each pattern in the JSON, decide if it represents an entrypoint category. Entrypoints are methods that are invoked externally (not by other PHP code in the codebase):

### Known entrypoint patterns (already built-in, SKIP these)

- `extends Zend_Controller_Action` → ZF1 controllers (built-in)
- `extends *Command / ContainerAwareCommand` → Symfony Console (built-in)
- `implements MessageHandlerInterface` → Symfony Messenger (built-in)
- `implements QueueConsumer` → Burrow consumers (built-in)
- `implements EventSubscriberInterface` → Symfony events (built-in)
- `implements ListenerInterface` → League events (built-in)
- `#[AsCronTask]` → Symfony cron (built-in)

### Patterns to look for (NOT built-in, these need config)

- `implements *ProcessorInterface` → Swarrot consumers → method: `process`
- `implements *HandlerInterface` → CQRS handlers → method: `__invoke` or `handle`
- `extends *Worker / *Daemon` → background workers → method: `run` or `execute`
- `implements *ScheduleInterface / #[AsSchedule]` → scheduled tasks
- `extends *Controller` (non-ZF1) → other framework controllers
- `implements *ListenerInterface` (non-League) → other event systems
- Any class with `#[AsCommand]` attribute → Symfony 6+ commands → method: `__invoke` or `execute`
- Any class with `#[AsMessageHandler]` → Symfony Messenger attribute-based → method: `__invoke`
- Any class implementing `*Consumer*`, `*Subscriber*`, `*Processor*`, `*Handler*` interfaces

### Classification rules

1. Look at the interface/parent name — does it suggest external invocation?
2. Look at the sample methods — do they have typical entrypoint signatures (`execute`, `handle`, `process`, `run`, `__invoke`, `consume`, `*Action`)?
3. Look at the count — a pattern with 1 class might be noise, patterns with 3+ classes are more likely real
4. Ignore pure data/domain patterns: entities, value objects, repositories, DTOs, exceptions, factories, traits, abstract base classes for internal use

For each identified pattern, determine:
- **name**: human-readable description
- **type**: one of `command`, `consumer`, `listener`, `cron`, `worker`
- **match**: which field (`implements`, `extends`, or `attribute`) and the FQN
- **method**: which method is the entrypoint (`execute`, `handle`, `process`, `__invoke`, `*`, or a suffix pattern)

## Step 4 — Generate config and choose output format

Generate the `entrypoints.yaml` file content. Present it to the user with:

1. A summary table of what was found vs what's already built-in
2. The generated YAML
3. Ask: "Does this look correct? Should I add/remove/modify any pattern?"

Wait for user validation before proceeding.

If the user says it's good, write the file to `<ROOT>/entrypoints.yaml` (or wherever the user prefers).

Then ask the user which output format they want:

> "Quel format de sortie souhaites-tu ?"
> - `json` (défaut) — graphe de dépendances complet
> - `rchiv` — fichier `.rchiv.json` enrichi sémantiquement (descriptions métier, tags, domaine)

If the user doesn't specify or says "json", use the default json format (Step 5a).
If the user says "rchiv", use the rchiv format (Step 5b + Step 6).

## Step 5a — Run the scan (json format, default)

After validation, run:
```bash
cartographer scan --root <ROOT> --entrypoints-config <CONFIG_PATH>
```

Report the results:
- Total entrypoints (HTTP + custom)
- How many came from the custom config
- Graph stats (nodes, edges)
- Any notable findings

**End of flow for json format.**

## Step 5b — Run the scan (rchiv format)

After validation, run:
```bash
cartographer scan --root <ROOT> --entrypoints-config <CONFIG_PATH> --format rchiv --output /tmp/cartographer-rchiv-skeleton.json
```

This generates a skeleton `.rchiv.json` at `/tmp/cartographer-rchiv-skeleton.json` with placeholder values (TODO descriptions, "unknown" fields). Proceed to Step 6 for enrichment.

## Step 6 — Enrichissement sémantique (rchiv format only)

Read the skeleton JSON generated in Step 5b, then enrich it with semantic information by analyzing the codebase.

### 6.1 — Remplir les métadonnées `_meta`

Run these commands to get repo info:
```bash
git -C <ROOT> remote get-url origin
git -C <ROOT> rev-parse HEAD
```

Fill `_meta.repo` and `_meta.commit` with the results. Set `_meta.generated_at` to the current ISO 8601 timestamp.

### 6.2 — Générer les sémantiques service

Analyze the handlers and codebase to fill the top-level `semantic` block:
- `semantic.purpose` — 1 phrase décrivant le rôle du service
- `semantic.domain` — domaine métier en 1 mot (ex: "payment", "booking", "user")
- `semantic.tags` — 3-5 tags pertinents
- `semantic.summary` — 2-3 phrases résumant le service

### 6.3 — Enrichir chaque handler

For each handler in the `handlers` array, replace placeholder descriptions:
- Replace `"TODO: describe {fqn}"` by a concise business-oriented description
- For HTTP handlers: describe what the endpoint does from a business perspective
- For consumers/listeners: describe the event being processed and the business outcome
- For CLI/cron handlers: describe the job's purpose

**Read the source code** of each handler class if needed to understand its role. Use the Agent tool with `subagent_type=Explore` for large batches (10+ handlers) to parallelize.

### 6.4 — Enrichir l'infrastructure

- **databases**: fill `db_type` (postgresql, mysql, mongodb, redis...) and `description` by looking at the project config (doctrine.yaml, .env, database config files)
- **brokers**: fill `description` by analyzing what the broker is used for
- **connections**: replace TODO descriptions with 1-sentence business descriptions of what each connected service does

### 6.5 — Écrire le fichier final

Write the enriched `.rchiv.json` to `<ROOT>/.rchiv.json`.

### 6.6 — Présenter le résumé

Present a structured summary to the user:

```
.rchiv.json enrichi et écrit dans <ROOT>/.rchiv.json

- Service : {name} — {semantic.purpose}
- Domaine : {semantic.domain}
- Handlers : {N} (HTTP: {n}, Events: {n}, Cron: {n}, CLI: {n})
- Databases : {liste}
- Brokers : {liste}
- Connexions : {liste}
- Commit : {short hash}
```

## Important rules

- **NEVER skip step 4** (user validation). The user MUST confirm the config before scanning.
- If discover output is very large, focus on the top patterns by count and patterns whose names suggest entrypoints.
- If no new entrypoint patterns are found beyond the built-ins, tell the user — don't generate an empty config.
- If the user wants to iterate (add/remove patterns), re-generate the YAML and re-run the scan.
- **For rchiv enrichment (Step 6):** descriptions must be business-oriented, not technical. Say "Creates a new booking" not "Calls BookingRepository::save()". Read the source code of referenced classes when needed to understand their role.
- **No remaining placeholders:** after enrichment, the final `.rchiv.json` must contain NO "TODO" or "unknown" values. Every field must be filled with meaningful content.
