const menu = document.querySelector('.menu');
const nav = document.querySelector('#navigation');
menu.hidden = false;
nav.dataset.collapsed = 'true';
menu.addEventListener('click', () => {
  const expanded = menu.getAttribute('aria-expanded') !== 'true';
  menu.setAttribute('aria-expanded', String(expanded));
  nav.dataset.collapsed = String(!expanded);
});
nav.addEventListener('click', event => {
  if (event.target.closest('a')) { menu.setAttribute('aria-expanded', 'false'); nav.dataset.collapsed = 'true'; }
});
document.querySelector('#year').textContent = new Date().getFullYear();
const form = document.querySelector('form');
const status = document.querySelector('#form-status');
const fields = document.querySelector('#contact-fields');
async function initialiseForm() {
  try {
    const response = await fetch('/contact.php', { cache: 'no-store', credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok || !data.enabled) throw new Error();
    document.querySelector('#form-token').value = data.token;
    fields.disabled = false;
    status.textContent = '';
  } catch {
    status.textContent = 'Enquiries are not available at the moment. Please check back later. No details have been sent.';
  }
}
form.addEventListener('submit', async event => {
  event.preventDefault();
  const body = new FormData(form);
  fields.disabled = true;
  status.textContent = 'Sending your enquiry…';
  try {
    const response = await fetch('/contact.php', { method: 'POST', body, credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Your enquiry could not be sent. Please try again later.');
    form.reset();
    status.textContent = data.message;
  } catch (error) {
    status.textContent = error.message === 'Failed to fetch' ? 'Unable to confirm delivery. Please check your connection before trying again.' : error.message;
  } finally { fields.disabled = false; }
});
initialiseForm();
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
