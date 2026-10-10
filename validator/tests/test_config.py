import pytest

from smarthost_validator.config import Config, ConfigError

BASE = {
    "SMARTHOST_ENV": "test", "SMARTHOST_LOG_LEVEL": "info", "SMARTHOST_LOG_FORMAT": "json", "SMARTHOST_TIMEZONE": "Africa/Johannesburg",
    "SMARTHOST_DB_HOST": "postgres", "SMARTHOST_DB_PORT": "5432", "SMARTHOST_DB_NAME": "smarthost", "SMARTHOST_DB_SSLMODE": "disable",
    "VALIDATOR_DB_USER": "smarthost_validator", "VALIDATOR_DB_PASSWORD": "pw", "VALIDATOR_WORKER_ID": "w1",
    "VALIDATOR_CHUNK_SIZE": "250", "VALIDATOR_LEASE_SECONDS": "300", "VALIDATOR_POLL_INTERVAL_SECONDS": "10",
    "VALIDATOR_GLOBAL_CONCURRENCY": "20", "VALIDATOR_PER_DOMAIN_CONCURRENCY": "2", "VALIDATOR_PER_MX_CONCURRENCY": "2",
    "VALIDATOR_MAX_ATTEMPTS": "4", "VALIDATOR_RETRY_BASE_SECONDS": "300", "VALIDATOR_RETRY_MAX_SECONDS": "7200",
    "VALIDATOR_DNS_RESOLVERS": "", "VALIDATOR_DNS_TIMEOUT_SECONDS": "5", "VALIDATOR_SMTP_PROBE_ENABLED": "false",
    "VALIDATOR_SMTP_ROUTE_OVERRIDE": "fake-smtp:2525", "VALIDATOR_SMTP_HELO_HOSTNAME": "validator.smarthost.localhost",
    "VALIDATOR_SMTP_MAIL_FROM": "validator@bounce.smarthost.localhost", "VALIDATOR_SMTP_CONNECT_TIMEOUT_SECONDS": "10",
    "VALIDATOR_SMTP_COMMAND_TIMEOUT_SECONDS": "30",
}


def test_parses_the_contract_variables():
    c = Config.from_env(BASE)
    assert c.chunk_size == 250 and c.smtp_route_override == ("fake-smtp", 2525) and c.dns_resolvers == ()
    assert c.worker_id == "w1"


def test_installation_time_zone_reaches_the_database_session():
    c = Config.from_env(BASE)
    assert c.timezone.key == "Africa/Johannesburg"
    assert "timezone=Africa/Johannesburg" in c.conninfo


def test_empty_worker_id_means_hostname():
    assert Config.from_env({**BASE, "VALIDATOR_WORKER_ID": ""}).worker_id


@pytest.mark.parametrize("override,message", [
    ({"SMARTHOST_ENV": "production"}, "must be empty in production"),
    ({"VALIDATOR_SMTP_PROBE_ENABLED": "true", "VALIDATOR_SMTP_ROUTE_OVERRIDE": ""}, "requires VALIDATOR_SMTP_ROUTE_OVERRIDE"),
    ({"VALIDATOR_SMTP_PROBE_ENABLED": "yes"}, "true or false"),
    ({"VALIDATOR_CHUNK_SIZE": "0"}, "VALIDATOR_CHUNK_SIZE"),
    ({"VALIDATOR_DNS_RESOLVERS": "dns.google"}, "not an IP address"),
    ({"VALIDATOR_DB_PASSWORD": ""}, "Missing required secret"),
    ({"VALIDATOR_SMTP_ROUTE_OVERRIDE": "fake-smtp"}, "host:port"),
    ({"VALIDATOR_RETRY_MAX_SECONDS": "10"}, "must not be below"),
    ({"SMARTHOST_ENV": "staging"}, "SMARTHOST_ENV"),
    ({"SMARTHOST_TIMEZONE": "Mars/Olympus"}, "SMARTHOST_TIMEZONE"),
    ({"SMARTHOST_TIMEZONE": ""}, "SMARTHOST_TIMEZONE"),
])
def test_fails_closed(override, message):
    with pytest.raises(ConfigError, match=message):
        Config.from_env({**BASE, **override})


def test_production_allows_live_probing_without_override():
    c = Config.from_env({**BASE, "SMARTHOST_ENV": "production", "VALIDATOR_SMTP_ROUTE_OVERRIDE": "", "VALIDATOR_SMTP_PROBE_ENABLED": "true"})
    assert c.smtp_probe_enabled and c.smtp_route_override is None


def test_secret_file_indirection(tmp_path):
    f = tmp_path / "pw"
    f.write_text("from-file\n")
    env = {**BASE, "VALIDATOR_DB_PASSWORD": "", "VALIDATOR_DB_PASSWORD_FILE": str(f)}
    assert Config.from_env(env).db_password == "from-file"
    with pytest.raises(ConfigError, match="set only one"):
        Config.from_env({**env, "VALIDATOR_DB_PASSWORD": "direct"})
