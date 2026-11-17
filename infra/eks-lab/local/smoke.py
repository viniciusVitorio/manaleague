"""Disposable HTTP/WAF/session/WebSocket integration; stdlib only, no external writes."""
import base64
import hashlib
import hmac
import http.client
import http.cookiejar
import os
from pathlib import Path
import re
import socket
import time
import urllib.error
import urllib.parse
import urllib.request

env = dict(line.split("=", 1) for line in Path(".env.local").read_text().splitlines() if "=" in line)
base = "http://127.0.0.1:18080"
def request(path, data=None):
    req = urllib.request.Request(base + path, data=data, headers={"Host": "localhost:18080"})
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()

for attempt in range(30):
    try:
        if request("/readyz")[0] == 200:
            break
    except OSError:
        pass
    time.sleep(1)
else:
    raise AssertionError("PHP/database readiness never succeeded")
for path in ("/up", "/readyz", "/", "/login"):
    assert request(path)[0] == 200, path
for path in ("/apps", "/apps/demo/events", "/.env", "/test.php", "/lab-original.php"):
    assert request(path)[0] == 404, path
status, headers, _ = request("/login")
assert "Strict-Transport-Security" not in headers, "HTTP must not set HSTS"
assert headers.get("Content-Security-Policy"), "Application CSP missing"
for payload in ("<script>alert(1)</script>", "1' OR 1=1-- -"):
    status, headers, _ = request("/?" + urllib.parse.urlencode({"q": payload}))
    assert status == 403 and headers.get("X-WAF-Block") == "modsecurity", (status, payload)
status, headers, _ = request("/?" + urllib.parse.urlencode({"q": "Magic Commander Friday"}))
assert status == 200 and not headers.get("X-WAF-Block")
status, headers, _ = request("/me/decks/1/import", urllib.parse.urlencode({"list": "1 Command Tower\n1 Search for Tomorrow"}).encode())
assert status in (302, 419) and not headers.get("X-WAF-Block"), "Narrow CRS deck import exception failed"
print("PASS: PHP/DB readiness, HTTP CSP/no HSTS, hidden files/API denial, WAF SQLi/XSS + legitimate GET/deck POST")

# Authenticate on the FIRST PHP Pod and reuse the same encrypted DB session on the SECOND.
conn = http.client.HTTPConnection(os.environ["APP1_IP"], 8080, timeout=10)
conn.request("GET", "/login", headers={"Host": "localhost:18080"})
response = conn.getresponse()
body = response.read().decode()
assert response.status == 200
cookies = [v.split(";", 1)[0] for k, v in response.getheaders() if k.lower() == "set-cookie"]
match = re.search(r'name="_token" value="([^"]+)"', body)
assert match, 'CSRF input missing'
token = match.group(1)
body = urllib.parse.urlencode({"_token": token, "email": "demo@manaleague.test", "password": env["DEMO_PASSWORD"]})
conn.request("POST", "/login", body, {"Host": "localhost:18080", "Cookie": "; ".join(cookies), "Content-Type": "application/x-www-form-urlencoded"})
response = conn.getresponse()
response.read()
assert response.status == 302, f"Login failed: {response.status}"
assert "/login" not in response.getheader("Location", ""), "Login redirected back to login"
cookies = [v.split(";", 1)[0] for k, v in response.getheaders() if k.lower() == "set-cookie"]
conn.close()
conn = http.client.HTTPConnection(os.environ["APP2_IP"], 8080, timeout=10)
conn.request("GET", "/me/profile", headers={"Host": "localhost:18080", "Cookie": "; ".join(cookies)})
response = conn.getresponse()
body = response.read().decode()
assert response.status == 200, f"Cross-Pod authenticated session failed: {response.status}"
assert "demo@manaleague.test" in body or "Organizador Demo" in body
conn.close()
print("PASS: real login on instance 1; same authenticated database session accepted by instance 2")

key = "ml" + hmac.new(env["APP_KEY"].encode(), b"reverb:key", hashlib.sha256).hexdigest()[:18]
nonce = base64.b64encode(os.urandom(16)).decode()
with socket.create_connection(("127.0.0.1", 18080), timeout=10) as ws:
    ws.sendall((f"GET /app/{key}?protocol=7&client=js&version=8.4.0&flash=false HTTP/1.1\r\n"
                f"Host: localhost:18080\r\nOrigin: http://localhost:18080\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {nonce}\r\nSec-WebSocket-Version: 13\r\n\r\n").encode())
    data = ws.recv(8192)
    assert b"101 Switching Protocols" in data, data[:120]
    expected = base64.b64encode(hashlib.sha1((nonce + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest())
    assert expected in data
    while b"pusher:connection_established" not in data:
        chunk = ws.recv(8192)
        assert chunk, "WebSocket closed before establishment event"
        data += chunk
print("PASS: Reverb HTTP upgrade + valid accept hash + Pusher connection_established frame")
req = urllib.request.Request(base + "/up", headers={"Host": "localhost:18080", "X-Real-IP": "203.0.113.222", "X-Forwarded-For": "198.51.100.222", "User-Agent": "eks-lab-spoof-test"})
with urllib.request.urlopen(req, timeout=10) as response:
    assert response.status == 200
