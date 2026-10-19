INSERT INTO joom_scheduler_tasks
    (title, type, execution_rules, cron_rules, state, params, created, created_by, next_execution)
VALUES
    ('AI Friendly matrix', 'aifriendly.regenerate',
     '{"rule-type":"interval-minutes","interval-minutes":"1"}',
     '{"type":"interval","exp":"PT1M"}',
     1, '{"batch_size":1}', NOW(), (SELECT id FROM joom_users WHERE username='admin' LIMIT 1), NOW());
