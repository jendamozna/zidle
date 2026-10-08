-- Refunds now always go back to the account the payment came from; the
-- customer no longer enters a refund account.
ALTER TABLE reservations DROP COLUMN IF EXISTS refund_account;
