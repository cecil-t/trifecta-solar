-- A build sheet can take a different deposit than its tier (more up front, or a material deposit
-- combined with the first payment). The override counts only with a reason; deposit keeps the tier.
ALTER TABLE presales_designs ADD COLUMN deposit_override REAL;
ALTER TABLE presales_designs ADD COLUMN deposit_reason TEXT;
