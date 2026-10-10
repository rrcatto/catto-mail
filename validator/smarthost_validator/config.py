"""Configuration from the environment contract (docs/contracts/environment.md).

Only contract variables are read. Secrets support the `X` / `X_FILE` indirection
(both set is an error). Startup fails closed on missing or invalid values and on
unsafe probe settings:

* production: VALIDATOR_SMTP_ROUTE_OVERRIDE must be empty;
* development/test: SMTP probing is allowed only through the route override (the
  fake SMTP service), so automated and local runs never probe real mail servers.
"""
from __future__ import annotations

import ipaddress
import os
import socket
from collections.abc import Mapping
from dataclasses import dataclass
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError


class ConfigError(Exception):
    pass


ENVIRONMENTS = ("development", "test", "production")


@dataclass(frozen=True)
class Config:
    smarthost_env: str
    log_level: str
    log_format: str
    timezone: ZoneInfo  # the installation's zone (SMARTHOST_TIMEZONE): logs, timestamps, database session
    db_host: str
    db_port: int
    db_name: str
    db_sslmode: str
    db_user: str
    db_password: str
    worker_id: str
    chunk_size: int
    lease_seconds: int
    poll_interval_seconds: float
    global_concurrency: int
    per_domain_concurrency: int
    per_mx_concurrency: int
    max_attempts: int
    retry_base_seconds: int
    retry_max_seconds: int
    dns_resolvers: tuple[str, ...]
    dns_timeout_seconds: float
    smtp_probe_enabled: bool
    smtp_route_override: tuple[str, int] | None
    smtp_helo_hostname: str
    smtp_mail_from: str
    smtp_connect_timeout_seconds: float
    smtp_command_timeout_seconds: float

    @property
    def conninfo(self) -> str:
        from psycopg.conninfo import make_conninfo

        return make_conninfo(host=self.db_host, port=self.db_port, dbname=self.db_name, sslmode=self.db_sslmode,
                             user=self.db_user, password=self.db_password, connect_timeout=10,
                             application_name="smarthost-validator", options=f"-c timezone={self.timezone.key}")

    @classmethod
    def from_env(cls, env: Mapping[str, str] | None = None) -> Config:
        e = dict(os.environ if env is None else env)
        r = _Reader(e)
        smarthost_env = r.choice("SMARTHOST_ENV", ENVIRONMENTS)
        override = r.optional("VALIDATOR_SMTP_ROUTE_OVERRIDE")
        route = _host_port(override, "VALIDATOR_SMTP_ROUTE_OVERRIDE") if override else None
        probe = r.boolean("VALIDATOR_SMTP_PROBE_ENABLED")
        if smarthost_env == "production" and route is not None:
            raise ConfigError("VALIDATOR_SMTP_ROUTE_OVERRIDE must be empty in production.")
        if smarthost_env != "production" and probe and route is None:
            raise ConfigError("Outside production, SMTP probing requires VALIDATOR_SMTP_ROUTE_OVERRIDE (no live probing).")
        resolvers = tuple(x.strip() for x in r.optional("VALIDATOR_DNS_RESOLVERS").split(",") if x.strip())
        for ip in resolvers:
            try:
                ipaddress.ip_address(ip)
            except ValueError as exc:
                raise ConfigError(f"VALIDATOR_DNS_RESOLVERS: {ip!r} is not an IP address.") from exc
        worker_id = r.optional("VALIDATOR_WORKER_ID") or socket.gethostname()
        cfg = cls(
            smarthost_env=smarthost_env,
            log_level=r.choice("SMARTHOST_LOG_LEVEL", ("debug", "info", "warning", "error")),
            log_format=r.choice("SMARTHOST_LOG_FORMAT", ("json", "text"), default="json"),
            timezone=_zone(r.required("SMARTHOST_TIMEZONE")),
            db_host=r.required("SMARTHOST_DB_HOST"),
            db_port=r.integer("SMARTHOST_DB_PORT", 1, 65535),
            db_name=r.required("SMARTHOST_DB_NAME"),
            db_sslmode=r.required("SMARTHOST_DB_SSLMODE"),
            db_user=r.required("VALIDATOR_DB_USER"),
            db_password=r.secret("VALIDATOR_DB_PASSWORD"),
            worker_id=worker_id,
            chunk_size=r.integer("VALIDATOR_CHUNK_SIZE", 1, 1000),
            lease_seconds=r.integer("VALIDATOR_LEASE_SECONDS", 5, 86400),
            poll_interval_seconds=float(r.integer("VALIDATOR_POLL_INTERVAL_SECONDS", 1, 3600)),
            global_concurrency=r.integer("VALIDATOR_GLOBAL_CONCURRENCY", 1, 1000),
            per_domain_concurrency=r.integer("VALIDATOR_PER_DOMAIN_CONCURRENCY", 1, 100),
            per_mx_concurrency=r.integer("VALIDATOR_PER_MX_CONCURRENCY", 1, 100),
            max_attempts=r.integer("VALIDATOR_MAX_ATTEMPTS", 1, 100),
            retry_base_seconds=r.integer("VALIDATOR_RETRY_BASE_SECONDS", 1, 86400),
            retry_max_seconds=r.integer("VALIDATOR_RETRY_MAX_SECONDS", 1, 7 * 86400),
            dns_resolvers=resolvers,
            dns_timeout_seconds=float(r.integer("VALIDATOR_DNS_TIMEOUT_SECONDS", 1, 120)),
            smtp_probe_enabled=probe,
            smtp_route_override=route,
            smtp_helo_hostname=r.required("VALIDATOR_SMTP_HELO_HOSTNAME"),
            smtp_mail_from=r.required("VALIDATOR_SMTP_MAIL_FROM"),
            smtp_connect_timeout_seconds=float(r.integer("VALIDATOR_SMTP_CONNECT_TIMEOUT_SECONDS", 1, 600)),
            smtp_command_timeout_seconds=float(r.integer("VALIDATOR_SMTP_COMMAND_TIMEOUT_SECONDS", 1, 600)),
        )
        if cfg.retry_max_seconds < cfg.retry_base_seconds:
            raise ConfigError("VALIDATOR_RETRY_MAX_SECONDS must not be below VALIDATOR_RETRY_BASE_SECONDS.")
        if "@" not in cfg.smtp_mail_from or any(c in cfg.smtp_mail_from for c in "<>\r\n "):
            raise ConfigError("VALIDATOR_SMTP_MAIL_FROM must be a plain address.")
        if any(c in cfg.smtp_helo_hostname for c in "\r\n "):
            raise ConfigError("VALIDATOR_SMTP_HELO_HOSTNAME must be a host name.")
        return cfg


