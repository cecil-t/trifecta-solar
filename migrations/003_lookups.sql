-- Simple lookup lists (no contacts attached). Admins can add to utilities and funding sources later.

CREATE TABLE states (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL
);
INSERT INTO states (code, name) VALUES ('PA','Pennsylvania'), ('MD','Maryland'), ('DE','Delaware');

-- County is reference data used for geography/reporting and to disambiguate municipality names
-- (Penn Township exists in several counties). Maryland's 'Baltimore City' is a county-equivalent.
CREATE TABLE counties (
    id         INTEGER PRIMARY KEY,
    state_code TEXT NOT NULL REFERENCES states(code),
    name       TEXT NOT NULL,
    UNIQUE (state_code, name)
);
INSERT INTO counties (state_code, name) VALUES
    ('PA', 'Adams'),
    ('PA', 'Allegheny'),
    ('PA', 'Armstrong'),
    ('PA', 'Beaver'),
    ('PA', 'Bedford'),
    ('PA', 'Berks'),
    ('PA', 'Blair'),
    ('PA', 'Bradford'),
    ('PA', 'Bucks'),
    ('PA', 'Butler'),
    ('PA', 'Cambria'),
    ('PA', 'Cameron'),
    ('PA', 'Carbon'),
    ('PA', 'Centre'),
    ('PA', 'Chester'),
    ('PA', 'Clarion'),
    ('PA', 'Clearfield'),
    ('PA', 'Clinton'),
    ('PA', 'Columbia'),
    ('PA', 'Crawford'),
    ('PA', 'Cumberland'),
    ('PA', 'Dauphin'),
    ('PA', 'Delaware'),
    ('PA', 'Elk'),
    ('PA', 'Erie'),
    ('PA', 'Fayette'),
    ('PA', 'Forest'),
    ('PA', 'Franklin'),
    ('PA', 'Fulton'),
    ('PA', 'Greene'),
    ('PA', 'Huntingdon'),
    ('PA', 'Indiana'),
    ('PA', 'Jefferson'),
    ('PA', 'Juniata'),
    ('PA', 'Lackawanna'),
    ('PA', 'Lancaster'),
    ('PA', 'Lawrence'),
    ('PA', 'Lebanon'),
    ('PA', 'Lehigh'),
    ('PA', 'Luzerne'),
    ('PA', 'Lycoming'),
    ('PA', 'McKean'),
    ('PA', 'Mercer'),
    ('PA', 'Mifflin'),
    ('PA', 'Monroe'),
    ('PA', 'Montgomery'),
    ('PA', 'Montour'),
    ('PA', 'Northampton'),
    ('PA', 'Northumberland'),
    ('PA', 'Perry'),
    ('PA', 'Philadelphia'),
    ('PA', 'Pike'),
    ('PA', 'Potter'),
    ('PA', 'Schuylkill'),
    ('PA', 'Snyder'),
    ('PA', 'Somerset'),
    ('PA', 'Sullivan'),
    ('PA', 'Susquehanna'),
    ('PA', 'Tioga'),
    ('PA', 'Union'),
    ('PA', 'Venango'),
    ('PA', 'Warren'),
    ('PA', 'Washington'),
    ('PA', 'Wayne'),
    ('PA', 'Westmoreland'),
    ('PA', 'Wyoming'),
    ('PA', 'York'),
    ('MD', 'Allegany'),
    ('MD', 'Anne Arundel'),
    ('MD', 'Baltimore'),
    ('MD', 'Baltimore City'),
    ('MD', 'Calvert'),
    ('MD', 'Caroline'),
    ('MD', 'Carroll'),
    ('MD', 'Cecil'),
    ('MD', 'Charles'),
    ('MD', 'Dorchester'),
    ('MD', 'Frederick'),
    ('MD', 'Garrett'),
    ('MD', 'Harford'),
    ('MD', 'Howard'),
    ('MD', 'Kent'),
    ('MD', 'Montgomery'),
    ('MD', 'Prince George''s'),
    ('MD', 'Queen Anne''s'),
    ('MD', 'St. Mary''s'),
    ('MD', 'Somerset'),
    ('MD', 'Talbot'),
    ('MD', 'Washington'),
    ('MD', 'Wicomico'),
    ('MD', 'Worcester'),
    ('DE', 'Kent'),
    ('DE', 'New Castle'),
    ('DE', 'Sussex');

CREATE TABLE utilities (
    id         INTEGER PRIMARY KEY,
    name       TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    is_active  INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0
);
INSERT INTO utilities (name, sort_order) VALUES
    ('PPL', 10), ('Met-Ed', 20), ('Penelec', 30), ('West Penn Power', 40), ('PECO', 50);

CREATE TABLE funding_sources (
    id         INTEGER PRIMARY KEY,
    name       TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    is_active  INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0
);
INSERT INTO funding_sources (name, sort_order) VALUES
    ('Cash', 10), ('REAP Grant', 20), ('Bank Financing (lender unknown)', 30),
    ('Atmos', 40), ('Climatize', 50), ('Orrstown Bank', 60), ('Other', 90);
