from smarthost_validator.config import Config
from tests.test_config import BASE


def config(**overrides: str) -> Config:
    env = {**BASE, "VALIDATOR_SMTP_PROBE_ENABLED": "true", "VALIDATOR_SMTP_ROUTE_OVERRIDE": "127.0.0.1:1",
           "VALIDATOR_RETRY_BASE_SECONDS": "60", "VALIDATOR_RETRY_MAX_SECONDS": "600", "VALIDATOR_MAX_ATTEMPTS": "3",
           "VALIDATOR_SMTP_COMMAND_TIMEOUT_SECONDS": "1", "VALIDATOR_SMTP_CONNECT_TIMEOUT_SECONDS": "1"}
    env.update(overrides)
    return Config.from_env(env)


ZONE = {
    "example.test": {"MX": [(10, "mx.example.test")]}, "mx.example.test": {"A": ["192.0.2.1"]},
    "accept-all.test": {"MX": [(10, "mx.accept-all.test")]}, "mx.accept-all.test": {"A": ["192.0.2.2"]},
    "block-all.test": {"MX": [(10, "mx.block-all.test")]}, "mx.block-all.test": {"A": ["192.0.2.3"]},
    "nullmx.test": {"MX": [(0, "")]},
    "fallback.test": {"A": ["192.0.2.4"]},
    "tempmail.test": {"MX": [(10, "mx.tempmail.test")]}, "mx.tempmail.test": {"A": ["192.0.2.5"]},
    "gmial.com": {"MX": [(10, "mx.gmial.com")]}, "mx.gmial.com": {"A": ["192.0.2.6"]},
}