def _host_port(value: str, name: str) -> tuple[str, int]:
    host, sep, port = value.rpartition(":")
    if not sep or not host or not port.isdigit() or not 1 <= int(port) <= 65535:
        raise ConfigError(f"{name} must be host:port.")
    return host, int(port)


class _Reader:
    def __init__(self, env: dict[str, str]) -> None:
        self.env = env

    def optional(self, name: str) -> str:
        return self.env.get(name, "").strip()

    def required(self, name: str) -> str:
        value = self.optional(name)
        if not value:
            raise ConfigError(f"Missing required variable {name}.")
        return value

    def secret(self, name: str) -> str:
        direct, file_name = self.env.get(name, ""), self.env.get(name + "_FILE", "")
        if direct and file_name:
            raise ConfigError(f"Both {name} and {name}_FILE are set; set only one.")
        if file_name:
            try:
                with open(file_name, encoding="utf-8") as fh:
                    value = fh.read().rstrip("\r\n")
            except OSError as exc:
                raise ConfigError(f"{name}_FILE is not readable.") from exc
        else:
            value = direct
        if not value:
            raise ConfigError(f"Missing required secret {name} (or {name}_FILE).")
        return value

    def integer(self, name: str, low: int, high: int) -> int:
        raw = self.required(name)
        if not raw.isdigit() or not low <= int(raw) <= high:
            raise ConfigError(f"{name} must be an integer between {low} and {high}.")
        return int(raw)

    def boolean(self, name: str) -> bool:
        raw = self.optional(name) or "false"
        if raw not in ("true", "false"):
            raise ConfigError(f"{name} must be true or false.")
        return raw == "true"

    def choice(self, name: str, allowed: tuple[str, ...], default: str | None = None) -> str:
        raw = self.optional(name) or (default or "")
        if raw not in allowed:
            raise ConfigError(f"{name} must be one of {', '.join(allowed)}.")
        return raw


def _zone(name: str) -> ZoneInfo:
    try:
        if name in ("Local", "localtime") or name.startswith(("/", ".")):
            raise ValueError(name)
        return ZoneInfo(name)
    except (ZoneInfoNotFoundError, ValueError) as exc:
        raise ConfigError(f"SMARTHOST_TIMEZONE must be an IANA time zone name such as Africa/Johannesburg, not {name!r}.") from exc
