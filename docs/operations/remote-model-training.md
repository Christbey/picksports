# Remote model training transfer

Status: local implementation, disabled by default. No production deployment,
credentials, migration, Mac installation, or live network verification is implied.

## Responsibilities

Production still prepares trusted datasets, owns attempt leases, verifies and
registers artifacts, evaluates promotion gates, and selects private challengers.
The Mac runs only Python training from a matching source checkout. It needs no
Laravel installation, application `.env`, database connection, customer account,
or object-storage credentials. Ordinary scheduled commands remain local.

Enablement is explicit: `ML_REMOTE_TRAINING_ENABLED=false` is the default.
`--worker` opts one newly prepared cycle into remote execution. It does not change
the scheduler or automatically move other work. Prepared cycles are bound to one
worker UUID and sport. Existing local cycles cannot be claimed remotely, and
local `--resume` refuses remote cycles.

## Credentials

The `ml:worker-credential` operator command issues dedicated, expiring bearer
credentials. These are not Sanctum/Passport user tokens. The server stores only a
SHA-256 token hash. The full credential is written once to a new private file,
never console output; existing files/symlinks cannot be overwritten.

Example operator commands (replace paths/UUIDs before use):

```bash
php artisan ml:worker-credential issue --name=home-mini --sport=nfl --sport=mlb --days=30 --output=/private/operator-directory/mini.token
php artisan ml:worker-credential rotate --worker=WORKER_UUID --output=/private/operator-directory/mini-next.token
php artisan ml:worker-credential revoke --worker=WORKER_UUID
```

The parent directory must already exist and should be private. Store credentials
outside source control and transfer the token file to the Mac through an existing
authorized secure channel. Do not copy the production environment file. Rotation
preserves the worker UUID and scopes, immediately invalidates the old token, and
allows the new token to recover that worker's active task. Revocation and expiry
block requests, including completion receipt replay. Default lifetime is 30 days;
the allowed range is 1–365 days.

Supported abilities are `training:read`, `training:claim`, `training:upload`, and
`training:complete`, and `training:report`; issuance grants these and only the explicitly named NFL/MLB
sports. There is no wildcard, customer API access, model promotion, public
publishing, dataset creation, arbitrary command, or storage-path capability.
Artifacts are executable serialization: an issued worker is a trusted artifact
producer. Hash checks detect corruption and lineage mismatch, not malicious
behavior by a compromised trusted training machine.

## Prepare and run

After an independently reviewed deployment/activation, an authorized operator can
prepare a cycle on the application host:

```bash
php artisan sports:train-weekly-model-challenger nfl --worker=WORKER_UUID --no-promote
```

The existing export and duplicate-fingerprint rules remain in effect. Use
`--force` only when deliberately overriding those existing rules. Preparation
archives the exact dataset and schema to the configured private ML disk. It
retains their hashes and byte counts, resolved options, and the Python package
source hash. Failure before successful remote preparation does not expose a task.
`--no-promote` prevents promotion of the previous challenger during preparation;
the worker has no promotion operation.

On the Mac, use Python 3.11 and install the selected sport's dependencies using
[the NFL package guide](../../ml/nfl/README.md) or
[the MLB package guide](../../ml/mlb/README.md). Use a source checkout matching the
prepared package hash. The client does not download or execute server-supplied
code or shell commands.

```bash
python3.11 scripts/ml_worker.py --url=https://YOUR_PICKSPORTS_HOST --token-file=/private/worker-directory/mini.token --repo=/absolute/path/to/picksports --workdir=/private/worker-directory/runs
```

This is a one-shot client, processing at most one assigned cycle. `--cycle=UUID`
selects a particular assignment or retrieves its completion receipt. Automatic
startup, polling supervision, heartbeats and a worker dashboard are separate
work; this change does not install a service on the Mac.

The token file must be owned by the current user and private (0600). The work
directory must be owned by the current user and private (0700). The client
requires an HTTPS origin and refuses redirects. Inputs are streamed and verified
against size and SHA-256 before atomic local replacement. Uploads are streamed in
1 MiB chunks. Task state and logs stay private. Child Python receives thread
limits and a small runtime environment; it does not inherit database, storage,
or worker credential variables.

## API contract

Base: `/api/ml-worker/v1`. Every endpoint requires the dedicated bearer credential;
responses are private and non-cacheable. No production paths, disk names, object
keys, environment settings or command strings are returned.

| Method / route | Ability | Result |
| --- | --- | --- |
| GET `/cycles` | read | Up to 50 assigned unfinished, non-exhausted cycles, oldest first |
| POST `/cycles/{cycle}/claim` | claim | Attempt/run IDs, owner token, lease expiry, input size/hash descriptors, source hash, bounded execution options |
| GET `/cycles/{cycle}/attempts/{attempt}/inputs/{dataset\|schema}` | read | Verified private input bytes |
| PUT `/cycles/{cycle}/attempts/{attempt}/files` | upload | Immutable file receipt |
| POST `/cycles/{cycle}/attempts/{attempt}/complete` | complete | Durable registered challenger receipt |
| POST `/cycles/{cycle}/attempts/{attempt}/fail` | complete | Generic failure receipt; raw worker errors are not accepted |

