'use strict';
(()=>{
 const scene=document.querySelector('.hero-scene'),button=document.querySelector('#motion-toggle');
 if(!scene||!button)return;
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');let paused=false,visible=true;
 try{paused=localStorage.getItem('temple-water-paused')==='1';}catch{}
 function update(){
  scene.classList.toggle('water-moving',!reduced.matches);
  scene.classList.toggle('water-paused',paused||!visible||document.hidden);
  button.hidden=reduced.matches;button.textContent=paused?'Play water animation':'Pause water animation';
  button.setAttribute('aria-pressed',String(paused));
 }
 button.addEventListener('click',()=>{paused=!paused;try{localStorage.setItem('temple-water-paused',paused?'1':'0');}catch{}update();});
 reduced.addEventListener('change',update);document.addEventListener('visibilitychange',update);
 if('IntersectionObserver' in window)new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;update();}).observe(scene);
 update();
})();
