-- Upgrade databases created with the restricted status CHECK in the earlier 001.sql.
-- The CLI runner temporarily disables FK enforcement before BEGIN, validates every FK before commit,
-- and restores enforcement afterwards. IDs and all existing ticket/audit/outbox links are preserved.
CREATE TABLE bookings_new (
    id INTEGER PRIMARY KEY,

    reference TEXT UNIQUE NOT NULL,

    mode TEXT NOT NULL DEFAULT 'test'
        CHECK(mode IN ('test', 'live')),

    token TEXT UNIQUE NOT NULL,

    date TEXT NOT NULL,
    type_name TEXT NOT NULL,

    quantity INTEGER NOT NULL
        CHECK(quantity > 0),

    unit_price INTEGER NOT NULL
        CHECK(unit_price >= 0),

    total INTEGER NOT NULL,

    name TEXT NOT NULL,
    email TEXT NOT NULL,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN (
            'creating',
            'pending',
            'paid',
            'complimentary',
            'failed',
            'partially_refunded',
            'refund_required',
            'cancelled',
            'expired',
            'refunded'
        )),

    expires_at INTEGER NOT NULL,

    session_id TEXT UNIQUE,
    payment_intent TEXT UNIQUE,

    created_at INTEGER NOT NULL,
    paid_at INTEGER,

    refund_amount INTEGER NOT NULL DEFAULT 0,

    note TEXT NOT NULL DEFAULT '',

    created_by INTEGER REFERENCES users(id),

    CHECK(
        total = quantity * unit_price
    ),

    CHECK(
        (status IN ('paid', 'partially_refunded', 'refunded', 'refund_required') AND paid_at IS NOT NULL)
        OR
        (status IN ('creating', 'pending', 'complimentary', 'failed', 'cancelled', 'expired') AND paid_at IS NULL)
    )
);

INSERT INTO bookings_new (id, reference, mode, token, date, type_name, quantity, unit_price, total, name, email, status, expires_at, session_id, payment_intent, created_at, paid_at, refund_amount, note, created_by) SELECT id, reference, mode, token, date, type_name, quantity, unit_price, total, name, email, status, expires_at, session_id, payment_intent, created_at, paid_at, refund_amount, note, created_by FROM bookings;
DROP TABLE bookings;
ALTER TABLE bookings_new RENAME TO bookings;
CREATE INDEX bookings_status ON bookings(status);
CREATE INDEX bookings_date_status ON bookings(date,status);
