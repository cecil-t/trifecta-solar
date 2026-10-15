-- Sales commissions.
--
-- The commission plan (tiers, self-generated lead bonus, referral bonus, in-house split) is kept on
-- the Rates page as settings, and every new build sheet copies it into commission_plan (JSON), so a
-- saved build sheet keeps the plan it was built with. Build sheets without a plan keep the old
-- single-rate math (rate, adjusted rate and step-down) exactly as before.
ALTER TABLE presales_designs ADD COLUMN commission_plan TEXT;

-- Catalog items (and the build sheet lines that copy them) can be priced as pass-through: added to
-- the price after markup, with no commission and no tax. pays_sales marks money that goes to the
-- salesperson (a site visit, REAP grant paperwork).
ALTER TABLE catalog_items ADD COLUMN pricing TEXT NOT NULL DEFAULT 'markup';
ALTER TABLE catalog_items ADD COLUMN pays_sales INTEGER NOT NULL DEFAULT 0;
ALTER TABLE presales_design_lines ADD COLUMN pricing TEXT NOT NULL DEFAULT 'markup';
ALTER TABLE presales_design_lines ADD COLUMN pays_sales INTEGER NOT NULL DEFAULT 0;

-- Where an opportunity came from. lead_source: self_gen | referral | trifecta | repeat.
-- lead_detail: for Trifecta leads, website | phone | marketing | other.
-- A referral names its referrer: a directory organization or just a typed name.
-- handoff_user_id: the salesperson who handed the lead to the opportunity's salesperson in house;
-- they get the in-house share of the whole payout.
ALTER TABLE presales_opportunities ADD COLUMN lead_source TEXT;
ALTER TABLE presales_opportunities ADD COLUMN lead_detail TEXT;
ALTER TABLE presales_opportunities ADD COLUMN referrer_org_id INTEGER REFERENCES organizations(id) ON DELETE SET NULL;
ALTER TABLE presales_opportunities ADD COLUMN referrer_name TEXT;
ALTER TABLE presales_opportunities ADD COLUMN handoff_user_id INTEGER REFERENCES users(id);

-- Organizations that send us customers for a referral bonus (roofing contractors and the like).
ALTER TABLE organizations ADD COLUMN referral_partner INTEGER NOT NULL DEFAULT 0;

-- One commission record per sold job. Amounts are kept on the record: computed from plan (JSON, the
-- plan the job sold under) and contract_amount when there is a plan, typed in when there is not
-- (jobs from before the tracker). contract_amount is the commissionable amount: the contract price
-- less pass-through items and extras without markup.
CREATE TABLE commissions (
	id               INTEGER PRIMARY KEY,
	project_id       INTEGER UNIQUE REFERENCES projects(id) ON DELETE SET NULL,
	opportunity_id   INTEGER REFERENCES presales_opportunities(id) ON DELETE SET NULL,
	design_id        INTEGER REFERENCES presales_designs(id) ON DELETE SET NULL,
	job_name         TEXT    NOT NULL,
	signed_on        TEXT,                -- YYYY-MM-DD contract signed
	salesperson_id   INTEGER REFERENCES users(id),
	handoff_user_id  INTEGER REFERENCES users(id),
	handoff_share    REAL    NOT NULL DEFAULT 0,    -- share of the whole payout to handoff_user_id (0.2 = 20%)
	lead_source      TEXT,
	referrer_name    TEXT,
	contract_amount  REAL    NOT NULL DEFAULT 0,
	plan             TEXT,
	commission       REAL    NOT NULL DEFAULT 0,
	self_gen         REAL    NOT NULL DEFAULT 0,
	payouts          REAL    NOT NULL DEFAULT 0,    -- site visit, REAP paperwork and other pays-the-salesperson items
	payouts_note     TEXT,
	referral_bonus   REAL    NOT NULL DEFAULT 0,    -- owed to the outside referrer, not the salesperson
	referral_paid_on TEXT,
	referral_w9      INTEGER NOT NULL DEFAULT 0,
	status           TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','cancelled')),
	notes            TEXT,
	created_by       INTEGER REFERENCES users(id),
	created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
	updated_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_commissions_salesperson ON commissions(salesperson_id, signed_on);
CREATE INDEX idx_commissions_handoff ON commissions(handoff_user_id);

-- Payments and adjustments per person paid. An adjustment (with a reason) changes what is owed;
-- a payment pays it down. task_id is set when the payment came from closing the project's
-- "pay commission" step, so reopening the step can take it back.
CREATE TABLE commission_entries (
	id            INTEGER PRIMARY KEY,
	commission_id INTEGER NOT NULL REFERENCES commissions(id) ON DELETE CASCADE,
	kind          TEXT    NOT NULL CHECK (kind IN ('payment','adjustment')),
	user_id       INTEGER REFERENCES users(id),
	amount        REAL    NOT NULL,
	entry_date    TEXT    NOT NULL,
	note          TEXT,
	task_id       INTEGER REFERENCES project_tasks(id) ON DELETE SET NULL,
	created_by    INTEGER REFERENCES users(id),
	created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_commission_entries ON commission_entries(commission_id, entry_date);

-- A task can do something when it is closed. 'pay_commission' records the commission as paid.
ALTER TABLE task_templates ADD COLUMN action TEXT;
ALTER TABLE project_tasks ADD COLUMN action TEXT;

-- The closeout step that reminds us to pay the salesperson. New projects get it from the template;
-- existing projects can add it from the template on the project page.
INSERT INTO task_templates (parent_id, name, phase, sort_order, default_needed, applies_when, action)
VALUES (NULL, 'Pay sales commission', 'closeout', 70, 1, 'all', 'pay_commission');
