import {mkdtempSync,writeFileSync,readFileSync,existsSync,rmSync,statSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {spawnSync} from 'node:child_process';
import assert from 'node:assert/strict';
const dir=mkdtempSync(join(tmpdir(),'temple-setup-')), php=process.env.PHP_BIN||'php', config=join(dir,'config.php');
const env={...process.env,TEMPLE_CONFIG:config};
const run=(args)=>spawnSync(php,args,{env,encoding:'utf8'});
const q=s=>"'"+s.replaceAll('\\','\\\\').replaceAll("'","\\'")+"'";
try {
 writeFileSync(config,`<?php return ['data_dir'=>${q(join(dir,'private'))},'base_url'=>'https://example.test','app_key'=>'','stripe_mode'=>'test','secure_cookies'=>true,'recipient'=>'keep@example.test'];`);
 let r=run(['bin/plesk-setup.php']); assert.notEqual(r.status,0); assert(!existsSync(join(dir,'private')));
 r=run(['bin/plesk-setup.php','admin@example.test']); assert.equal(r.status,0,r.stderr); assert.match(r.stdout,/Initial setup complete/);
 const secret=readFileSync(join(dir,'private/initial-admin.txt'),'utf8').match(/Password: (\w+)/)[1];
 assert.equal(secret.length,32); assert(!r.stdout.includes(secret)); assert(!r.stderr.includes(secret));
 assert.equal(statSync(join(dir,'private/initial-admin.txt')).mode&0o777,0o600);
 assert.match(readFileSync(config,'utf8'),/keep@example.test/);
 let check=run(['-r',`require ${q(resolve('app/bootstrap.php'))}; $u=$s->one('SELECT * FROM users'); if(!password_verify(${q(secret)},$u['password']) || $u['role']!=='admin' || $s->setting('bookings_enabled')!=='0' || strlen($config['app_key'])!==64) exit(1);`]);assert.equal(check.status,0,check.stderr);
 const before=readFileSync(config,'utf8');
 r=run(['bin/plesk-setup.php','other@example.test']);assert.notEqual(r.status,0);assert.match(r.stderr,/already exist/);assert.equal(readFileSync(config,'utf8'),before);
 assert(readFileSync(join(dir,'private/initial-admin.txt'),'utf8').includes(secret));
 console.log('PASS: no-SSH setup validates email, generates key and private credentials, preserves configuration, hashes password, disables bookings and refuses repeat setup without resetting accounts.');
} finally {rmSync(dir,{recursive:true,force:true});}
