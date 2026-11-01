#!/usr/bin/env python3
# © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
"""FlxCoreHC v1.172.0 — a tiny SMTP sink for the HTTP smoke test (TEST ENVIRONMENT ONLY).

Accepts mail on 127.0.0.1:<port> without TLS or authentication and appends each message to a JSON-lines file, so the
test can read what the application REALLY sent (sign-in codes, password-reset links) instead of peeking at the database.
Usage: smtp_sink.py <port> <mailbox-file>
"""
import json, socketserver, sys, threading

PORT = int(sys.argv[1]); BOX = sys.argv[2]
LOCK = threading.Lock()

class Handler(socketserver.StreamRequestHandler):
    def w(self, line):
        self.wfile.write((line + "\r\n").encode()); self.wfile.flush()
    def handle(self):
        self.w("220 flx-smoke-sink ESMTP")
        mail_from, rcpt = "", []
        while True:
            raw = self.rfile.readline()
            if not raw: return
            line = raw.decode("utf-8", "replace").rstrip("\r\n"); cmd = line.upper()
            if cmd.startswith("EHLO") or cmd.startswith("HELO"):
                self.w("250-flx-smoke-sink"); self.w("250 8BITMIME")
            elif cmd.startswith("MAIL FROM"):
                mail_from = line.split(":", 1)[1].strip().strip("<>"); self.w("250 OK")
            elif cmd.startswith("RCPT TO"):
                rcpt.append(line.split(":", 1)[1].strip().strip("<>")); self.w("250 OK")
            elif cmd == "DATA":
                self.w("354 End data with <CR><LF>.<CR><LF>")
                lines = []
                while True:
                    d = self.rfile.readline()
                    if not d or d in (b".\r\n", b".\n"): break
                    t = d.decode("utf-8", "replace").rstrip("\r\n")
                    lines.append(t[1:] if t.startswith("..") else t)     # undo dot-stuffing
                text = "\n".join(lines)
                head, _, body = text.partition("\n\n")
                subject = next((h.split(":", 1)[1].strip() for h in head.split("\n") if h.lower().startswith("subject:")), "")
                with LOCK, open(BOX, "a", encoding="utf-8") as f:
                    f.write(json.dumps({"from": mail_from, "to": rcpt, "subject": subject, "body": body}) + "\n")
                mail_from, rcpt = "", []
                self.w("250 OK queued")
            elif cmd == "QUIT":
                self.w("221 Bye"); return
            elif cmd == "RSET" or cmd == "NOOP":
                self.w("250 OK")
            else:
                self.w("502 Command not implemented")

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True; daemon_threads = True

if __name__ == "__main__":
    open(BOX, "a").close()
    with Server(("127.0.0.1", PORT), Handler) as s:
        s.serve_forever()
