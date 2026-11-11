"""A chat-completions endpoint that counts TCP (or TLS) connections.

Usage: python3 connection_counting_server.py [certfile keyfile]
Prints the port it listens on, then serves until killed. GET /__connections
returns how many connections it has accepted.
"""
import json
import socket
import ssl
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

connections = 0


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"  # keep-alive

    def setup(self):
        global connections
        connections += 1
        super().setup()

    def log_message(self, *args):
        pass

    def _send(self, payload):
        body = json.dumps(payload).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        self._send({"connections": connections})

    def do_POST(self):
        request = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        text = request["messages"][-1]["content"]
        self._send({"choices": [{"message": {"content": text + "!"}, "finish_reason": "stop"}]})


server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
if len(sys.argv) == 3:
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(sys.argv[1], sys.argv[2])
    server.socket = context.wrap_socket(server.socket, server_side=True)
print(server.server_address[1], flush=True)
server.serve_forever()
