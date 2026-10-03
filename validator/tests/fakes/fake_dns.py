"""Deterministic fake DNS server (UDP and TCP) for integration tests.

Serves a JSON zone file:  {"records": {"name": {"MX": [[10, "mx1.x.test"]], "A": ["192.0.2.1"],
"AAAA": [...], "TXT": [...]}}, "servfail": ["name", ...], "refused": [...], "timeout": [...],
"formerr": [...]}. Names not in the zone answer NXDOMAIN; names in the zone
without the requested type answer NOERROR/NODATA. It never forwards queries, so
tests never depend on public DNS.

    python -m tests.fakes.fake_dns --zone zone.json [--host 127.0.0.1] [--port 53]
"""
from __future__ import annotations

import argparse
import asyncio
import json
import sys

import dns.flags
import dns.message
import dns.name
import dns.rcode
import dns.rdataclass
import dns.rdatatype
import dns.rrset


class Zone:
    def __init__(self, data: dict) -> None:
        self.records = {k.lower().rstrip("."): v for k, v in data.get("records", {}).items()}
        self.modes = {mode: {n.lower().rstrip(".") for n in data.get(mode, [])} for mode in ("servfail", "refused", "timeout", "formerr")}

    def answer(self, wire: bytes) -> bytes | None:
        try:
            query = dns.message.from_wire(wire)
        except Exception:
            return None
        response = dns.message.make_response(query)
        response.flags |= dns.flags.AA
        if not query.question:
            response.set_rcode(dns.rcode.FORMERR)
            return response.to_wire()
        q = query.question[0]
        name = q.name.to_text(omit_final_dot=True).lower()
        rdtype = dns.rdatatype.to_text(q.rdtype)
        if name in self.modes["timeout"]:
            return None
        if name in self.modes["servfail"]:
            response.set_rcode(dns.rcode.SERVFAIL)
            return response.to_wire()
        if name in self.modes["refused"]:
            response.set_rcode(dns.rcode.REFUSED)
            return response.to_wire()
        if name in self.modes["formerr"]:
            return b"\x00\x01garbage"
        if name not in self.records:
            response.set_rcode(dns.rcode.NXDOMAIN)
            return response.to_wire()
        values = self.records[name].get(rdtype, [])
        if values:
            texts = [f"{v[0]} {v[1].rstrip('.') + '.' if v[1] not in ('', '.') else '.'}" if rdtype == "MX" else
                     (f'"{v}"' if rdtype == "TXT" else str(v)) for v in values]
            response.answer.append(dns.rrset.from_text_list(q.name, 300, dns.rdataclass.IN, q.rdtype, texts))
        return response.to_wire()


class _Udp(asyncio.DatagramProtocol):
    def __init__(self, zone: Zone) -> None:
        self.zone = zone

    def connection_made(self, transport) -> None:  # type: ignore[no-untyped-def]
        self.transport = transport

    def datagram_received(self, data: bytes, addr) -> None:  # type: ignore[no-untyped-def]
        reply = self.zone.answer(data)
        if reply is not None:
            self.transport.sendto(reply, addr)


async def serve(zone: Zone, host: str, port: int) -> tuple[asyncio.BaseTransport, asyncio.Server]:
    loop = asyncio.get_running_loop()
    udp, _ = await loop.create_datagram_endpoint(lambda: _Udp(zone), local_addr=(host, port))

    async def tcp(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        try:
            size = int.from_bytes(await reader.readexactly(2), "big")
            reply = zone.answer(await reader.readexactly(size))
            if reply is not None:
                writer.write(len(reply).to_bytes(2, "big") + reply)
                await writer.drain()
        except (asyncio.IncompleteReadError, ConnectionError):
            pass
        finally:
            writer.close()

    server = await asyncio.start_server(tcp, host, port)
    return udp, server


async def _main(args: argparse.Namespace) -> None:
    with open(args.zone) as fh:
        zone = Zone(json.load(fh))
    _, server = await serve(zone, args.host, args.port)
    print(json.dumps({"service": "fake-dns", "msg": "listening", "port": args.port, "names": len(zone.records)}), flush=True)
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("--zone", required=True)
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, default=53)
    try:
        asyncio.run(_main(p.parse_args()))
    except KeyboardInterrupt:
        sys.exit(0)