Attempt operations require `X-Training-Owner`. Uploads use raw
`application/octet-stream` bytes and require `Content-Length`, `X-Artifact-Path`,
and lowercase `X-Content-SHA256`. Only canonical relative root filenames and
single-level `models/`, `calibrators/`, `explanations/`, and `tuning/` paths are
accepted. No ZIP extraction or client-selected production/storage path occurs.

Completion requires `X-Result-SHA256`. Its canonical hash is SHA-256 over entries
sorted by path, each encoded as UTF-8:
`path + NUL + file_sha256 + NUL + decimal_byte_count + newline`.
The server derives entries from its immutable upload receipts, not caller JSON.
A completion hash covers all files, including `manifest.json`. The manifest's
artifact inventory must exactly match the other uploaded paths, hashes and sizes.
Existing sport bundle validators also verify required files, report identity and
lineage. The uploaded schema must match the prepared schema byte-for-byte, and
run, dataset, sport/model version and source identities must match the assignment.

Typical errors: 401 invalid/rotated/expired/revoked credential; 403 missing ability;
404 disabled service or inaccessible assignment; 409 stale lease/owner, conflicting
replay or refused completion; 413 transfer quota exceeded; 422 malformed headers,
hash/size/inventory/lineage mismatch; 429 throttle or staging reservation busy.
Worker-facing errors do not return internal file paths or raw exception details.

## Bounds, ownership and recovery

Defaults are 64 files and 1 GiB per attempt, with 256 MiB per binary file. Manifest
and schema limits are 256 KiB; JSON reports have a 2 MiB cap. Metadata caps are
checked before reading/materializing/decoding; manifest decoding is limited to
64 levels and its inventory count/shape is bounded before bulk materialization.

Before reading upload bytes, a database reservation enforces one active transfer
per worker, at most four active transfers globally and at most 512 MiB reserved
staging bytes. File-count/total-byte quotas are also checked before reading.
Request-body streaming runs outside SQL locks; each chunk rechecks the transfer
owner and expiry.
A transfer lease lasts 600 seconds. Completion/failed transfer releases its slot;
expired slots and their staged files are reclaimed by the next admitted transfer.
Lost PHP processes therefore do not hold permanent reservations. HTTP/PHP/proxy
request timeouts must remain below this reservation window; this code does not
replace infrastructure timeouts or OS disk monitoring. Expired-slot cleanup
cannot reclaim an OS-open file while its process is blocked in a read; request
and read deadlines must terminate those processes before admission is enabled.

The 512 MiB setting is an **upload reservation budget, not a hard limit on all
staging disk usage**. Completion separately materializes and copies up to the
1 GiB per-attempt artifact limit into the staging root while holding its worker's
completion locks. Completions for different workers can overlap and are not
covered by the global upload reservation. Until completion also has an aggregate
reservation, or independently verified infrastructure capacity/concurrency limits
bound this work, aggregate completion staging remains an explicit resource gate
for enabling this disabled feature. Include verified artifact caches, retained
objects, expired/open transfers and overlapping completions in disk sizing.

Attempt leases and retry limits remain governed by the Step 2 implementation:
training timeout plus 600 seconds, three attempts by default, no automatic lease
renewal. Downloads/uploads also consume that window. Repeated claims recover the
same active owner; expired claims allocate a new attempt/run ID and abandon the
old owner. Expired or replaced owners cannot download, upload, report failure or
complete. A completed claim returns its saved receipt. Duplicate upload bytes
return the same file receipt; changed bytes at the same path are rejected.

Rerun the one-shot client after an interrupted transfer. It reuses verified input
files and an already completed local run, and retries immutable transfers. After
training failure, the client reports a generic failure; the next claim uses a new
attempt. After source mismatch, use the matching checkout before rerunning. A
persistent conflict or exhausted cycle requires operator review, not `--force`
on a worker endpoint. Local logs and uploaded objects are retained for review;
there is no automatic remote artifact garbage collector in this step.

SQL completion atomically records registration/evaluation/shadow selection and
the attempt/cycle receipt. **Object storage is not transactional.** Existing
registrars write private immutable objects while SQL completion holds its locks.
A crash/refused completion can leave unreferenced private objects. Retrying the
same returned run uses the same immutable object identities and contents; tests
exercise failure after real local-disk object writes and successful replay without
creating extra objects. Expired attempts use a new run UUID. No exactly-once
external-storage or live S3 failure claim is made.

## Verification and deployment gates

