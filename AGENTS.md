# Smarthost Development Instructions

Before doing any work, read:

1. docs/20260908-1644-smarthost-human-specification.md
2. docs/20260908-1644-smarthost-llm-spec.yaml

The YAML specification is authoritative for implementation requirements.
The Markdown specification explains the architecture and development phases.

Do not implement work from later phases unless explicitly instructed.
Do not change architectural decisions in the specifications without explicit approval.
Do not commit, push, tag, release, or bump versions unless explicitly instructed.
Use Podman, not Docker.
Run all applicable tests before reporting completion.
Report:
- files changed;
- implementation completed;
- tests run and results;
- anything unverified;
- any specification ambiguity encountered.
