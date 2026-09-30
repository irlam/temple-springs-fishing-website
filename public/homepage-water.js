'use strict';
(()=>{
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 const species=[
  {body:'#cbd3c8',back:'#506b68',belly:'#f0edcf',fin:'#b95342',bars:0,depth:.27},
  {body:'#78864a',back:'#30482d',belly:'#aeb56c',fin:'#4d633c',bars:0,depth:.30},
  {body:'#b18a48',back:'#62492d',belly:'#d4b96d',fin:'#836037',bars:0,depth:.34},
  {body:'#99a65b',back:'#3e5938',belly:'#c5ca78',fin:'#c86a38',bars:6,depth:.31}
 ];
 document.querySelectorAll('[data-fish-scene]').forEach(init);

 function init(scene){
  const canvas=scene.querySelector('.jumping-fish-canvas');if(!canvas)return;
  const ctx=canvas.getContext('2d',{alpha:true}),compact=scene.dataset.fishScene==='card';
  let width=1,height=1,dpr=1,last=0,nextJump=compact?200:350,visible=true;
  const fish=[],ripples=[],drops=[];
  function resize(){const box=scene.getBoundingClientRect();width=Math.max(1,box.width);height=Math.max(1,box.height);dpr=Math.min(devicePixelRatio||1,2);canvas.width=Math.round(width*dpr);canvas.height=Math.round(height*dpr);canvas.style.width=width+'px';canvas.style.height=height+'px';ctx.setTransform(dpr,0,0,dpr,0,0);}
  function addRipple(x,y,delay=0){ripples.push({x,y,age:-delay,life:900});}
  function splash(x,y,side){addRipple(x,y);addRipple(x,y,160);for(let i=0;i<11;i++)drops.push({x,y,vx:(Math.random()-.5)*72+side*18,vy:-35-Math.random()*95,age:0,life:520+Math.random()*260,r:1+Math.random()*1.8});}
  function spawn(){const mobile=width<720,direction=Math.random()>.5?1:-1,zoneStart=compact?width*.10:(mobile?width*.54:width*.61),zoneEnd=compact?width*.91:width*.92,span=Math.min(compact?width*.58:(mobile?width*.34:width*.25),compact?185:260);let start=zoneStart+Math.random()*Math.max(12,zoneEnd-zoneStart-span);if(direction<0)start+=span;const waterY=height*(compact?.79:(mobile?.70:.71))+Math.random()*(compact?4:16);fish.push({kind:species[Math.floor(Math.random()*species.length)],start,waterY,direction,span,height:height*(compact?.45:(mobile?.17:.23)),age:0,duration:(compact?1250:1450)+Math.random()*450,size:(compact?31:(mobile?32:44))+Math.random()*(compact?7:14)});splash(start,waterY,direction);}
  function fishPath(f,p){const x=f.start+f.direction*f.span*p,y=f.waterY-Math.sin(Math.PI*p)*f.height;return{x,y,angle:Math.atan2(-Math.PI*f.height*Math.cos(Math.PI*p),Math.abs(f.span))};}
  function bodyPath(s,depth){ctx.beginPath();ctx.moveTo(-s*.53,0);ctx.bezierCurveTo(-s*.40,-s*depth,-s*.08,-s*(depth+.05),s*.25,-s*(depth-.02));ctx.bezierCurveTo(s*.43,-s*(depth-.07),s*.56,-s*.12,s*.60,-s*.02);ctx.quadraticCurveTo(s*.61,s*.04,s*.53,s*.09);ctx.bezierCurveTo(s*.36,s*(depth-.03),s*.02,s*(depth+.01),-s*.29,s*(depth-.02));ctx.quadraticCurveTo(-s*.45,s*.16,-s*.53,0);ctx.closePath();}
  function drawFish(f,p,reflection=false){
   const pos=fishPath(f,p),fade=Math.min(1,p*5,(1-p)*5),s=f.size,k=f.kind;ctx.save();ctx.translate(pos.x,reflection?f.waterY+(f.waterY-pos.y)*.48:pos.y);ctx.scale(1,reflection?-.48:1);ctx.rotate(pos.angle);ctx.scale(f.direction,1);ctx.globalAlpha=(reflection?.13:.92)*fade;
   const g=ctx.createLinearGradient(0,-s*.34,0,s*.34);g.addColorStop(0,k.back);g.addColorStop(.5,k.body);g.addColorStop(1,k.belly);
   ctx.fillStyle=k.fin;ctx.beginPath();ctx.moveTo(-s*.47,-s*.07);ctx.lineTo(-s*.84,-s*.34);ctx.lineTo(-s*.74,-s*.04);ctx.lineTo(-s*.88,0);ctx.lineTo(-s*.74,s*.05);ctx.lineTo(-s*.84,s*.34);ctx.lineTo(-s*.47,s*.08);ctx.closePath();ctx.fill();
   ctx.beginPath();ctx.moveTo(-s*.24,-s*.23);ctx.quadraticCurveTo(-s*.06,-s*.52,s*.23,-s*.25);ctx.lineTo(s*.18,-s*.19);ctx.quadraticCurveTo(-s*.02,-s*.30,-s*.24,-s*.18);ctx.closePath();ctx.fill();
   ctx.beginPath();ctx.moveTo(-s*.03,s*.22);ctx.lineTo(-s*.19,s*.43);ctx.lineTo(s*.20,s*.24);ctx.closePath();ctx.fill();
   ctx.fillStyle=g;bodyPath(s,k.depth);ctx.fill();ctx.strokeStyle='#223f35aa';ctx.lineWidth=Math.max(1,s*.018);ctx.stroke();
   if(k.bars){ctx.save();bodyPath(s,k.depth);ctx.clip();ctx.strokeStyle='#263d31a8';ctx.lineWidth=s*.06;for(let i=0;i<k.bars;i++){const x=-s*.30+i*s*.13;ctx.beginPath();ctx.moveTo(x,-s*.28);ctx.lineTo(x+s*.03,s*.27);ctx.stroke();}ctx.restore();}
   ctx.strokeStyle='#314d4375';ctx.lineWidth=Math.max(1,s*.018);ctx.beginPath();ctx.arc(s*.31,0,s*.18,-1.25,1.25);ctx.stroke();ctx.strokeStyle='#f5efd05c';ctx.beginPath();ctx.moveTo(-s*.35,s*.02);ctx.quadraticCurveTo(0,-s*.03,s*.43,s*.02);ctx.stroke();
   ctx.fillStyle=k.fin;ctx.globalAlpha*=.82;ctx.beginPath();ctx.moveTo(s*.16,s*.07);ctx.quadraticCurveTo(s*.04,s*.26,s*.31,s*.26);ctx.closePath();ctx.fill();ctx.globalAlpha/=.82;
   if(!reflection){ctx.fillStyle='#efd17c';ctx.beginPath();ctx.arc(s*.43,-s*.09,s*.07,0,Math.PI*2);ctx.fill();ctx.fillStyle='#102923';ctx.beginPath();ctx.arc(s*.445,-s*.09,s*.035,0,Math.PI*2);ctx.fill();ctx.fillStyle='#f4f1d8';ctx.beginPath();ctx.arc(s*.455,-s*.105,s*.012,0,Math.PI*2);ctx.fill();ctx.strokeStyle='#2b4138';ctx.beginPath();ctx.arc(s*.57,s*.02,s*.08,-1.1,.7);ctx.stroke();}ctx.restore();
  }
  function drawRipple(r){if(r.age<0)return;const p=Math.min(1,r.age/r.life);ctx.save();ctx.globalAlpha=(1-p)*.35;ctx.strokeStyle='#e8efd4';ctx.lineWidth=1.2;ctx.beginPath();ctx.ellipse(r.x,r.y,8+p*(compact?34:62),3+p*(compact?8:13),0,0,Math.PI*2);ctx.stroke();ctx.restore();}
  function drawDrop(d){const t=d.age/1000;ctx.save();ctx.globalAlpha=Math.max(0,1-d.age/d.life)*.58;ctx.fillStyle='#eff3dd';ctx.beginPath();ctx.arc(d.x+d.vx*t,d.y+d.vy*t+170*t*t,d.r,0,Math.PI*2);ctx.fill();ctx.restore();}
  function frame(now){const delta=Math.min(40,now-last||16);last=now;ctx.clearRect(0,0,width,height);const running=!reduced.matches&&visible&&!document.hidden;if(running){nextJump-=delta;if(nextJump<=0&&fish.length<(compact?2:3)){spawn();nextJump=(compact?1150:900)+Math.random()*(compact?1500:1800);}fish.forEach(f=>f.age+=delta);ripples.forEach(r=>r.age+=delta);drops.forEach(d=>d.age+=delta);}for(const f of fish){const p=Math.min(1,f.age/f.duration);drawFish(f,p,true);drawFish(f,p,false);if(running&&f.age>=f.duration&&f.age-delta<f.duration)splash(f.start+f.direction*f.span,f.waterY,f.direction);}ripples.forEach(drawRipple);drops.forEach(drawDrop);for(let i=fish.length-1;i>=0;i--)if(fish[i].age>fish[i].duration+80)fish.splice(i,1);for(let i=ripples.length-1;i>=0;i--)if(ripples[i].age>ripples[i].life)ripples.splice(i,1);for(let i=drops.length-1;i>=0;i--)if(drops[i].age>drops[i].life)drops.splice(i,1);requestAnimationFrame(frame);}
  reduced.addEventListener('change',()=>scene.classList.toggle('water-paused',reduced.matches));if('ResizeObserver' in window)new ResizeObserver(resize).observe(scene);else addEventListener('resize',resize);if('IntersectionObserver' in window)new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;}).observe(scene);resize();scene.classList.toggle('water-paused',reduced.matches);if(!reduced.matches){spawn();setTimeout(spawn,compact?720:620);}requestAnimationFrame(frame);
 }
})();
