CREATE TABLE IF NOT EXISTS `#__aifriendly_content_state` (
  `content_key` varchar(255) NOT NULL,
  `excluded` tinyint unsigned NOT NULL DEFAULT 0,
  `checksum` char(64) NOT NULL DEFAULT '',
  `version_path` varchar(1024) NOT NULL DEFAULT '',
  `last_generated` datetime NULL DEFAULT NULL,
  `last_error` text NOT NULL,
  PRIMARY KEY (`content_key`),
  KEY `idx_aifriendly_excluded` (`excluded`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__aifriendly_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reason` varchar(80) NOT NULL DEFAULT 'manual',
  `content` mediumtext NOT NULL,
  `content_hash` char(64) NOT NULL,
  `line_count` int unsigned NOT NULL DEFAULT 0,
  `token_estimate` int unsigned NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aifriendly_snapshot_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__aifriendly_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(80) NOT NULL,
  `level` varchar(20) NOT NULL DEFAULT 'info',
  `payload` mediumtext NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_aifriendly_event_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
