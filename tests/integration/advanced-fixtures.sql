INSERT INTO joom_fields
    (context, title, name, description, state, params, fieldparams, language, created_time, modified_time, access)
VALUES
    ('com_content.article', 'Public detail', 'public-detail', '', 1, '{}', '{}', '*', NOW(), NOW(), 1),
    ('com_content.article', 'Restricted detail', 'restricted-detail', '', 1, '{}', '{}', '*', NOW(), NOW(), 3);

INSERT INTO joom_fields_values (field_id, item_id, value)
SELECT f.id, CAST(a.id AS CHAR),
       CASE WHEN f.name = 'public-detail' THEN 'Visible custom field fixture'
            ELSE 'PRIVATE CUSTOM FIELD SECRET' END
FROM joom_fields AS f
CROSS JOIN joom_content AS a
WHERE a.alias = 'public-useful'
  AND f.name IN ('public-detail', 'restricted-detail');

INSERT INTO joom_content
    (`title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `modified`, `images`, `urls`, `attribs`, `metadesc`, `access`, `metadata`, `language`)
VALUES
    ('English language page', 'language-fixture', '<p>English language text.</p>', '', 1, 2, NOW(), (SELECT id FROM joom_users WHERE username='admin' LIMIT 1), NOW(), '{}', '{}', '{}', '', 1, '{}', 'en-GB'),
    ('Pagina italiana', 'language-fixture', '<p>Testo in italiano.</p>', '', 1, 2, NOW(), (SELECT id FROM joom_users WHERE username='admin' LIMIT 1), NOW(), '{}', '{}', '{}', '', 1, '{}', 'it-IT');
