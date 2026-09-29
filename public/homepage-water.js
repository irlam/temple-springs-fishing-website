'use strict';
(()=>{
 const scene=document.querySelector('.hero-scene');
 const canvas=document.querySelector('#jumping-fish-canvas');
 const button=document.querySelector('#motion-toggle');
 if(!scene||!canvas||!button)return;

 const ctx=canvas.getContext('2d',{alpha:true});
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 const species=[
  {body:'#c8d2c8',back:'#607b76',belly:'#eef0df',fin:'#a9503d',bars:0},
  {body:'#7b8546',back:'#344a2d',belly:'#aab06b',fin:'#56683b',bars:0},
  {body:'#b18b49',back:'#674c2d',belly:'#d1b76c',fin:'#8c6838',bars:0},
  {body:'#9aa85d',back:'#435f3b',belly:'#c4ca79',fin:'#c36c38',bars:6}
 ];
 let width=1,height=1,dpr=1,last=0,nextJump=350,paused=false,visible=true;
 const fish=[],ripples=[],drops=[];
 try{paused=localStorage.getItem('temple-fish-paused')==='1';}catch{}

 function resize(){
  const box=scene.getBoundingClientRect();width=Math.max(1,box.width);height=Math.max(1,box.height);dpr=Math.min(devicePixelRatio||1,2);
  canvas.width=Math.round(width*dpr);canvas.height=Math.round(height*dpr);canvas.style.width=width+'px';canvas.style.height=height+'px';ctx.setTransform(dpr,0,0,dpr,0,0);
 }
 function addRipple(x,y,delay=0){ripples.push({x,y,age:-delay,life:900});}
 function splash(x,y,side){
  addRipple(x,y);addRipple(x,y,160);
  for(let i=0;i<13;i++)drops.push({x,y,vx:(Math.random()-.5)*72+side*18,vy:-35-Math.random()*100,age:0,life:520+Math.random()*280,r:1+Math.random()*2});
 }
 function spawn(){
  const mobile=width<720,direction=Math.random()>.5?1:-1;
  const zoneStart=mobile?width*.56:width*.63,zoneEnd=width*.92,span=Math.min(mobile?width*.32:width*.24,250);
  let start=zoneStart+Math.random()*Math.max(20,zoneEnd-zoneStart-span);if(direction<0)start+=span;
  const waterY=height*(mobile?.69:.70)+Math.random()*18;
  fish.push({kind:species[Math.floor(Math.random()*species.length)],start,waterY,direction,span,height:height*(mobile?.16:.22),age:0,duration:1450+Math.random()*500,size:(mobile?30:42)+Math.random()*(mobile?13:22)});
  splash(start,waterY,direction);
 }
 function fishPath(f,p){
  const x=f.start+f.direction*f.span*p,y=f.waterY-Math.sin(Math.PI*p)*f.height;
  return{x,y,angle:Math.atan2(-Math.PI*f.height*Math.cos(Math.PI*p),f.direction*f.span)};
 }
 function bodyPath(size){
  ctx.beginPath();ctx.moveTo(-size*.55,0);ctx.bezierCurveTo(-size*.25,-size*.32,size*.28,-size*.32,size*.55,0);ctx.bezierCurveTo(size*.28,size*.32,-size*.25,size*.32,-size*.55,0);ctx.closePath();
 }
 function drawFish(f,p,reflection=false){
  const pos=fishPath(f,p),fade=Math.min(1,p*5,(1-p)*5),s=f.size;ctx.save();
  ctx.translate(pos.x,reflection?f.waterY+(f.waterY-pos.y)*.48:pos.y);ctx.scale(1,reflection?-.48:1);ctx.rotate(pos.angle);ctx.globalAlpha=(reflection?.12:.72)*fade;
  const g=ctx.createLinearGradient(0,-s*.26,0,s*.28);g.addColorStop(0,f.kind.back);g.addColorStop(.52,f.kind.body);g.addColorStop(1,f.kind.belly);
  ctx.fillStyle=f.kind.fin;ctx.beginPath();ctx.moveTo(-s*.49,0);ctx.lineTo(-s*.82,-s*.31);ctx.lineTo(-s*.72,0);ctx.lineTo(-s*.82,s*.31);ctx.closePath();ctx.fill();
  ctx.beginPath();ctx.moveTo(-s*.12,-s*.22);ctx.lineTo(s*.05,-s*.43);ctx.lineTo(s*.25,-s*.2);ctx.closePath();ctx.fill();
  ctx.beginPath();ctx.moveTo(-s*.03,s*.2);ctx.lineTo(-s*.18,s*.41);ctx.lineTo(s*.17,s*.22);ctx.closePath();ctx.fill();
  ctx.fillStyle=g;bodyPath(s);ctx.fill();
  if(f.kind.bars){ctx.save();bodyPath(s);ctx.clip();ctx.strokeStyle='#273f32aa';ctx.lineWidth=s*.065;for(let i=0;i<f.kind.bars;i++){const x=-s*.30+i*s*.13;ctx.beginPath();ctx.moveTo(x,-s*.25);ctx.lineTo(x+s*.08,s*.24);ctx.stroke();}ctx.restore();}
  ctx.strokeStyle='#f4efcf55';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(-s*.32,0);ctx.quadraticCurveTo(0,-s*.08,s*.36,0);ctx.stroke();
  ctx.fillStyle='#e8c46c';ctx.beginPath();ctx.arc(s*.37,-s*.07,s*.055,0,Math.PI*2);ctx.fill();ctx.fillStyle='#142a25';ctx.beginPath();ctx.arc(s*.385,-s*.07,s*.029,0,Math.PI*2);ctx.fill();ctx.restore();
 }
 function drawRipple(r){if(r.age<0)return;const p=Math.min(1,r.age/r.life);ctx.save();ctx.globalAlpha=(1-p)*.34;ctx.strokeStyle='#e5edcf';ctx.lineWidth=1.2;ctx.beginPath();ctx.ellipse(r.x,r.y,9+p*62,3+p*13,0,0,Math.PI*2);ctx.stroke();ctx.restore();}
 function drawDrop(d){const t=d.age/1000;ctx.save();ctx.globalAlpha=Math.max(0,1-d.age/d.life)*.55;ctx.fillStyle='#eff3dd';ctx.beginPath();ctx.arc(d.x+d.vx*t,d.y+d.vy*t+170*t*t,d.r,0,Math.PI*2);ctx.fill();ctx.restore();}
 function frame(now){
  const delta=Math.min(40,now-last||16);last=now;ctx.clearRect(0,0,width,height);const running=!paused&&!reduced.matches&&visible&&!document.hidden;
  if(running){nextJump-=delta;if(nextJump<=0&&fish.length<3){spawn();nextJump=900+Math.random()*1800;}fish.forEach(f=>f.age+=delta);ripples.forEach(r=>r.age+=delta);drops.forEach(d=>d.age+=delta);}
  for(const f of fish){const p=Math.min(1,f.age/f.duration);drawFish(f,p,true);drawFish(f,p,false);if(running&&f.age>=f.duration&&f.age-delta<f.duration)splash(f.start+f.direction*f.span,f.waterY,f.direction);}
  ripples.forEach(drawRipple);drops.forEach(drawDrop);
  for(let i=fish.length-1;i>=0;i--)if(fish[i].age>fish[i].duration+80)fish.splice(i,1);
  for(let i=ripples.length-1;i>=0;i--)if(ripples[i].age>ripples[i].life)ripples.splice(i,1);
  for(let i=drops.length-1;i>=0;i--)if(drops[i].age>drops[i].life)drops.splice(i,1);
  requestAnimationFrame(frame);
 }
 function update(){const stopped=paused||reduced.matches;button.hidden=reduced.matches;button.textContent=paused?'Play fish animation':'Pause fish animation';button.setAttribute('aria-pressed',String(paused));scene.classList.toggle('water-paused',stopped);}
 button.addEventListener('click',()=>{paused=!paused;try{localStorage.setItem('temple-fish-paused',paused?'1':'0');}catch{}update();});
 reduced.addEventListener('change',update);document.addEventListener('visibilitychange',update);
 if('ResizeObserver' in window)new ResizeObserver(resize).observe(scene);else addEventListener('resize',resize);
 if('IntersectionObserver' in window)new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;}).observe(scene);
 resize();update();if(!reduced.matches){spawn();setTimeout(()=>{if(!paused)spawn();},620);}requestAnimationFrame(frame);
})();
