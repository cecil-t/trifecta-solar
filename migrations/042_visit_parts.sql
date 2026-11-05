-- Parts cost per service visit, for warranty and billable work. Cents; NULL = none entered.
-- Manufacturer-supplied parts (an RMA replacement) are entered as 0 or left blank.
ALTER TABLE service_visits ADD COLUMN parts_cents INTEGER;
ALTER TABLE service_visits ADD COLUMN parts_note TEXT;
