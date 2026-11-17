#!/usr/bin/env python3
"""Probe the actual restricted lab endpoint from its allowed runner; stdlib only.

No redirects, insecure TLS, AWS calls, response bodies, cookies, or private keys in
output. REVERB_PUBLIC_KEY is the public client key, not APP_KEY or app secret.
"""
import argparse
import base64
import hashlib
import http.client
import json
import os
import socket
import ssl
import struct
import sys
import time
from urllib.parse import urlsplit


class ProbeError(ValueError):
    pass


def probe_once(url, public_key, timeout=10):
    parsed = urlsplit(url)
    if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/'):
        raise ProbeError('HTTP probe requires an isolated URL origin')
    if not public_key or not all(char.isalnum() or char in '-_' for char in public_key):
        raise ProbeError('WebSocket public key missing/invalid')
    deadline = time.monotonic() + timeout
    def remaining():
        value = deadline - time.monotonic()
        if value <= 0:
            raise ProbeError('HTTP/WebSocket attempt timed out')
        return value
    port = parsed.port or (443 if parsed.scheme == 'https' else 80)
    for path in ('/readyz', '/', '/login'):
        cls = http.client.HTTPSConnection if parsed.scheme == 'https' else http.client.HTTPConnection
        connection = cls(parsed.hostname, port, timeout=remaining())
        try:
            connection.request('GET', path, headers={'Host': parsed.netloc, 'User-Agent': 'manaleague-eks-lab-postdeploy', 'Connection': 'close'})
            response = connection.getresponse()
            if response.status != 200:
                raise ProbeError(f'HTTP {path} returned {response.status}, expected 200')
            response.close()
        finally:
            connection.close()
    sock = socket.create_connection((parsed.hostname, port), timeout=remaining())
    try:
        if parsed.scheme == 'https':
            sock = ssl.create_default_context().wrap_socket(sock, server_hostname=parsed.hostname)
        sock.settimeout(remaining())
        nonce = base64.b64encode(os.urandom(16)).decode()
        origin = url.rstrip('/')
        sock.sendall((f'GET /app/{public_key}?protocol=7&client=js&version=8.4.0&flash=false HTTP/1.1\r\n'
                      f'Host: {parsed.netloc}\r\nOrigin: {origin}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                      f'Sec-WebSocket-Key: {nonce}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        with sock.makefile('rb') as stream:
            header_lines = []
            size = 0
            while True:
                sock.settimeout(remaining())
                line = stream.readline(8193)
                size += len(line)
                if size > 16384 or not line:
                    raise ProbeError('WebSocket upgrade headers missing/oversized')
                if line == b'\r\n':
                    break
                header_lines.append(line)
            status = header_lines[0].split() if header_lines else []
            if len(status) < 2 or status[1] != b'101':
                raise ProbeError('WebSocket upgrade did not return HTTP 101')
            headers = {}
            for line in header_lines[1:]:
                name, separator, value = line.partition(b':')
                if not separator:
                    raise ProbeError('WebSocket invalid upgrade headers')
                headers[name.lower()] = value.strip()
            expected = base64.b64encode(hashlib.sha1((nonce + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest())
            if headers.get(b'sec-websocket-accept') != expected or headers.get(b'upgrade', b'').lower() != b'websocket' or b'upgrade' not in headers.get(b'connection', b'').lower():
                raise ProbeError('WebSocket invalid upgrade accept/hash')
            def read_exact(count):
                data = b''
                while len(data) < count:
                    sock.settimeout(remaining())
                    chunk = stream.read(count - len(data))
                    if not chunk:
                        raise ProbeError('WebSocket closed before connection_established event')
                    data += chunk
                return data
            first, second = read_exact(2)
            if first != 0x81 or second & 0x80:
                raise ProbeError('WebSocket unexpected initial frame')
            length = second & 0x7f
            if length == 126:
                length = struct.unpack('!H', read_exact(2))[0]
            elif length == 127:
                length = struct.unpack('!Q', read_exact(8))[0]
            if length > 65536:
                raise ProbeError('WebSocket initial frame too large')
            try:
                event = json.loads(read_exact(length))
            except (ValueError, UnicodeError):
                raise ProbeError('WebSocket invalid initial event') from None
            if not isinstance(event, dict) or event.get('event') != 'pusher:connection_established':
                raise ProbeError('WebSocket connection_established event missing')
    finally:
        sock.close()


def check_endpoint(url, public_key, attempts=12):
    last_reason = 'not attempted'
    for attempt in range(attempts):
        try:
            probe_once(url, public_key)
            return
        except (OSError, ValueError, http.client.HTTPException) as error:
            # Bodies, headers, cookies and arbitrary transport exception strings stay private.
            last_reason = str(error) if isinstance(error, ProbeError) else type(error).__name__
        if attempt + 1 < attempts:
            time.sleep(5)
    raise ValueError(f'HTTP/WebSocket checks failed after {attempts} attempts: {last_reason}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True)
    args = parser.parse_args()
    check_endpoint(args.url, os.environ.get('REVERB_PUBLIC_KEY', ''))
    print('PASS: actual ALB HTTP /readyz / /login and Reverb 101/accept/connection_established')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
