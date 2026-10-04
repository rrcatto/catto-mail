#!/usr/bin/env python3
"""Smarthost contract consistency check (specification 2.3).

Cross-checks the canonical YAML specification, the human specification and the
normative contract files so they cannot drift silently:

  * spec <-> vocabulary: every value enumerated in the spec exists in
    docs/contracts/status-vocabulary.yaml, and every value marked
    `origin: spec` really is enumerated in the spec;
  * vocabulary integrity: transitions, ranks, terminal flags, event status
    effects and event sources;
  * vocabulary -> OpenAPI enums and -> reference DDL CHECK constraints;
  * spec -> OpenAPI: endpoints, limits, idempotency, no client_id in bodies;
  * spec -> DDL/schema.md: tables, key fields, required indexes, documentation;
  * environment contract <-> infra/.env.example: same variables, secrets
    empty, safety switches safe, limits within contract ceilings;
  * human specification: version, required topics, endpoints, statuses, events;
  * stale-term scan across all artefacts for concepts the spec has removed.

Read-only: it never modifies files. Exit status 0 = all checks pass, 1 = failures.
Requires Python 3.10+ and PyYAML.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover - environment guard
    sys.exit("check-contracts: PyYAML is required (python3 -m pip install pyyaml)")

ROOT = Path(__file__).resolve().parent.parent
SPEC = ROOT / "docs/20260908-1644-smarthost-llm-spec.yaml"
HUMAN = ROOT / "docs/20260908-1644-smarthost-human-specification.md"
VOCAB = ROOT / "docs/contracts/status-vocabulary.yaml"
OPENAPI = ROOT / "docs/api/openapi.v1.yaml"
DDL = ROOT / "docs/schema/reference-schema.sql"
SCHEMA_MD = ROOT / "docs/schema/schema.md"
ENV_MD = ROOT / "docs/contracts/environment.md"
ENV_EXAMPLE = ROOT / "infra/.env.example"
LICENSE_FILE = ROOT / "LICENSE"
DECISION_LOG = ROOT / "docs/architecture/open-decisions.md"
OTHER_DOCS = [
    ROOT / "docs/architecture/overview.md",
    ROOT / "docs/architecture/conventions.md",
    ROOT / "docs/architecture/postfix-integration.md",
]

EXPECTED_SPEC_VERSION = "2.3"
EXPECTED_SPEC_DATE = "2026-10-04"
# Decisions that must be incorporated across the revision history (D-30 is still open).
EXPECTED_DECISIONS = {f"D-{n:02d}" for n in range(1, 36)} - {"D-30"}

# vocabulary key -> path of the enumerating list in the spec
SPEC_LISTS = {
    "overall_classification": ("validation", "result_model", "overall_classifications"),
    "validation_job_status": ("validation", "job_statuses"),
    "send_job_status": ("sending", "send_job_statuses"),
    "message_class": ("sending", "message_classes"),
    "typo_reason_code": ("validation", "typo_suggestions", "reason_codes"),
    "confidence_level": ("validation", "typo_suggestions", "confidence_levels"),
    "message_status": ("message_tracking", "current_statuses"),
    "message_event_type": ("message_tracking", "event_model", "event_types"),
    "event_source": ("message_tracking", "event_model", "event_sources"),
    "failure_scope": ("suppression_and_reputation", "repeated_soft_bounce", "failure_scopes"),
    "suppression_reason": ("suppression_and_reputation", "smarthost_suppression_reasons"),
    "webhook_event_type": ("api", "webhooks", "events_initial"),
    "dsn_classification": ("inbound_bounce_handling", "classifications"),
    "unmatched_dsn_status": ("inbound_bounce_handling", "unmatched_dsns", "statuses"),
    "sending_domain_status": ("sending", "sending_domains", "statuses"),
    "dkim_status": ("sending", "sending_domains", "dkim_statuses"),
}

# OpenAPI component schema -> vocabulary key (enums must be identical sets)
OPENAPI_ENUMS = {
    "ValidationJobStatus": "validation_job_status",
    "SendJobStatus": "send_job_status",
    "MessageClass": "message_class",
    "SyntaxStatus": "syntax_status",
    "DomainStatus": "domain_status",
    "SmtpStatus": "smtp_status",
    "OverallClassification": "overall_classification",
    "ConfidenceLevel": "confidence_level",
    "TypoReasonCode": "typo_reason_code",
    "MessageStatus": "message_status",
    "MessageEventType": "message_event_type",
    "WebhookEventType": "webhook_event_type",
}
OPENAPI_COUNT_OBJECTS = {
    "ClassificationCounts": "overall_classification",
    "MessageStatusCounts": "message_status",
}

# (table, column) in the reference DDL -> vocabulary key
DDL_ENUMS = {
    ("clients", "status"): "client_status",
    ("users", "status"): "user_status",
    ("users", "global_role"): "global_role",
    ("client_memberships", "role"): "client_membership_role",
    ("sending_domains", "status"): "sending_domain_status",
    ("sending_domains", "dkim_status"): "dkim_status",
    ("validation_jobs", "status"): "validation_job_status",
    ("validation_addresses", "syntax_status"): "syntax_status",
    ("validation_addresses", "domain_status"): "domain_status",
    ("validation_addresses", "smtp_status"): "smtp_status",
    ("validation_addresses", "suggestion_reason_code"): "typo_reason_code",
    ("validation_addresses", "suggestion_confidence"): "confidence_level",
    ("validation_addresses", "overall_classification"): "overall_classification",
    ("validation_addresses", "confidence"): "confidence_level",
    ("validation_addresses", "processing_state"): "validation_processing_state",
    ("validation_evidence", "evidence_type"): "validation_evidence_type",
    ("send_jobs", "message_class"): "message_class",
    ("send_jobs", "status"): "send_job_status",
    ("messages", "current_status"): "message_status",
    ("message_events", "event_type"): "message_event_type",
    ("message_events", "event_source"): "event_source",
    ("message_events", "failure_scope"): "failure_scope",
    ("unmatched_dsns", "classification"): "dsn_classification",
    ("unmatched_dsns", "status"): "unmatched_dsn_status",
    ("suppressions", "scope_type"): "suppression_scope_type",
    ("suppressions", "reason"): "suppression_reason",
    ("usage_records", "usage_type"): "usage_type",
    ("usage_records", "reference_type"): "usage_reference_type",
    ("webhook_endpoints", "status"): "webhook_endpoint_status",
    ("webhook_events", "event_type"): "webhook_event_type",
    ("webhook_events", "subject_type"): "webhook_subject_type",
    ("webhook_deliveries", "event_type"): "webhook_event_type",
    ("webhook_deliveries", "status"): "webhook_delivery_status",
    ("audit_log", "actor_type"): "audit_actor_type",
}

ENV_CONSUMERS = {"app", "webhook-worker", "validator", "delivery", "postfix", "opendkim",
                 "postgres", "bootstrap", "mailpit", "fake-smtp", "proxy"}

# Concepts the specification has removed; they must not reappear in any
# authoritative artefact (the decision log may still mention them).
STALE_TERMS = {
    r"\bunsubscribe_signal\b": "removed event",
    r"\bsuppression_matched\b": "renamed to message_suppressed",
    r"APP_TRACKING_TOKEN_KEY": "tracking tokens are random, no shared key",
    r"\bclient_reference\b": "use external_reference (D-19)",
    r"html_body_or_template_reference|text_body_or_template_reference": "no templates in Smarthost (D-08)",
    r"signed token|signed_tracking_pixel|signed_redirect|signed or cryptographically random":
        "tracking tokens are opaque random values, not signed",
    r"`running`|`paused`": "job states standardised (D-29)",
    r"current_status_examples|event_types_initial|decision_record": "superseded spec structure",
}

HUMAN_REQUIRED_TOPICS = [
    "fully rendered", "send_job_recipients", "`collecting`", "`dispatched`", "`completed`",
    "transactional outbox", "webhook worker", "nginx", "PHP-FPM", "FastCGI", "OpenDKIM",
    "192 bits", "subscription", "transactional", "_smarthost-verification", "transient",
    "reconciliation", "outcome_unknown", "unmatched DSN", "compliance gate", "at most 500", "10 MiB",
    "List-Unsubscribe-Post", "external_address_reference", "lease_expires_at",
    "source event key", "Reply-To", "Revision History",
]

failures: list[str] = []
passes = 0


def check(condition: bool, message: str) -> None:
    global passes
    if condition:
        passes += 1
    else:
        failures.append(message)


def load_yaml(path: Path):
    with path.open(encoding="utf-8") as fh:
        return yaml.safe_load(fh)


def dig(data, path):
    for key in path:
        data = data[key]
    return data


def vocab_values(vocab: dict, key: str) -> set[str]:
    return set(vocab[key]["values"].keys())


# ---------------------------------------------------------------------------
def check_spec_metadata(spec: dict) -> None:
    project = spec["project"]
    check(project["specification_version"] == EXPECTED_SPEC_VERSION,
          f"spec: specification_version must be {EXPECTED_SPEC_VERSION}")
    check(project["specification_date"] == EXPECTED_SPEC_DATE,
          f"spec: specification_date must be {EXPECTED_SPEC_DATE}")
    history = spec.get("revision_history", [])
    latest = next((h for h in history if h.get("version") == EXPECTED_SPEC_VERSION), {})
    check(bool(latest), "spec: revision_history lacks the current version")
    incorporated = {d for h in history for d in h.get("decisions_incorporated", [])}
    missing = sorted(EXPECTED_DECISIONS - incorporated)
    check(not missing, f"spec: revision history missing decisions {missing}")
    for name, rel in spec["instruction_for_llm"]["normative_contracts"].items():
        check((ROOT / rel).is_file(), f"spec: normative contract '{name}' missing at {rel}")


def check_normalization_vectors(spec: dict) -> None:
    """D-32: the shared vector contract is well formed (implementations test the values)."""
    rel = spec["instruction_for_llm"]["normative_contracts"].get("address_normalization_vectors")
    check(rel is not None, "spec: address_normalization_vectors contract not listed")
    if rel is None or not (ROOT / rel).is_file():
        return
    data = json.loads((ROOT / rel).read_text(encoding="utf-8"))
    vectors = data.get("vectors", [])
    check(len(vectors) >= 50, "vectors: fewer than 50 address-normalisation vectors")
    inputs = [v.get("input") for v in vectors]
    check(len(inputs) == len(set(inputs)), "vectors: duplicate inputs")
    for v in vectors:
        ok = isinstance(v.get("input"), str) and (v.get("normalized") is None or isinstance(v.get("normalized"), str)) \
            and isinstance(v.get("note"), str) and set(v) == {"input", "normalized", "note"}
        check(ok, f"vectors: malformed vector {v!r}")
        n = v.get("normalized")
        if isinstance(n, str) and "@" in n:
            local, domain = n.rsplit("@", 1)
            check(domain == domain.lower() and domain.isascii(), f"vectors: domain not lower-case ASCII in {n!r}")
            check(local in v["input"], f"vectors: local part not preserved byte-for-byte in {n!r}")


def check_vocabulary(spec: dict, vocab: dict) -> None:
    for key, path in SPEC_LISTS.items():
        spec_values = dig(spec, path)
        check(len(spec_values) == len(set(spec_values)), f"spec: duplicate values in {'.'.join(path)}")
        values = vocab[key]["values"]
        for v in spec_values:
            check(v in values, f"vocabulary.{key}: spec value '{v}' missing")
        for v, meta in values.items():
            if meta.get("origin") == "spec":
                check(v in spec_values, f"vocabulary.{key}.{v}: origin=spec but not in spec {'.'.join(path)}")

    for key, block in vocab.items():
        if not (isinstance(block, dict) and "values" in block):
            continue
        for v, meta in block["values"].items():
            origin = meta.get("origin") if isinstance(meta, dict) else None
            check(origin in {"spec", "contract"}, f"vocabulary.{key}.{v}: origin must be spec|contract")
            if origin == "spec":
                check(key in SPEC_LISTS, f"vocabulary.{key}.{v}: origin=spec but no spec list maps to '{key}'")
        if "transitions" in block:
            for src, dests in block["transitions"].items():
                check(src in block["values"], f"vocabulary.{key}.transitions: unknown source '{src}'")
                check(not block["values"].get(src, {}).get("terminal", False),
                      f"vocabulary.{key}.transitions: terminal state '{src}' has outgoing transitions")
                for d in dests:
                    check(d in block["values"], f"vocabulary.{key}.transitions: unknown target '{d}'")

    statuses = vocab["message_status"]["values"]
    for st, meta in statuses.items():
        check(isinstance(meta.get("rank"), int), f"vocabulary.message_status.{st}: rank missing")
        check(isinstance(meta.get("terminal"), bool), f"vocabulary.message_status.{st}: terminal missing")
    # outcome_unknown must be supersedable by every authoritative transport outcome (D-27)
    for st in ("remote_accepted", "soft_bounced", "hard_bounced", "complained"):
        check(statuses[st]["rank"] > statuses["outcome_unknown"]["rank"],
              f"vocabulary: {st} must outrank outcome_unknown so it can supersede it")
    check(statuses["outcome_unknown"]["terminal"] is True, "vocabulary: outcome_unknown must be terminal")

    sources = vocab_values(vocab, "event_source")
    for ev, meta in vocab["message_event_type"]["values"].items():
        target = meta.get("sets_status")
        check(target is None or target in statuses,
              f"vocabulary.message_event_type.{ev}: sets_status '{target}' unknown")
        check(bool(meta.get("sources")) and set(meta["sources"]) <= sources,
              f"vocabulary.message_event_type.{ev}: sources must be non-empty event_source values")
    for cls, meta in vocab["dsn_classification"]["values"].items():
        check(meta.get("message_event") in vocab["message_event_type"]["values"],
              f"vocabulary.dsn_classification.{cls}: unknown message_event")


# ---------------------------------------------------------------------------
def check_licence(api: dict) -> None:
    """The OpenAPI licence metadata must match the repository LICENSE (MIT)."""
    first_line = LICENSE_FILE.read_text(encoding="utf-8").splitlines()[0].strip() if LICENSE_FILE.is_file() else ""
    check(first_line == "MIT License", f"LICENSE: expected an MIT licence file, found {first_line!r}")
    lic = api.get("info", {}).get("license", {})
    check(lic.get("name") == "MIT" and lic.get("identifier") == "MIT",
          f"openapi: info.license must be name=MIT identifier=MIT to match LICENSE, found {lic}")


def check_openapi(spec: dict, vocab: dict, api: dict) -> None:
    schemas = api["components"]["schemas"]
    for name, key in OPENAPI_ENUMS.items():
        enum = schemas.get(name, {}).get("enum")
        check(enum is not None, f"openapi: schema {name} missing enum")
        if enum is not None:
            check(len(enum) == len(set(enum)), f"openapi: {name} has duplicate enum values")
            diff = sorted(set(enum) ^ vocab_values(vocab, key))
            check(not diff, f"openapi: {name} enum differs from vocabulary.{key}: {diff}")
    for name, key in OPENAPI_COUNT_OBJECTS.items():
        req = set(schemas[name]["required"])
        check(req == vocab_values(vocab, key), f"openapi: {name}.required differs from vocabulary.{key}")
        check(set(schemas[name]["properties"]) == req, f"openapi: {name} properties differ from required")

    check(api["servers"][0]["url"].rstrip("/").endswith(spec["api"]["base_path"]),
          "openapi: server URL does not end with spec base_path")
    spec_ops = {(ep["path"], ep["method"].lower()) for ep in spec["api"]["endpoints_initial"]}
    api_ops = {(p, m) for p, item in api["paths"].items() for m in item
               if m in {"get", "post", "put", "patch", "delete"}}
    for path, method in sorted(spec_ops):
        check((path, method) in api_ops, f"openapi: spec endpoint {method.upper()} {path} missing")
    for path, method in sorted(api_ops - spec_ops):
        check(False, f"openapi: endpoint {method.upper()} {path} is not in spec api.endpoints_initial")

    ingestion = spec["sending"]["ingestion"]
    check(schemas["ValidationJobCreateRequest"]["properties"]["addresses"]["maxItems"]
          == spec["validation"]["max_addresses_per_public_job"],
          "openapi: validation addresses maxItems differs from spec")
    check(schemas["RecipientBatchRequest"]["properties"]["recipients"]["maxItems"]
          == ingestion["max_recipients_per_batch"],
          "openapi: recipient batch maxItems differs from spec max_recipients_per_batch")
    check(schemas["RecipientBatchResult"]["properties"]["total_recipients"]["maximum"]
          == ingestion["max_recipients_per_job"]["default"],
          "openapi: job recipient ceiling differs from spec max_recipients_per_job")
    check("recipients" not in schemas["SendJobCreateRequest"]["properties"],
          "openapi: send-job creation must accept job-level metadata only (D-24)")
    for name in ("ValidationJobCreateRequest", "ValidationAddressInput", "SendJobCreateRequest",
                 "Recipient", "RecipientBatchRequest"):
        props = schemas[name]["properties"]
        check("client_id" not in props, f"openapi: {name} must not accept client_id")
        check("headers" not in props, f"openapi: {name} must not accept arbitrary headers (D-25)")
    for path in ("/validation-jobs", "/send-jobs", "/send-jobs/{id}/recipients"):
        refs = [p.get("$ref") for p in api["paths"][path]["post"].get("parameters", [])]
        check("#/components/parameters/IdempotencyKey" in refs, f"openapi: POST {path} lacks Idempotency-Key")
    check(api["components"]["parameters"]["IdempotencyKey"]["required"] is True,
          "openapi: Idempotency-Key must be required")


# ---------------------------------------------------------------------------
def parse_ddl(sql: str) -> dict[str, str]:
    sql = re.sub(r"--[^\n]*", "", sql)
    return {m.group(1): m.group(2) for m in re.finditer(r"CREATE TABLE (\w+) \((.*?)\n\);", sql, re.S)}


def check_ddl(spec: dict, vocab: dict, sql: str, schema_md: str) -> None:
    tables = parse_ddl(sql)
    check(set(tables) == set(spec["schema"]["tables"]),
          f"ddl: table set differs from spec: {sorted(set(tables) ^ set(spec['schema']['tables']))}")
    check(set(tables) == set(spec["project_boundaries"]["data_ownership"]["smarthost_owns"]),
          "ddl: table set differs from spec project_boundaries.smarthost_owns")
    for table, definition in spec["schema"]["tables"].items():
        if table not in tables:
            continue
        columns = set(re.findall(
            r"^\s{4}(\w+)\s+(?:uuid|text|integer|bigint|smallint|boolean|jsonb|timestamptz)\b",
            tables[table], re.M))
        for field in definition["key_fields"]:
            col = re.sub(r"_(nullable_for_global|nullable)$", "", field)
            check(col in columns, f"ddl: {table}.{col} (spec key field '{field}') missing")
    for (table, column), key in DDL_ENUMS.items():
        body = tables.get(table, "")
        # The column definition runs to its first comma; CHECK may follow DEFAULT on the next line.
        m = re.search(rf"^\s{{4}}{column}\s[^,]*?CHECK \(\s*{column} IN \((.*?)\)\)", body, re.M | re.S)
        check(m is not None, f"ddl: {table}.{column} has no CHECK (... IN ...) constraint")
        if m:
            found = set(re.findall(r"'([^']+)'", m.group(1)))
            diff = sorted(found ^ vocab_values(vocab, key))
            check(not diff, f"ddl: {table}.{column} CHECK differs from vocabulary.{key}: {diff}")
    for idx in spec["schema"]["indexes_required"]:
        check(f"`{idx}`" in schema_md, f"schema.md: required index '{idx}' not documented")
    for table in tables:
        check(f"`{table}`" in schema_md or f" {table} " in schema_md,
              f"schema.md: table '{table}' not documented")
    statements = re.sub(r"--[^\n]*", "", sql)
    check(re.search(r"^\s*(GRANT|CREATE ROLE|ALTER ROLE)\b", statements, re.M | re.I) is None,
          "ddl: roles and grants are infrastructure (D-15) and must not be in the reference DDL")


# ---------------------------------------------------------------------------
def check_environment(spec: dict, api: dict, env_md: str, env_example: str) -> None:
    rows = re.findall(r"^\| `([A-Z][A-Z0-9_]*)` \| ([^|]+)\| (\*\*yes\*\*|no) \|", env_md, re.M)
    contract: dict[str, bool] = {}
    for name, consumers, secret in rows:
        check(name not in contract, f"environment.md: '{name}' listed twice")
        contract[name] = secret == "**yes**"
        unknown = {c.strip() for c in consumers.split(",")} - ENV_CONSUMERS
        check(not unknown, f"environment.md: {name} has unknown consumers {sorted(unknown)}")

    example: dict[str, str] = {}
    for lineno, line in enumerate(env_example.splitlines(), 1):
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        m = re.match(r"^([A-Z][A-Z0-9_]*)=(.*)$", line)
        check(m is not None, f".env.example:{lineno}: not KEY=value")
        if m:
            check(m.group(1) not in example, f".env.example: '{m.group(1)}' defined twice")
            example[m.group(1)] = m.group(2)

    check(set(contract) == set(example),
          f"environment contract and .env.example differ: {sorted(set(contract) ^ set(example))}")
    for name, is_secret in contract.items():
        if is_secret:
            check(example.get(name, "") == "", f".env.example: secret '{name}' must be empty")
    for name, value in example.items():
        if (re.search(r"(PASSWORD|SECRET|_KEYS?$|TOKEN|_DSN$)", name)
                and not re.search(r"_(FILE|SECONDS|HOURS|DAYS)$", name)):
            check(value == "", f".env.example: '{name}' looks secret but has a value")
    for name in ("SMARTHOST_LIVE_DELIVERY_ENABLED", "VALIDATOR_SMTP_PROBE_ENABLED",
                 "SMARTHOST_ALLOW_UNVERIFIED_SENDING_DOMAINS"):
        check(example.get(name) == "false", f".env.example: {name} must default to false")
    schemas = api["components"]["schemas"]
    limits = {
        "APP_SEND_JOB_MAX_RECIPIENTS": schemas["RecipientBatchResult"]["properties"]["total_recipients"]["maximum"],
        "APP_SEND_JOB_MAX_RECIPIENTS_PER_BATCH": schemas["RecipientBatchRequest"]["properties"]["recipients"]["maxItems"],
    }
    for name, ceiling in limits.items():
        value = example.get(name, "")
        check(value.isdigit() and 1 <= int(value) <= ceiling, f".env.example: {name} must be 1..{ceiling}")
    check(example.get("APP_API_MAX_REQUEST_BYTES") == str(10 * 1024 * 1024),
          ".env.example: APP_API_MAX_REQUEST_BYTES must stay 10 MiB (D-24)")
    check(example.get("APP_RETENTION_STAGED_CONTENT_DAYS") == "7",
          ".env.example: staged-content cleanup default must be 7 days (D-14)")
    for name, value in example.items():
        if name.startswith("APP_RETENTION_") and name != "APP_RETENTION_STAGED_CONTENT_DAYS":
            check(value == "", f".env.example: {name} must not hard-code a long-term period (D-14)")
    snapshots = example.get("DELIVERY_RECONCILE_MIN_SNAPSHOTS", "0")
    check(snapshots.isdigit() and int(snapshots) >= 2, ".env.example: reconciliation needs >= 2 snapshots (D-27)")
    for name in spec["suppression_and_reputation"]["repeated_soft_bounce"]["configuration"]:
        check(name in example, f".env.example: {name} (spec repeated_soft_bounce) missing")
    check(example.get("DELIVERY_SOFT_BOUNCE_SUPPRESSION_THRESHOLD") == "3"
          and example.get("DELIVERY_SOFT_BOUNCE_SUPPRESSION_WINDOW_DAYS") == "30",
          ".env.example: soft-bounce defaults must be 3 within 30 days (D-18)")


# ---------------------------------------------------------------------------
def check_human_spec(spec: dict, vocab: dict, human: str) -> None:
    check(re.search(r"\*\*Specification version:\*\*\s*" + re.escape(EXPECTED_SPEC_VERSION) + r"\b", human) is not None,
          f"human spec: version {EXPECTED_SPEC_VERSION} not declared")
    for topic in HUMAN_REQUIRED_TOPICS:
        check(topic.lower() in human.lower(), f"human spec: topic '{topic}' not covered")
    flat = re.sub(r" +", " ", human)
    for ep in spec["api"]["endpoints_initial"]:
        check(f"{ep['method']} /v1{ep['path']}" in flat,
              f"human spec: endpoint {ep['method']} /v1{ep['path']} not listed")
    for key in ("send_job_status", "validation_job_status", "message_status", "message_event_type"):
        for value in vocab_values(vocab, key):
            check(f"`{value}`" in human, f"human spec: {key} value `{value}` not described")


def spec_text_without_history(spec: dict) -> str:
    trimmed = {k: v for k, v in spec.items() if k != "revision_history"}
    return yaml.safe_dump(trimmed, sort_keys=False, width=1000)


def check_stale_terms(texts: dict[str, str]) -> None:
    for label, text in texts.items():
        for pattern, reason in STALE_TERMS.items():
            m = re.search(pattern, text)
            check(m is None, f"{label}: stale term '{m.group(0) if m else pattern}' ({reason})")
    for label in ("openapi", "ddl", "vocabulary", "human spec"):
        for term in ("merge_data", "template_reference"):
            check(term not in texts[label], f"{label}: forbidden term '{term}' (D-08)")


def check_decision_log(spec: dict, log: str) -> None:
    check("not a source of architectural authority" in log, "decision log: must declare it is not authority")
    latest = spec["revision_history"][-1]
    for d in latest["decisions_incorporated"]:
        check(d in log, f"decision log: {d} not recorded")


# ---------------------------------------------------------------------------
def main() -> int:
    spec = load_yaml(SPEC)
    vocab = load_yaml(VOCAB)
    api = load_yaml(OPENAPI)
    sql = DDL.read_text(encoding="utf-8")
    human = HUMAN.read_text(encoding="utf-8")

    check_spec_metadata(spec)
    check_normalization_vectors(spec)
    check_vocabulary(spec, vocab)
    check_openapi(spec, vocab, api)
    check_licence(api)
    check_ddl(spec, vocab, sql, SCHEMA_MD.read_text(encoding="utf-8"))
    check_environment(spec, api, ENV_MD.read_text(encoding="utf-8"), ENV_EXAMPLE.read_text(encoding="utf-8"))
    check_human_spec(spec, vocab, human)
    texts = {
        "spec": spec_text_without_history(spec),
        "human spec": human.split("# 23. Revision History")[0],
        "openapi": OPENAPI.read_text(encoding="utf-8"),
        "ddl": sql,
        "vocabulary": VOCAB.read_text(encoding="utf-8"),
        "environment": ENV_MD.read_text(encoding="utf-8") + ENV_EXAMPLE.read_text(encoding="utf-8"),
        "schema.md": SCHEMA_MD.read_text(encoding="utf-8"),
    }
    for doc in OTHER_DOCS:
        check(doc.is_file(), f"missing document {doc.relative_to(ROOT)}")
        if doc.is_file():
            texts[doc.name] = doc.read_text(encoding="utf-8")
    check_stale_terms(texts)
    check_decision_log(spec, DECISION_LOG.read_text(encoding="utf-8"))

    for f in failures:
        print(f"FAIL  {f}")
    print(f"check-contracts: {passes} passed, {len(failures)} failed")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
