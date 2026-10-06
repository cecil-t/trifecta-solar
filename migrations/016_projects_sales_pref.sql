-- Salesperson filter each user last picked on the Projects list, restored when they come back.
-- NULL = All salespeople.
ALTER TABLE users ADD COLUMN projects_sales_id INTEGER;
