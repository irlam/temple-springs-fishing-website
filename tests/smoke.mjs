import assert from 'node:assert/strict';
import { mkdtemp, cp, writeFile, rm, readFile, access } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn } from 'node:child_process';
const root = resolve(import.meta.dirname, '..');
const temp = await mkdtemp(join(tmpdir(), 'temple-springs-test-'));
let server;
try {
  await cp(join(root, 'public'), join(temp, 'public'), { recursive: true });
  server = spawn('php', ['-S', '127.0.0.1:8089', '-t', join(temp, 'public')], { stdio: 'ignore' });
  let started = false;
  for (let i = 0; i < 30; i++) {
    try { await fetch('http://127.0.0.1:8089/'); started = true; break; } catch { await new Promise(r => setTimeout(r, 100)); }
  }
  assert.ok(started, 'PHP test server started');
  const url = 'http://127.0.0.1:8089/contact.php';
  let response = await fetch(url);
  assert.ok(response.headers.get('cache-control').includes('no-store'));
  assert.equal((await response.json()).enabled, false);
  assert.equal((await fetch(url, { method: 'POST' })).status, 503);
  assert.equal((await fetch(url, { method: 'PUT' })).status, 405);
  // Temporary configuration only. No test ever reaches the mail transport.
  await writeFile(join(temp, 'config.php'), "<?php return ['recipient'=>'test@example.com','sender'=>'sender@example.com','rate_limit_secret'=>'test-only'];");
  response = await fetch(url);
  const cookie = response.headers.get('set-cookie').split(';')[0];
  const data = await response.json();
  assert.equal(data.enabled, true);
  assert.equal(data.token.length, 64);
  const post = values => fetch(url, { method: 'POST', headers: { cookie }, body: new URLSearchParams(values) });
  assert.equal((await post({ token: 'wrong' })).status, 403);
  assert.equal((await post({ token: data.token, email: 'invalid' })).status, 422);
  assert.equal((await post({ token: data.token, website: 'spam' })).status, 400);
  assert.equal((await post({ token: data.token, name: 'Test', email: 'test@example.com', message: 'This is a test message.', interest: 'General enquiry' })).status, 422);
  assert.equal((await fetch('http://127.0.0.1:8089/config.php')).status, 404);
  const html = await readFile(join(root, 'public/index.html'), 'utf8');
  for (const [, file] of html.matchAll(/(?:src|href)="(\/[^"#]+)"/g)) await access(join(root, 'public', file));
  const ids = new Set([...html.matchAll(/id="([^"]+)"/g)].map(m => m[1]));
  for (const [, id] of html.matchAll(/href="#([^"]+)"/g)) assert.ok(ids.has(id), `Anchor ${id} exists`);
  const manifest = JSON.parse(await readFile(join(root, 'public/manifest.webmanifest'), 'utf8'));
  for (const icon of manifest.icons) await access(join(root, 'public', icon.src));
  console.log('PASS: contact disabled/configured states, method handling, CSRF, validation, honeypot, consent, private config, local assets, anchors and manifest icons. No email sent.');
} finally {
  if (server && server.exitCode === null) { server.kill(); await new Promise(r => server.once('exit', r)); }
  await rm(temp, { recursive: true, force: true });
}
