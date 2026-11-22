"""Real loopback HTTPS/WSS transport checks with an ephemeral fixture CA.

No AWS, production HTTP, DNS changes, certificate-store install or Docker.
Test trust is injected only in this process; the actual probe has no insecure flag.
"""
import base64
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
from pathlib import Path
import shutil
import ssl
import subprocess
import tempfile
import threading
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/check_endpoint.py'


@unittest.skipUnless(shutil.which('openssl'), 'OpenSSL required for ephemeral TLS fixtures')
class EndpointTls(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix='eks-local-tls-')
        cls.addClassCleanup(cls.directory.cleanup)
        cls.base = Path(cls.directory.name)
        def run(*args):
            result = subprocess.run(['openssl', *args], cwd=cls.base, capture_output=True, timeout=25)
            if result.returncode:
                raise RuntimeError('Ephemeral TLS fixture generation failed')
        run('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'ca.key', '-out', 'ca.pem',
            '-days', '1', '-subj', '/CN=EKS Lab Fixture CA',
            '-addext', 'basicConstraints=critical,CA:TRUE',
            '-addext', 'keyUsage=critical,keyCertSign,cRLSign', '-addext', 'subjectKeyIdentifier=hash')
        run('req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'server.key',
            '-out', 'server.csr', '-subj', '/CN=localhost')
        for name, san in (('server', 'IP:127.0.0.1'), ('wrong', 'DNS:wrong.invalid')):
            extensions = cls.base / (name + '-extensions.txt')
            extensions.write_text(
                'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n'
                'extendedKeyUsage=serverAuth\nsubjectAltName=' + san + '\n'
                'subjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n', encoding='ascii')
            run('x509', '-req', '-in', 'server.csr', '-CA', 'ca.pem', '-CAkey', 'ca.key',
                '-CAcreateserial', '-out', name + '.pem', '-days', '1', '-sha256', '-extfile', extensions.name)

    def module(self):
        spec = importlib.util.spec_from_file_location('endpoint_tls_probe', SCRIPT)
        assert spec is not None and spec.loader is not None
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def trusted(self):
        return ssl.create_default_context(cafile=str(self.base / 'ca.pem'))

    def server(self, *, wrong_hostname=False):
        seen = []
        class Handler(BaseHTTPRequestHandler):
            protocol_version = 'HTTP/1.1'
            def log_message(self, format, *args):
                pass
            def do_GET(self):
                seen.append((self.path, self.headers.get('Origin'), self.connection.version()))
                if self.path.startswith('/app/'):
                    self.send_response(101)
                    self.send_header('Upgrade', 'websocket')
                    self.send_header('Connection', 'Upgrade')
                    value = (self.headers['Sec-WebSocket-Key'] + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()
                    self.send_header('Sec-WebSocket-Accept', base64.b64encode(hashlib.sha1(value).digest()).decode())
                    self.end_headers()
                    data = b'{"event":"pusher:connection_established","data":"{}"}'
                    self.wfile.write(bytes([0x81, len(data)]) + data)
                    self.close_connection = True
                else:
                    self.send_response(200)
                    self.send_header('Content-Length', '2')
                    self.end_headers()
                    self.wfile.write(b'OK')
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(str(self.base / ('wrong.pem' if wrong_hostname else 'server.pem')), str(self.base / 'server.key'))
        server.socket = context.wrap_socket(server.socket, server_side=True)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(thread.join, 3)
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        return f'https://127.0.0.1:{server.server_port}', seen

    def test_https_pages_and_wss_upgrade_use_real_verified_tls(self):
        module = self.module()
        url, seen = self.server()
        trusted = self.trusted()
        with patch.object(module.ssl, '_create_default_https_context', return_value=trusted), \
             patch.object(module.ssl, 'create_default_context', return_value=trusted):
            module.probe_once(url, 'public-fixture-key', timeout=8)
        self.assertEqual([row[0] for row in seen[:3]], ['/readyz', '/', '/login'])
        self.assertEqual(seen[-1][1], url)
        self.assertTrue(all(row[2] in ('TLSv1.2', 'TLSv1.3') for row in seen))

    def test_unknown_ca_is_rejected_without_installing_fixture_trust(self):
        module = self.module()
        url, seen = self.server()
        with self.assertRaises(ssl.SSLCertVerificationError):
            module.probe_once(url, 'public-fixture-key', timeout=8)
        self.assertEqual(seen, [])

    def test_wss_itself_rejects_unknown_ca_after_verified_https_pages(self):
        module = self.module()
        url, seen = self.server()
        trusted = self.trusted()
        with patch.object(module.ssl, '_create_default_https_context', return_value=trusted):
            with self.assertRaises(ssl.SSLCertVerificationError):
                module.probe_once(url, 'public-fixture-key', timeout=8)
        self.assertEqual([row[0] for row in seen], ['/readyz', '/', '/login'])

    def test_wss_rejects_wrong_hostname_even_with_correct_ca(self):
        module = self.module()
        url, seen = self.server(wrong_hostname=True)
        http_context = self.trusted()
        http_context.check_hostname = False  # Isolate the real WSS hostname check below.
        wss_context = self.trusted()
        with patch.object(module.ssl, '_create_default_https_context', return_value=http_context), \
             patch.object(module.ssl, 'create_default_context', return_value=wss_context):
            with self.assertRaises(ssl.SSLCertVerificationError):
                module.probe_once(url, 'public-fixture-key', timeout=8)
        self.assertEqual([row[0] for row in seen], ['/readyz', '/', '/login'])


if __name__ == '__main__':
    unittest.main()
