/*
 * File: site-ambient.js
 * Purpose: Draws a soft, highly transparent, drifting "bubble"/light
 *          particle animation that sits behind the whole page (outside
 *          of the hero banner, which keeps its own separate jumping fish
 *          animation from homepage-water.js completely unchanged).
 * Notes:   - Fixed full-page canvas, pointer-events:none, very low opacity
 *            (see site-ambient.css), so it never blocks clicks or reading.
 *          - Automatically stays off for prefers-reduced-motion, when the
 *            browser tab is hidden, or if the page is being printed.
 * Last updated: 29/09/2026 (UK date format dd/mm/yyyy)
 */
'use strict';
(()=>{
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 if(reduced.matches)return;

 const wrap=document.createElement('div');
 wrap.className='site-ambient';
 wrap.setAttribute('aria-hidden','true');
 const canvas=document.createElement('canvas');
 canvas.id='site-ambient-canvas';
 wrap.appendChild(canvas);
 document.body.insertBefore(wrap,document.body.firstChild);

 const ctx=canvas.getContext('2d',{alpha:true});
 let width=1,height=1,dpr=1,last=0;
 const particles=[];
 const COUNT_PER_1000PX=0.06;

 function resize(){
  width=window.innerWidth;height=Math.max(window.innerHeight,document.documentElement.scrollHeight);
  dpr=Math.min(devicePixelRatio||1,1.5);
  canvas.width=Math.round(width*dpr);canvas.height=Math.round(height*dpr);
  canvas.style.width=width+'px';canvas.style.height=height+'px';
  ctx.setTransform(dpr,0,0,dpr,0,0);
  seed();
 }
 function seed(){
  const target=Math.round(Math.min(60,Math.max(18,(width*height/1000)*COUNT_PER_1000PX)));
  particles.length=0;
  for(let i=0;i<target;i++)particles.push(makeParticle(true));
 }
 function makeParticle(randomY){
  return{
   x:Math.random()*width,
   y:randomY?Math.random()*height:height+20,
   r:6+Math.random()*22,
   speed:6+Math.random()*14,
   drift:(Math.random()-.5)*10,
   wobble:Math.random()*Math.PI*2,
   wobbleSpeed:.4+Math.random()*.6,
   alpha:.08+Math.random()*.18
  };
 }
 function step(delta){
  const t=delta/1000;
  for(const p of particles){
   p.wobble+=p.wobbleSpeed*t;
   p.y-=p.speed*t;
   p.x+=Math.sin(p.wobble)*p.drift*t;
   if(p.y< -30){Object.assign(p,makeParticle(false));}
  }
 }
 function draw(){
  ctx.clearRect(0,0,width,height);
  for(const p of particles){
   const g=ctx.createRadialGradient(p.x,p.y,0,p.x,p.y,p.r);
   g.addColorStop(0,`rgba(223,236,196,${p.alpha})`);
   g.addColorStop(1,'rgba(223,236,196,0)');
   ctx.fillStyle=g;
   ctx.beginPath();
   ctx.arc(p.x,p.y,p.r,0,Math.PI*2);
   ctx.fill();
  }
 }
 function frame(now){
  const delta=Math.min(50,now-last||16);last=now;
  if(!document.hidden){step(delta);draw();}
  requestAnimationFrame(frame);
 }
 let resizeTimer;
 function onResize(){clearTimeout(resizeTimer);resizeTimer=setTimeout(resize,150);}
 addEventListener('resize',onResize);
 addEventListener('load',resize);
 reduced.addEventListener('change',()=>{wrap.style.display=reduced.matches?'none':'';});
 resize();
 requestAnimationFrame(frame);
})();
