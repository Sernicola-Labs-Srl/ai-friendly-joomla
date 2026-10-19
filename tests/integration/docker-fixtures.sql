INSERT INTO joom_content (`title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `modified`, `images`, `urls`, `attribs`, `metadesc`, `access`, `metadata`, `language`)
VALUES
('Public &amp; Useful', 'public-useful', '<h2>Public heading</h2><p>Readable public text.</p>', '', 1, 2, NOW(), (SELECT id FROM joom_users WHERE username='admin' LIMIT 1), NOW(), '{}', '{}', '{}', 'Public excerpt', 1, '{}', '*'),
('Second public page', 'second-public', '<p>Second public text.</p>', '', 1, 2, NOW(), (SELECT id FROM joom_users WHERE username='admin' LIMIT 1), NOW(), '{}', '{}', '{}', 'Second excerpt', 1, '{}', '*'),
('Private article', 'private-article', '<p>Private secret fixture.</p>', '', 1, 2, NOW(), (SELECT id FROM joom_users WHERE username='admin' LIMIT 1), NOW(), '{}', '{}', '{}', 'Private excerpt', 3, '{}', '*'),
('Future article', 'future-article', '<p>Future fixture.</p>', '', 1, 2, NOW(), (SELECT id FROM joom_users WHERE username='admin' LIMIT 1), NOW(), '{}', '{}', '{}', 'Future excerpt', 1, '{}', '*');
UPDATE joom_content SET publish_up='2099-01-01 00:00:00' WHERE alias='future-article';
