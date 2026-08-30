#!/usr/bin/env node
'use strict';
/*
 * A throwaway TLS front end for the round-trip harness, in Node's standard library and nothing
 * else — matching `fleet-reporter.js`'s own zero-dependency posture.
 *
 * WHY IT EXISTS. D1 § 3.5 is an operator ruling, not a preference: "there is no loopback
 * deployment mode, and no part of this design may assume same-host anything". The flusher enforces
 * it — `postBatch()` refuses any `ingest_url` whose protocol is not `https:` — and there is no
 * `rejectUnauthorized: false` anywhere in the reporter to work around. `php artisan serve` speaks
 * only HTTP, so the way to exercise the REAL transport path is to put real TLS in front of it and
 * trust the certificate through the reporter's OWN `ca_file` key, which § 3.5 provides for exactly
 * this ("a sandbox host with a private CA is supported by `ca_file` → NODE_EXTRA_CA_CERTS").
 *
 * So certificate verification is ON for the whole round trip. Turning it off would have been one
 * line and would have made the harness prove the transport works in a configuration no seat ever
 * runs — "the classic constraint-weakening fix, and it ships to production seats" (§ 3.5).
 *
  * Usage: tls-terminator.cjs <listen-port> <origin-port> <cert.pem> <key.pem>
 */

const https = require('https');
const http = require('http');
const fs = require('fs');

const [, , listenPort, originPort, certPath, keyPath] = process.argv;

const server = https.createServer(
  { cert: fs.readFileSync(certPath), key: fs.readFileSync(keyPath) },
  (req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks);
      const headers = Object.assign({}, req.headers);
      headers['content-length'] = String(body.length);
      delete headers.host;

      const upstream = http.request(
        { host: '127.0.0.1', port: Number(originPort), path: req.url, method: req.method, headers },
        (up) => {
          res.writeHead(up.statusCode, up.headers);
          up.pipe(res);
        },
      );

      upstream.on('error', (e) => {
        res.writeHead(502, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'server_error', detail: String(e && e.message) }));
      });

      upstream.end(body);
    });
  },
);

server.listen(Number(listenPort), '127.0.0.1', () => {
  process.stdout.write(`tls-terminator listening on ${listenPort} -> ${originPort}\n`);
});
