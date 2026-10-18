-- Proposal, version 1: monthly use for the savings math, the proposal fields on a build sheet,
-- and the proposal terms (Clarifications and Terms & Conditions) as versioned blocks.

-- Monthly use. usage_profile is the typical monthly shape spread over the yearly use when the 12
-- months from the bill aren't entered (App\Savings::PROFILES; NULL = picked by customer type).
-- monthly_usage is JSON: 12 kWh values, January to December, typed from the bill.
ALTER TABLE presales_opportunities ADD COLUMN usage_profile TEXT;
ALTER TABLE presales_opportunities ADD COLUMN monthly_usage TEXT;

-- A build sheet's proposal. quote_number is the year and a 4-digit number (20261001), given the
-- first time the proposal is opened. proposal_date blank = today; valid_until blank = the default
-- days after the proposal date (Rates page). contact_id: the customer's contact it is addressed to.
-- customer_notes: the one place for notes the customer sees (System Summary page).
ALTER TABLE presales_designs ADD COLUMN quote_number TEXT;
ALTER TABLE presales_designs ADD COLUMN proposal_date TEXT;
ALTER TABLE presales_designs ADD COLUMN valid_until TEXT;
ALTER TABLE presales_designs ADD COLUMN contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL;
ALTER TABLE presales_designs ADD COLUMN customer_notes TEXT;
-- The production estimate the proposal leads with: detailed | pvwatts; NULL = the Rates page default.
ALTER TABLE presales_designs ADD COLUMN production_model TEXT;
-- Savings assumptions changed on this build sheet (JSON; a key left out uses the Rates page value).
ALTER TABLE presales_designs ADD COLUMN savings_inputs TEXT;
-- Incentives (JSON list: label, amount or pct of the price, timing upfront | year1).
ALTER TABLE presales_designs ADD COLUMN incentives TEXT;
-- Financing shown beside the cash price (an estimate; not in the savings projection).
ALTER TABLE presales_designs ADD COLUMN finance_down REAL;
ALTER TABLE presales_designs ADD COLUMN finance_rate REAL;      -- APR, percent
ALTER TABLE presales_designs ADD COLUMN finance_years INTEGER;
-- Production guarantee: only an admin turns it on, per build sheet.
ALTER TABLE presales_designs ADD COLUMN guarantee_on INTEGER NOT NULL DEFAULT 0;
ALTER TABLE presales_designs ADD COLUMN guarantee_by INTEGER REFERENCES users(id);
ALTER TABLE presales_designs ADD COLUMN guarantee_at TEXT;
CREATE UNIQUE INDEX idx_presales_designs_quote ON presales_designs(quote_number) WHERE quote_number IS NOT NULL;

-- Proposal terms. Each save of the terms page is a new version; the newest is current. Blocks are
-- the opening paragraph, each Clarifications line, each Terms & Conditions section, the Final
-- Authorizations and the addendum, in order. cond says when a block prints: always, inhouse,
-- solarinsure, guarantee, battery, residential, nonresidential. Body text is plain text with
-- **bold**, "- " bullet lines and {placeholders} (App\ProposalTerms).
CREATE TABLE proposal_terms_versions (
	id         INTEGER PRIMARY KEY,
	note       TEXT,
	created_by INTEGER REFERENCES users(id),
	created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE proposal_terms_blocks (
	id         INTEGER PRIMARY KEY,
	version_id INTEGER NOT NULL REFERENCES proposal_terms_versions(id) ON DELETE CASCADE,
	kind       TEXT    NOT NULL CHECK (kind IN ('intro','clarification','section','authorization','addendum')),
	sort_order INTEGER NOT NULL DEFAULT 0,
	heading    TEXT,
	body       TEXT    NOT NULL DEFAULT '',
	cond       TEXT    NOT NULL DEFAULT 'always'
);
CREATE INDEX idx_proposal_terms_blocks_version ON proposal_terms_blocks(version_id, kind, sort_order);
