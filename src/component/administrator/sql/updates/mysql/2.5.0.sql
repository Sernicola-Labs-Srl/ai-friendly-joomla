CREATE TABLE IF NOT EXISTS `#__aifriendly_jobs` (
  `id` char(36) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'indexing',
  `trigger_name` varchar(40) NOT NULL DEFAULT 'manual',
  `force_run` tinyint unsigned NOT NULL DEFAULT 0,
  `total` int unsigned NOT NULL DEFAULT 0,
  `cursor` int unsigned NOT NULL DEFAULT 0,
  `dirty` tinyint unsigned NOT NULL DEFAULT 0,
  `stats` mediumtext NOT NULL,
  `error` text NOT NULL,
  `corpus_path` varchar(1024) NOT NULL DEFAULT '',
  `started` datetime NOT NULL,
  `finished` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aifriendly_jobs_started` (`started`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__aifriendly_job_items` (
  `job_id` char(36) NOT NULL,
  `seq` int unsigned NOT NULL,
  `content_key` varchar(255) NOT NULL,
  PRIMARY KEY (`job_id`, `seq`),
  UNIQUE KEY `idx_aifriendly_job_key` (`job_id`, `content_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
