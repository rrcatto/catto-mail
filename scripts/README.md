# scripts/ — explicit development and maintenance helpers

- `check-contracts.py` is the read-only contract consistency check. It cross-checks the canonical
  YAML specification, the human specification, the vocabulary, the OpenAPI document, the reference
  DDL, the schema documentation and the environment contract/template. It also scans for stale
  terms that the specification has removed. Requires Python 3.10+ and PyYAML.

  ```sh
  python3 scripts/check-contracts.py
  ```
