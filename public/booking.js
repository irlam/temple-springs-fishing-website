'use strict';
document.querySelector('#print-tickets')?.addEventListener('click',()=>window.print());
const start=document.querySelector('#start-camera'),stop=document.querySelector('#stop-camera'),video=document.querySelector('#scanner'),status=document.querySelector('#scanner-status');
let stream,running=false;
function stopCamera(){running=false;stream?.getTracks().forEach(t=>t.stop());if(video){video.srcObject=null;video.hidden=true;}if(stop)stop.hidden=true;if(start)start.disabled=false;}
function ticketToken(value){try{const u=new URL(value,location.origin);if(u.origin===location.origin&&u.pathname==='/scan.php'&&/^[a-f0-9]{64}$/.test(u.searchParams.get('token')))return u.searchParams.get('token');}catch{}return /^[a-f0-9]{64}$/.test(value)?value:null;}
start?.addEventListener('click',async()=>{
  start.disabled=true;status.textContent='Requesting camera access…';
  try{
    if(!navigator.mediaDevices?.getUserMedia)throw new Error('Camera access requires HTTPS and browser permission. Use the manual lookup below.');
    stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
    video.srcObject=stream;video.hidden=false;stop.hidden=false;await video.play();running=true;
    const native=typeof BarcodeDetector!=='undefined'&&(await BarcodeDetector.getSupportedFormats()).includes('qr_code');
    const detector=native?new BarcodeDetector({formats:['qr_code']}):null;
    if(!detector&&!window.jsQR)throw new Error('QR decoder unavailable. Use the manual lookup below.');
    const canvas=document.createElement('canvas'),ctx=canvas.getContext('2d',{willReadFrequently:true});
    status.textContent='Point the camera at the QR code. You will review the ticket before check-in.';
    async function scan(){
      if(!running)return;
      try{
        let raw;
        if(detector){raw=(await detector.detect(video))[0]?.rawValue;}
        else if(video.readyState>=2){canvas.width=640;canvas.height=Math.round(video.videoHeight*640/video.videoWidth);ctx.drawImage(video,0,0,canvas.width,canvas.height);const frame=ctx.getImageData(0,0,canvas.width,canvas.height);raw=window.jsQR(frame.data,frame.width,frame.height,{inversionAttempts:'dontInvert'})?.data;}
        if(raw){const token=ticketToken(raw);if(token){stopCamera();location.assign('/scan.php?token='+token);return;}status.textContent='That QR is not a Temple Springs ticket.';}
      }catch{status.textContent='Unable to read this frame. Hold the ticket steady or use manual lookup.';}
      if(running)setTimeout(scan,200);
    }
    scan();
  }catch(e){stopCamera();status.textContent=e.message||'Camera unavailable. Use manual lookup.';}
});
stop?.addEventListener('click',()=>{stopCamera();status.textContent='Camera stopped.';});
window.addEventListener('pagehide',stopCamera);
document.addEventListener('visibilitychange',()=>{if(document.hidden)stopCamera();});
