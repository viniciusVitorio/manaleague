"""Exercise the remote probe against a real loopback HTTP/WebSocket fixture, not AWS."""
import base64
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
from pathlib import Path
import threading
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/check_endpoint.py'

class EndpointTests(unittest.TestCase):
    def load(self):
        self.assertTrue(SCRIPT.exists(), 'actual post-deploy endpoint probe is missing')
        spec = importlib.util.spec_from_file_location('endpoint_probe', SCRIPT)
        assert spec and spec.loader
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def fixture(self, *, reject_origin=False, bad_accept=False, missing_event=False, redirect=False):
        seen = []
        class Handler(BaseHTTPRequestHandler):
            protocol_version = 'HTTP/1.1'
            def log_message(self, format, *args):
                pass
            def do_GET(self):
                seen.append((self.path, self.headers.get('Origin')))
                if self.path.startswith('/app/'):
                    self.send_response(403 if reject_origin else 101)
                    self.send_header('Upgrade', 'websocket')
                    self.send_header('Connection', 'Upgrade')
                    nonce = self.headers['Sec-WebSocket-Key']
                    accept = base64.b64encode(hashlib.sha1((nonce+'258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()).decode()
                    self.send_header('Sec-WebSocket-Accept', 'invalid' if bad_accept else accept)
                    self.end_headers()
                    if not reject_origin:
                        body = b'{"event":"pusher:connection_established","data":"{}"}' if not missing_event else b'{"event":"other"}'
                        self.wfile.write(bytes([0x81, len(body)]) + body)
                    self.close_connection = True
                else:
                    self.send_response(302 if redirect else 200)
                    self.send_header('Content-Length', '2')
                    if redirect:
                        self.send_header('Location', '/login')
                    self.end_headers()
                    self.wfile.write(b'OK')
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        return f'http://127.0.0.1:{server.server_port}', seen

    def test_actual_ready_root_login_and_websocket_accept_and_event(self):
        module = self.load()
        url, seen = self.fixture()
        module.check_endpoint(url, 'public-test-key', attempts=1)
        self.assertEqual([path for path, _ in seen[:3]], ['/readyz', '/', '/login'])
        self.assertEqual(seen[-1][1], url)

    def test_rejects_bad_upgrade_accept_missing_event_and_http_redirect(self):
        module = self.load()
        for flags in ({'reject_origin': True}, {'bad_accept': True}, {'missing_event': True}, {'redirect': True}):
            with self.subTest(flags=flags):
                url, _ = self.fixture(**flags)
                with self.assertRaisesRegex(ValueError, 'HTTP|WebSocket'):
                    module.check_endpoint(url, 'public-test-key', attempts=1)

    def test_retries_bounded_without_response_body_in_error(self):
        module = self.load()
        with patch.object(module, 'probe_once', side_effect=OSError('SECRET-RESPONSE-BODY')) as probe, patch.object(module.time, 'sleep'):
            with self.assertRaises(ValueError) as error:
                module.check_endpoint('http://lab.test', 'public-key', attempts=3)
        self.assertEqual(probe.call_count, 3)
        self.assertNotIn('SECRET', str(error.exception))

if __name__ == '__main__':
    unittest.main()
