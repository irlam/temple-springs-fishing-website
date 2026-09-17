import assert from 'node:assert/strict';
import {mkdtemp,rm,readdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';import{join,resolve}from'node:path';import{execFileSync}from'node:child_process';
const root=resolve(import.meta.dirname,'..'),dir=await mkdtemp(join(tmpdir(),'temple-cli-')),php=process.env.PHP_BIN||'php';
const env={...process.env,TEMPLE_CONFIG:join(dir,'config.php')};
const run=(...args)=>execFileSync(php,args,{env,encoding:'utf8'});
const cli=command=>run(join(root,'bin/console.php'),command);
try{
 run('-r',`$c=require ${JSON.stringify(join(root,'config.example.php'))};$c['data_dir']=${JSON.stringify(join(dir,'private'))};$c['app_key']=bin2hex(random_bytes(32));file_put_contents(getenv('TEMPLE_CONFIG'),'<?php return '.var_export($c,true).';');`);
 assert.match(cli('migrate'),/Applied 001.sql/); console.log('PASS: initial migration creates database');
 assert.match(cli('health'),/bookings: OFF/); console.log('PASS: new database defaults to bookings OFF');
 run('-r',`require ${JSON.stringify(join(root,'app/bootstrap.php'))};$s->run("UPDATE settings SET value='37' WHERE key='daily_capacity'");`);
 assert.doesNotMatch(cli('migrate'),/Applied/);
 assert.equal(run('-r',`require ${JSON.stringify(join(root,'app/bootstrap.php'))};echo $s->setting('daily_capacity');`),'37');console.log('PASS: migration rerun preserves operator settings');
 assert.match(cli('backup'),/Consistent SQLite backup created/);assert.equal((await readdir(join(dir,'private/backups'))).length,1);console.log('PASS: backup command creates private snapshot');
 assert.match(cli('maintenance'),/failures: 0/);console.log('PASS: maintenance runs safely with bookings closed and no payment credentials');
 console.log('\n5 CLI checks passed.');
}finally{await rm(dir,{recursive:true,force:true});}
