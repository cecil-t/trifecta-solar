-- Directory: customers and third parties (organizations), the people at them (contacts),
-- and municipalities (the ordinance owner for every project).

-- type:
--   customer   - anyone can add; one customer can have many projects
--   agency     - code / plan review / inspection agencies, COGs, county offices (admin-managed)
--   designer   - planset designers, e.g. Unique Solar Design, EnergyScape (admin-managed)
--   contractor - sitework or installation subcontractors (admin-managed)
--   other      - anything else (admin-managed)
CREATE TABLE organizations (
    id          INTEGER PRIMARY KEY,
    type        TEXT    NOT NULL CHECK (type IN ('customer','agency','designer','contractor','other')),
    name        TEXT    NOT NULL,
    phone       TEXT,
    email       TEXT,
    street      TEXT,
    city        TEXT,
    state       TEXT,
    zip         TEXT,
    notes       TEXT,
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_by  INTEGER REFERENCES users(id),
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    updated_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_organizations_type_name ON organizations(type, name);

-- Municipality = township / borough / city. Unique per county (Penn Township exists in several).
-- name_key is a normalized form (lowercase, Twp->township, Boro->borough, punctuation stripped)
-- so "Penn Twp" and "Penn Township" in the same county are treated as the same place.
-- The *_mode / *_org_id pairs record who usually performs each review for this municipality;
-- new projects copy these as defaults and can override them.
CREATE TABLE municipalities (
    id                 INTEGER PRIMARY KEY,
    name               TEXT    NOT NULL,
    name_key           TEXT    NOT NULL,
    county_id          INTEGER NOT NULL REFERENCES counties(id),
    zoning_mode        TEXT    CHECK (zoning_mode IN ('self','agency')),
    zoning_org_id      INTEGER REFERENCES organizations(id),
    plan_review_mode   TEXT    CHECK (plan_review_mode IN ('self','agency')),
    plan_review_org_id INTEGER REFERENCES organizations(id),
    inspection_mode    TEXT    CHECK (inspection_mode IN ('self','agency')),
    inspection_org_id  INTEGER REFERENCES organizations(id),
    notes              TEXT,
    created_by         INTEGER REFERENCES users(id),
    created_at         TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    updated_at         TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    UNIQUE (name_key, county_id)
);

-- People. A contact belongs to an organization or a municipality (e.g. the zoning officer).
CREATE TABLE contacts (
    id          INTEGER PRIMARY KEY,
    owner_type  TEXT    NOT NULL CHECK (owner_type IN ('organization','municipality')),
    owner_id    INTEGER NOT NULL,
    name        TEXT    NOT NULL,
    role        TEXT,
    phone       TEXT,
    email       TEXT,
    notes       TEXT,
    is_primary  INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
    updated_at  TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE INDEX idx_contacts_owner ON contacts(owner_type, owner_id);

INSERT INTO organizations (type, name) VALUES
    ('designer', 'Unique Solar Design'),
    ('designer', 'EnergyScape');
