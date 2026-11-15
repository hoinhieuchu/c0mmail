#!/usr/bin/env python3
"""Minimal SMTP test endpoint that exercises a real STARTTLS socket upgrade."""
from __future__ import annotations

import argparse
import base64
import pathlib
import socketserver
import ssl


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=3587)
    parser.add_argument("--cert", required=True)
    parser.add_argument("--key", required=True)
    parser.add_argument("--capture", required=True)
    parser.add_argument("--username", required=True)
    parser.add_argument("--password", required=True)
    args = parser.parse_args()

    tls_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls_context.load_cert_chain(args.cert, args.key)
    capture = pathlib.Path(args.capture)

    class Handler(socketserver.StreamRequestHandler):
        def reply(self, message: str) -> None:
            self.wfile.write(message.encode("ascii") + b"\r\n")
            self.wfile.flush()

        def handle(self) -> None:
            self.reply("220 c0mmail STARTTLS test server")
            tls_active = False
            auth_step = 0
            authenticated = False
            data_mode = False
            message_lines: list[bytes] = []
            username = ""

            while True:
                line = self.rfile.readline(65536)
                if not line:
                    return
                line = line.rstrip(b"\r\n")

                if data_mode:
                    if line == b".":
                        payload = b"\r\n".join(
                            item[1:] if item.startswith(b"..") else item
                            for item in message_lines
                        ) + b"\r\n"
                        capture.write_bytes(payload)
                        self.reply("250 2.0.0 Message accepted for test capture")
                        message_lines = []
                        data_mode = False
                    else:
                        message_lines.append(line)
                    continue

                command = line.decode("ascii", "replace")
                upper = command.upper()
                if upper.startswith("EHLO ") or upper.startswith("HELO "):
                    self.reply("250-localhost")
                    if not tls_active:
                        self.reply("250-STARTTLS")
                    self.reply("250 AUTH LOGIN")
                elif upper == "STARTTLS":
                    if tls_active:
                        self.reply("454 TLS already active")
                        continue
                    self.reply("220 2.0.0 Ready to start TLS")
                    self.rfile.close()
                    self.wfile.close()
                    self.connection = tls_context.wrap_socket(self.connection, server_side=True)
                    self.rfile = self.connection.makefile("rb")
                    self.wfile = self.connection.makefile("wb")
                    tls_active = True
                elif upper == "AUTH LOGIN":
                    if not tls_active:
                        self.reply("530 5.7.0 Must issue STARTTLS first")
                    else:
                        auth_step = 1
                        self.reply("334 VXNlcm5hbWU6")
                elif auth_step == 1:
                    try:
                        username = base64.b64decode(line, validate=True).decode("utf-8")
                    except Exception:
                        self.reply("535 5.7.8 Invalid credentials")
                        auth_step = 0
                    else:
                        auth_step = 2
                        self.reply("334 UGFzc3dvcmQ6")
                elif auth_step == 2:
                    try:
                        password = base64.b64decode(line, validate=True).decode("utf-8")
                    except Exception:
                        password = ""
                    authenticated = username == args.username and password == args.password
                    auth_step = 0
                    self.reply("235 2.7.0 Authentication successful" if authenticated else "535 5.7.8 Invalid credentials")
                elif upper.startswith("MAIL FROM:"):
                    self.reply("250 2.1.0 Sender accepted" if authenticated else "530 5.7.0 Authentication required")
                elif upper.startswith("RCPT TO:"):
                    self.reply("250 2.1.5 Recipient accepted" if authenticated else "530 5.7.0 Authentication required")
                elif upper == "DATA":
                    if authenticated:
                        data_mode = True
                        self.reply("354 End data with <CR><LF>.<CR><LF>")
                    else:
                        self.reply("530 5.7.0 Authentication required")
                elif upper == "RSET":
                    message_lines = []
                    data_mode = False
                    self.reply("250 2.0.0 Reset")
                elif upper == "NOOP":
                    self.reply("250 2.0.0 OK")
                elif upper == "QUIT":
                    self.reply("221 2.0.0 Bye")
                    return
                else:
                    self.reply("502 5.5.1 Command not implemented")

    class Server(socketserver.ThreadingTCPServer):
        allow_reuse_address = True
        daemon_threads = True

    with Server((args.host, args.port), Handler) as server:
        server.serve_forever()


if __name__ == "__main__":
    main()
