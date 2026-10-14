-- Staff phone number, shown on the salesperson card of proposals and wherever staff contact
-- details are listed. Optional; formatted 717-555-0100 when it is a plain 10-digit US number.
ALTER TABLE users ADD COLUMN phone TEXT;
