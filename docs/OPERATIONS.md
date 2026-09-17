# Staff and recovery guide

## Everyday use

- `/staff.php`: sign in. Sessions expire after 30 minutes idle or 12 hours total. Password resets/account creation happen with the private CLI `user` command; disabling an account prevents further authenticated actions.
- **Admin**: bookings/payments/check-ins dashboard, searches and CSV, ticket types/prices, daily capacity/closures, opening control, complimentary issue, email retries, unpaid/complimentary cancellation and audit history.
- **Bailiff**: dashboard/search and scanner/check-in. No settings, complimentary issue, CSV export or cancellation access.
- Search by reference, name or email, optionally filtered by date. Each booking lists individual tickets. For a QR scan, customer details appear only after staff authentication. Manual reference lookup on the dashboard is the fallback when a camera is unavailable.
- A valid ticket has an explicit **Confirm check-in for this person** button. Scanning alone never admits someone. Each of a multi-ticket booking's anglers has a distinct QR and independent check-in. Check-in records the staff member and timestamp in Europe/London display time.
- An already-used ticket shows who checked it in and when. Wrong-date, cancelled, refunded, wrong-mode and unknown tickets cannot be checked in. There is no undo/re-entry action that could conceal duplicate use.
- Complimentary tickets count against the same date capacity, need an admin and a reason, are labelled in records and can only be issued while bookings are ON.

## Capacity and controls

The daily limit is an angler count, not a ticket-order count. Each quantity uses that many places. A date override replaces the default limit; a closure blocks new reservations. Lowering capacity below existing reservations shows zero remaining and does not delete bookings. A closure does not automatically refund or invalidate already-issued tickets: contact affected customers and process full refunds in Stripe where appropriate.

The OFF switch is checked both when reserving and immediately before initiating Stripe Checkout. It defaults OFF in the first migration. Saving ON requires an explicit rights confirmation and configured Stripe/webhook/SMTP/privacy settings. Existing paid tickets remain recorded when OFF. Maintenance expires open unpaid Checkout sessions; a payment completed before expiry is still honoured through the signed webhook. To close immediately from SSH, run `php bin/console.php bookings-off` then `maintenance`.

## Payment states

| State | Meaning | Capacity / tickets |
|---|---|---|
| creating | Reservation created; Stripe session creation not yet confirmed | Capacity held; no tickets |
| pending | Stripe Checkout session bound | Capacity held; no tickets |
| paid | Matching signed paid-session event processed | Capacity held; tickets issued once |
| complimentary | Admin issued and recorded free admission | Capacity held; tickets issued |
| expired | Stripe session expired, or unbound uncertain request passed its safe hold window | Released; no valid admission |
| failed | Terminal asynchronous Checkout failure | Released; no tickets |
| cancelled | Confirmed unpaid session expiry or admin complimentary cancellation | Released; invalid tickets |
| partially_refunded | Some money refunded | Capacity/tickets retained; refund amount recorded |
| refunded | Full booking refunded in Stripe | Released; all tickets invalid |
| refund_required | Money arrived after cancellation, on a past date, or after released capacity was taken | No tickets; admin must refund/review |

A declined card attempt is **audited as `payment_attempt_failed`**, not treated as a terminal failed booking while the Stripe page can accept another attempt. Checkout is card-only; asynchronous event handlers are defensive support, not an offer of delayed payment methods.

The local hold lasts approximately 35 minutes, but it is never blindly released while a known Stripe Checkout can still accept payment. An API/network error may leave an unbound `creating` reservation: maintenance releases it only after its original Stripe expiration plus a two-minute safety margin. A later confirmed payment rechecks capacity and cannot cause overbooking; it may require a refund. Known completed sessions retain capacity until Stripe's signed completion event arrives. If a webhook is delayed, the customer status page shows pending and offers refresh; loading it does not confirm payment.

## Exceptions and recovery

- **Pending after a customer paid:** find the session/payment in Stripe, inspect webhook deliveries and resend the signed event. Do not manually change database status to paid. The application checks booking reference, currency, total, session binding and mode.
- **Refund required:** locate the stored payment intent in Stripe, confirm the charge, issue the appropriate refund and check that `charge.refunded` updates the booking. The application does not automatically refund money.
- **Partial refund:** tickets remain valid, as partial refunds may be goodwill adjustments. For fewer anglers, refund the whole booking and arrange a new correctly sized booking; there is no ambiguous partial ticket revocation.
- **Duplicate/out-of-order webhook:** event IDs are unique in a transaction; ticket existence and unique outbox booking IDs also protect against distinct events representing the same completion. Earlier refunds for an as-yet-unbound known payment cause a retry instead of being lost. Expiry cannot downgrade an already-paid booking; payment completion cannot revive a refunded/cancelled one.
- **Email failure:** check SMTP configuration/connectivity and the dashboard email state, then choose Resend. Tickets remain valid and available through the customer's secure status link. No raw SMTP credentials/provider exceptions are rendered in the UI.
- **Stripe unavailable:** leave holds in place until maintenance can reconcile. This may temporarily reduce availability but cannot silently oversell.
- **Lost customer email/link:** an admin can search the booking and resend to the original address. There is no public lookup by email/reference that would reveal customer information. Correcting the email is deliberately not a public capability.
- **Unexpected capacity limit:** inspect closures/date overrides and pending sessions before raising limits. Never raise capacity simply to hide a payment reconciliation issue.

Ticket URLs are bearer credentials. Do not share them publicly. Public QR image generation exposes no customer information; validation and check-in require authentication. Staff pages, tickets, payments and webhook responses are not cached by the application/PWA. Keep server/CDN caching rules aligned with PLESK.md.

## Remaining operator decisions

No opening date, lease, angling rights, access permission, final operating hours, real capacity or final price has been asserted by this application. Replace draft operating rules and complete organiser/privacy details before accepting bookings. The default configuration stays in Stripe test mode and does not contain real secrets.
