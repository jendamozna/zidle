-- Customers can put a name on each seat ticket.
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS ticket_names TEXT NOT NULL DEFAULT '{}' COMMENT 'JSON {seat: name on the ticket} set by the customer; others show the reservation name' AFTER free_seats;