Local tests cover both sport registrars through the API, capability/sport/worker
isolation, private inputs, hash/size/path limits, staging admission before body
reads, reservation recovery, stale owners, duplicate completion, SQL rollback
with retained storage objects, and credential lifecycle. Standard-library Python
tests cover HTTPS/redirect restrictions, private token files, source identity,
streaming, input corruption and the sanitized child environment.

Before any future deployment: apply the Step 2 attempt migration and additive
`2026_11_26_020000_create_ml_worker_transfer_tables.php`; drain old training code;
verify private ML storage, HTTPS/proxy settings, upload/request size limits and
request timeouts; benchmark server completion and Mac memory/runtime; and verify
MySQL concurrency plus real object storage/network retries. Close the aggregate
completion-staging capacity/concurrency gate and verify blocked-read termination
before enabling remote admission. Configure any request
header/body collectors to exclude worker credentials, owner headers and task
responses. Leave the feature disabled until those checks and deployment review.
Rollback disables remote admission and retains credential, attempt, transfer and
upload audit tables. Existing local schedules remain the fallback.

## Training operations dashboard (Step 4)

`ML_TRAINING_MONITORING_ENABLED=false` is a separate default-off gate. Its
additive migration is `2026_11_26_030000_create_training_monitoring_tables.php`.
Apply it before enabling monitoring. Both existing NFL/MLB admin model monitors
include a shared training panel, even when no artifact exists. The private
`GET /admin/training-operations/{nfl|mlb}` snapshot endpoint requires the existing
admin session authorization. The panel refreshes only this lightweight endpoint
every 30 seconds while visible; it does not rerun model-quality queries or trigger
training, provider calls, promotion or recovery.

Snapshots include up to 50 sport-scoped workers and 25 recent weekly cycles,
with the latest 10 attempt receipts per cycle. Results link to the existing
artifact evaluation and lineage panels; a completed training job does not imply
a promoted model or an approved betting selection. No credential hash, owner
token, production path, storage key or full training specification is serialized.

The worker client reports every 30 seconds while the one-shot process runs and
at major stages. It sends CPU count/load, free/total disk, physical RAM where
available, and training-process RSS where the local `ps` command supports it.
RSS is not system memory pressure or a peak-memory benchmark. Missing metrics
remain unavailable. Worker version is `picksports-worker/2`. A stopped one-shot
client eventually stops reporting even if the Mac remains powered on; this step
does not install a daemon or infer machine power/network status.

The protocol adds two explicitly authenticated, throttled routes:

| Method / route | Ability | Behavior |
| --- | --- | --- |
| POST `/api/ml-worker/v1/heartbeat` | report | Bounded metric snapshot and server-observed last-seen time |
| POST `/api/ml-worker/v1/cycles/{cycle}/attempts/{attempt}/progress` | report | Owner-fenced ordered stage/log snapshot |

Newly issued credentials include `training:report`. Existing credentials keep
their scopes on rotation unless the operator explicitly supplies `--report`:

```bash
php artisan ml:worker-credential rotate --worker=WORKER_UUID --report --output=/private/operator-directory/mini-reporting.token
```

Older clients/credentials can still train and transfer artifacts without reporting.
Disabled/unavailable telemetry, network errors or missing reporting permission do
not change training execution, lease duration, completion, promotion or retries.
Reporting uses short request deadlines and remains best effort.

Heartbeat bodies contain a `metrics` object with only the documented version and
numeric resource fields. Progress requires `X-Training-Owner` plus integer
`sequence`, stage (`claimed`, `downloading`, `training_and_evaluation`, `uploading`,
`completing`) and nullable `log_tail`. Bodies are capped at 32 KiB, log snapshots at
8 KiB, and stages/metrics are allowlisted. Lower/equal sequence updates cannot
replace newer snapshots. Reclaiming a claim returns the last accepted sequence;
expired/superseded owners and out-of-sport/worker reporters cannot update progress.
Heartbeats do not renew leases. Active local executors also report a bounded log
tail through an optional output observer; monitoring exceptions are isolated from
execution. Full logs stay private on the worker; this is not a full log archive.

Known bearer/owner values, token-shaped values, credential assignments and control
codes are redacted from persisted tails. Admin rendering treats logs as plain
text, including HTML-shaped output. Operators should still avoid writing secrets
into process output. Completed and failed cycle states override stale reported
stages. A missed heartbeat becomes unreachable after 180 seconds, independently
of job status; lease expiry is shown as needing recovery, not proof of termination.
Failed dashboard refreshes mark reachability unknown rather than continuing to
present an old online status as current. No alerts/messages are sent by this step.

Step 3's disabled-remote resource and infrastructure gates remain open. This UI
and telemetry work does not close aggregate completion staging, blocked-read
termination, live storage/network retry or Mac performance acceptance conditions.
