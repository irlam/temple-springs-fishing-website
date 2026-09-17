import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require=createRequire(import.meta.url);
import { mkdtemp, rm, readFile, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn, execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
const root=resolve(import.meta.dirname,'..'),dir=await mkdtemp(join(tmpdir(),'temple-http-'));
const php=process.env.PHP_BIN||'php',base='http://127.0.0.1:8093';
let server,checks=0;
function check(value,label){assert.ok(value,label);checks++;console.log('PASS: '+label);}
const csrf=html=>html.match(/name="csrf" value="([a-f0-9]+)"/)?.[1];
const env={...process.env,TEMPLE_TEST_DIR:dir,TEMPLE_CONFIG:join(dir,'config.php')};
try{
 const booking=JSON.parse(execFileSync(php,[join(root,'tests/fixture.php')],{env,encoding:'utf8'}));
 server=spawn(php,['-S','127.0.0.1:8093','-t',join(root,'public')],{env,stdio:['ignore','ignore','pipe']});let errors='';server.stderr.on('data',b=>errors+=b);
 for(let i=0;i<50;i++){try{await fetch(base);break;}catch{await new Promise(r=>setTimeout(r,100));}}
 let r=await fetch(base+'/book.php'),html=await r.text();check(r.status===200&&html.includes('Bookings are not yet open'),'booking screen clearly disabled');
 check(r.headers.get('cache-control').includes('no-store'),'dynamic pages never cache');
 const anonCookie=r.headers.get('set-cookie').split(';')[0];
 r=await fetch(base+'/book.php',{method:'POST',headers:{cookie:anonCookie},body:new URLSearchParams({csrf:csrf(html)||'',date:booking.date,type:'1',quantity:'1',name:'Forgery',email:'test@example.test',rules:'yes'})});
 // Disabled page exposes no form token; direct CSRF-less submission is refused.
 check(r.status===403,'direct booking POST without CSRF denied');
 r=await fetch(base+'/staff.php');html=await r.text();const publicCookie=r.headers.get('set-cookie').split(';')[0],publicCsrf=csrf(html);
 r=await fetch(base+'/book.php',{method:'POST',headers:{cookie:publicCookie},body:new URLSearchParams({csrf:publicCsrf,date:booking.date,type:'1',quantity:'1',name:'Forgery',email:'test@example.test',rules:'yes'})});
 check((await r.text()).includes('Bookings are not yet open'),'valid-CSRF forged POST still cannot bypass OFF');
 for(const route of ['/dashboard.php','/settings.php','/scan.php?token='+'a'.repeat(64)]){
  r=await fetch(base+route,{redirect:'manual'});check(r.status===303&&r.headers.get('location')==='/staff.php','authentication required: '+route.split('?')[0]);
 }
 r=await fetch(base+'/booking.php?token='+booking.token);html=await r.text();check(!html.includes('class="qr"')&&html.includes('Payment has not yet been confirmed'),'success/status URL cannot issue tickets');
 r=await fetch(base+'/booking.php?token='+booking.reference);check(r.status===404,'guessable reference cannot open customer booking');
 for(const path of ['/config.php','/var/temple.sqlite','/app/Booking.php','/composer.json'])check((await fetch(base+path)).status===404,'private path not served: '+path);
 const payload={id:'evt_http_confirmation',object:'event',type:'checkout.session.completed',livemode:false,data:{object:{id:'cs_http',object:'checkout.session',metadata:{booking_id:String(booking.id)},client_reference_id:booking.reference,currency:'gbp',amount_total:booking.total,payment_status:'paid',payment_intent:'pi_http'}}};
 const raw=JSON.stringify(payload),t=Math.floor(Date.now()/1000),sig=`t=${t},v1=${createHmac('sha256','whsec_local_fixture').update(t+'.'+raw).digest('hex')}`;
 r=await fetch(base+'/webhook.php',{method:'POST',body:raw,headers:{'stripe-signature':'bad'}});check(r.status===400,'HTTP webhook rejects invalid signature');
 for(let i=0;i<2;i++){r=await fetch(base+'/webhook.php',{method:'POST',body:raw,headers:{'stripe-signature':sig}});check(r.status===200,'HTTP signed webhook acknowledgement '+(i+1));}
 r=await fetch(base+'/booking.php?token='+booking.token);html=await r.text();check((html.match(/class="qr"/g)||[]).length===2,'webhook issues exactly two individual QR tickets');
 const ticketToken=html.match(/\/qr.php\?token=([a-f0-9]+)/)[1];
 r=await fetch(base+'/qr.php?token='+ticketToken);const svg=await r.text();check(r.status===200&&r.headers.get('content-type').includes('image/svg+xml')&&svg.includes('<svg'),'server generates local SVG QR image');
 check(!svg.includes('customer@example.test')&&!svg.includes('Customer Example'),'QR SVG contains no personal details');
 async function login(role){let r=await fetch(base+'/staff.php'),text=await r.text(),cookie=r.headers.get('set-cookie').split(';')[0];r=await fetch(base+'/staff.php',{method:'POST',redirect:'manual',headers:{cookie},body:new URLSearchParams({csrf:csrf(text),email:role+'@example.test',password:'Local-test-password-7491'})});assert.equal(r.status,303);return r.headers.get('set-cookie').split(';')[0];}
 const bailiff=await login('bailiff');
 r=await fetch(base+'/settings.php',{headers:{cookie:bailiff}});check(r.status===403,'bailiff cannot open admin settings');
 r=await fetch(base+'/dashboard.php?export=1',{headers:{cookie:bailiff}});check(r.status===403,'bailiff cannot export customer CSV');
 r=await fetch(base+'/scan.php?token='+ticketToken,{headers:{cookie:bailiff}});html=await r.text();check(html.includes('Confirm check-in')&&html.includes('customer@example.test'),'authenticated scanner shows valid customer details');
 let body=new URLSearchParams({csrf:csrf(html),token:ticketToken});
 r=await fetch(base+'/scan.php',{method:'POST',headers:{cookie:bailiff},body});check((await r.text()).includes('Already Used'),'explicit staff POST checks in');
 r=await fetch(base+'/scan.php',{method:'POST',headers:{cookie:bailiff},body});check((await r.text()).includes('Check-in refused'),'duplicate HTTP check-in rejected');
 r=await fetch(base+'/scan.php',{method:'POST',headers:{cookie:bailiff},body:new URLSearchParams({token:ticketToken,csrf:'wrong'})});check(r.status===403,'check-in CSRF rejected');
 const admin=await login('admin');r=await fetch(base+'/dashboard.php?export=1',{headers:{cookie:admin}});const csv=await r.text();check(csv.includes("'=Customer Example")&&csv.startsWith('Reference,Date'),'CSV export protects against spreadsheet formula injection');
 r=await fetch(base+'/settings.php',{headers:{cookie:admin}});html=await r.text();
 r=await fetch(base+'/settings.php',{method:'POST',headers:{cookie:admin},body:new URLSearchParams({csrf:csrf(html),action:'settings',enabled:'1',capacity:'20'})});check((await r.text()).includes('Confirm that angling rights'),'opening requires explicit rights confirmation');
 // Exercise the enabled workflow only in this isolated temporary fixture database.
 execFileSync(php,['-r',`require ${JSON.stringify(join(root,'app/bootstrap.php'))}; $s->run("UPDATE settings SET value='1' WHERE key='bookings_enabled'");`],{env});
 r=await fetch(base+'/settings.php',{headers:{cookie:admin}});html=await r.text();const adminCsrf=csrf(html);
 r=await fetch(base+'/settings.php',{method:'POST',headers:{cookie:admin},body:new URLSearchParams({csrf:adminCsrf,action:'type',id:'1',name:'Adult day ticket',price:'12.34',active:'1'})});check((await r.text()).includes('Changes saved'),'admin updates ticket prices');
 r=await fetch(base+'/book.php');html=await r.text();check(html.includes('£12.34')&&html.includes('Continue to secure payment'),'enabled booking form shows configured prices and checkout journey');
 r=await fetch(base+'/settings.php',{method:'POST',headers:{cookie:admin},body:new URLSearchParams({csrf:adminCsrf,action:'day',date:booking.date,capacity:'5',closed:'1',note:'Test closure'})});check((await r.text()).includes('Changes saved'),'admin saves date closure');
 r=await fetch(base+'/book.php');check((await r.text()).includes('Closed on this date'),'public availability enforces saved closure');
 await fetch(base+'/settings.php',{method:'POST',headers:{cookie:admin},body:new URLSearchParams({csrf:adminCsrf,action:'day',date:booking.date,capacity:'5'})});
 r=await fetch(base+'/settings.php',{method:'POST',redirect:'manual',headers:{cookie:admin},body:new URLSearchParams({csrf:adminCsrf,action:'comp',date:booking.date,type:'1',quantity:'1',name:'Guest Example',email:'guest@example.test',reason:'Recorded test invitation'})});check(r.status===303,'admin issues complimentary ticket');
 r=await fetch(base+r.headers.get('location'),{headers:{cookie:admin}});html=await r.text();check(html.includes('Recorded test invitation')&&html.includes('complimentary'),'complimentary issue retains reason and status');
 const sw=await readFile(join(root,'public/sw.js'),'utf8');check(!/ASSETS=\[[^\]]*(\.php|\.html|'\/')/.test(sw)&&sw.includes('u.search'),'service worker excludes pages and query strings');
 // Optional real browser checks: layout, QR decoding and actual sign-in on mobile.
 if(process.env.TEMPLE_BROWSER==='1'){
  const {chromium}=require('playwright');const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE||undefined,args:['--no-sandbox','--disable-dev-shm-usage','--no-zygote','--single-process','--disable-gpu','--disable-software-rasterizer']});
  try{
   const page=await browser.newPage({viewport:{width:390,height:844}});const pageErrors=[];page.on('pageerror',e=>pageErrors.push(e.message));
   for(const path of ['/','/book.php','/staff.php']){await page.goto(base+path);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'390px layout fits: '+path);}
   await page.getByLabel('Email',{exact:true}).fill('admin@example.test');await page.getByLabel('Password',{exact:true}).fill('Local-test-password-7491');await page.getByRole('button',{name:'Sign in securely'}).click();await page.waitForURL('**/dashboard.php');
   check(await page.getByRole('heading',{name:'Fishery dashboard'}).isVisible(),'browser staff sign-in works');
   for(const path of ['/dashboard.php','/settings.php','/scan.php?token='+ticketToken]){await page.goto(base+path);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'staff mobile layout fits: '+path.split('?')[0]);}
   await page.goto(base+'/booking.php?token='+booking.token);
   const decoded=await page.evaluate(async()=>{const img=document.querySelector('.qr');await img.decode();const c=document.createElement('canvas');c.width=img.naturalWidth;c.height=img.naturalHeight;const x=c.getContext('2d');x.fillStyle='white';x.fillRect(0,0,c.width,c.height);x.drawImage(img,0,0);const d=x.getImageData(0,0,c.width,c.height);return window.jsQR(d.data,d.width,d.height)?.data;});
   check(decoded===base+'/scan.php?token='+ticketToken,'rendered QR decodes to opaque staff validation link');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile tickets fit screen');
   const screenshots=process.env.TEMPLE_SCREENSHOTS;
   if(screenshots){await mkdir(screenshots,{recursive:true});await page.screenshot({path:join(screenshots,'mobile-ticket.png'),fullPage:true});await page.goto(base+'/dashboard.php');await page.screenshot({path:join(screenshots,'mobile-dashboard.png'),fullPage:true});await page.setViewportSize({width:1440,height:1000});await page.goto(base+'/');await page.screenshot({path:join(screenshots,'desktop-home.png'),fullPage:true});}
   check(pageErrors.length===0,'browser reports no JavaScript errors');
  }finally{await browser.close();}
 }
 check(!errors.includes('Fatal error')&&!errors.includes('Warning:'),'HTTP routes produce no PHP fatal errors or warnings');
 console.log(`\n${checks} HTTP/browser checks passed. No real payment or email sent.`);
}finally{if(server){server.kill();await new Promise(r=>server.once('exit',r));}await rm(dir,{recursive:true,force:true});}
