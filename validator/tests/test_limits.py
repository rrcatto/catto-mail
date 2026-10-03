import asyncio
import random

from smarthost_validator.limits import AcceptAllPolicy, Limits, ProviderBackoff


async def test_global_domain_and_mx_limits_hold_under_load():
    limits = Limits(global_limit=5, per_domain=2, per_mx=3)
    domains = [f"d{i}.test" for i in range(8)]

    async def probe(domain: str, mx: str):
        async with limits.smtp(domain, mx):
            await asyncio.sleep(random.uniform(0.001, 0.01))

    jobs = [probe(random.choice(domains), f"mx{random.randrange(4)}.test") for _ in range(600)]
    await asyncio.gather(*jobs)
    s = limits.stats()
    assert s["max_global"] <= 5 and s["max_per_domain"] <= 2 and s["max_per_mx"] <= 3
    assert s["max_global"] == 5  # the limit was actually reached
    assert limits.domain.keys_alive == 0 and limits.mx.keys_alive == 0  # no unbounded growth


async def test_skewed_load_does_not_starve_other_domains():
    """Thousands of addresses at one provider wait on its domain/MX slots without
    holding global slots, so another domain still gets through promptly."""
    limits = Limits(global_limit=4, per_domain=2, per_mx=2)
    order: list[str] = []

    async def probe(domain: str, mx: str, delay: float):
        async with limits.smtp(domain, mx):
            order.append(domain)
            await asyncio.sleep(delay)

    big = [asyncio.create_task(probe("bigmail.test", "mx.bigmail.test", 0.02)) for _ in range(200)]
    await asyncio.sleep(0.005)
    small = asyncio.create_task(probe("small.test", "mx.small.test", 0.0))
    await asyncio.wait_for(small, 0.2)  # far sooner than 200 x 0.02 / 2 seconds
    assert limits.domain.max_seen_by_key["bigmail.test"] <= 2 and limits.global_.max_seen <= 4
    for t in big:
        t.cancel()
    await asyncio.gather(*big, return_exceptions=True)


def test_provider_backoff_is_exponential_and_capped():
    now = [1000.0]
    b = ProviderBackoff(10, 60, clock=lambda: now[0])
    assert b.cooling_for("mx") == 0
    assert [b.record_throttling("mx") for _ in range(5)] == [10, 20, 40, 60, 60]
    assert b.cooling_for("mx") == 60
    now[0] += 61
    assert b.cooling_for("mx") == 0
    b.record_success("mx")
    assert b.record_throttling("mx") == 10


async def test_accept_all_policy_one_probe_per_domain_and_waiters_share_the_verdict():
    p = AcceptAllPolicy(window_seconds=3600, min_interval_seconds=0.0)
    first = await p.begin("x.test")
    assert first == (True, None)
    waiter = asyncio.create_task(p.begin("x.test"))
    await asyncio.sleep(0.01)
    assert not waiter.done()  # waits for the in-flight probe
    p.finish("x.test", executed=True, verdict=True)
    assert await waiter == (False, True)
    assert await p.begin("x.test") == (False, True) and p.probes == 1


async def test_accept_all_not_executed_lets_the_next_task_probe():
    p = AcceptAllPolicy(window_seconds=3600, min_interval_seconds=0.0)
    assert (await p.begin("y.test"))[0]
    p.finish("y.test", executed=False, verdict=None)  # e.g. the target itself was rejected
    assert (await p.begin("y.test"))[0]
    p.finish("y.test", executed=True, verdict=None)  # executed but inconclusive: no new probe this window
    assert await p.begin("y.test") == (False, None) and p.probes == 1


async def test_accept_all_probes_are_rate_limited_worker_wide():
    p = AcceptAllPolicy(window_seconds=3600, min_interval_seconds=0.2)
    loop = asyncio.get_running_loop()
    start = loop.time()
    for d in ["a.test", "b.test", "c.test"]:
        assert (await p.begin(d))[0]
        p.finish(d, True, False)
    assert loop.time() - start >= 0.39
