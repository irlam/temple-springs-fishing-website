'use strict';
(()=>{
 const form=document.querySelector('#ticket-form');if(!form)return;
 const quantity=form.querySelector('#ticket-quantity'),ticket=document.querySelector('#summary-ticket'),qty=document.querySelector('#summary-quantity'),total=document.querySelector('#summary-total');
 function update(){const selected=form.querySelector('[name="type"]:checked'),number=Math.max(1,Number.parseInt(quantity.value,10)||1);if(!selected)return;ticket.textContent=selected.dataset.name;qty.textContent=String(number);total.textContent=new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(Number(selected.dataset.price)*number/100);}
 form.addEventListener('input',update);update();
})();
