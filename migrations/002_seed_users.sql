-- Initial staff accounts. Passwords start empty: the first admin sets theirs at /setup,
-- then sets temporary passwords for everyone else from the Users screen.
INSERT INTO users (email, name, initials, is_admin) VALUES
    ('person@example.com',    'Greg Hassler',       'GH',  1),
    ('person@example.com',    'Staff Member',        'EB',  1),
    ('person@example.com',  'Staff Member',    'SK',  0),
    ('person@example.com', 'Staff Member',     'XX', 0),
    ('person@example.com', 'Staff Member', 'BB',  0);
