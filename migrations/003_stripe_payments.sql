-- Stripe payment/refund tracking for the production checkout flow.
-- Monetary amounts are stored in pence.
--
-- Existing columns already used for Stripe:
--   bookings.session_id      -> Stripe Checkout Session ID
--   bookings.payment_intent  -> Stripe PaymentIntent ID
--   bookings.refund_amount   -> cumulative refunded amount
--   bookings.token           -> booking token
--   events.id                -> Stripe webhook event ID (idempotency)
--
-- This migration adds richer payment metadata, webhook processing state,
-- ticket generation timestamps, and a refund history table so partial or
-- repeated refunds can be tracked safely.

ALTER TABLE bookings
ADD COLUMN payment_method TEXT;

ALTER TABLE bookings
ADD COLUMN amount_paid INTEGER NOT NULL DEFAULT 0
    CHECK(amount_paid >= 0);

ALTER TABLE bookings
ADD COLUMN refunded_at INTEGER;

-- Backfill already-paid bookings when upgrading an existing database.
UPDATE bookings
SET amount_paid = total
WHERE status IN ('paid', 'partially_refunded', 'refund_required', 'refunded')
  AND amount_paid = 0;


ALTER TABLE tickets
ADD COLUMN generated_at INTEGER;


-- events.id remains the unique Stripe event ID. The primary key prevents
-- duplicate webhook delivery from being processed as a new event.
ALTER TABLE events
ADD COLUMN processed_at INTEGER;

ALTER TABLE events
ADD COLUMN status TEXT NOT NULL DEFAULT 'received'
    CHECK(status IN ('received', 'processed', 'ignored', 'failed'));

ALTER TABLE events
ADD COLUMN last_error TEXT;


-- A separate refund table supports full and partial refunds and keeps a
-- permanent audit trail for each Stripe refund rather than storing only the
-- latest refund ID on the booking.
CREATE TABLE refunds (
    id INTEGER PRIMARY KEY,

    booking_id INTEGER NOT NULL
        REFERENCES bookings(id),

    stripe_refund_id TEXT UNIQUE NOT NULL,

    amount INTEGER NOT NULL
        CHECK(amount > 0),

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN (
            'pending',
            'succeeded',
            'failed',
            'cancelled'
        )),

    reason TEXT NOT NULL DEFAULT '',

    requested_at INTEGER NOT NULL,
    completed_at INTEGER,

    failure_reason TEXT NOT NULL DEFAULT '',

    CHECK(
        (status = 'succeeded' AND completed_at IS NOT NULL)
        OR
        (status IN ('pending', 'failed', 'cancelled'))
    )
);

CREATE INDEX refunds_booking_id
ON refunds(booking_id);

CREATE INDEX refunds_status
ON refunds(status);

CREATE INDEX events_type_received_at
ON events(type, received_at);
