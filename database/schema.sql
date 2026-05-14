CREATE TABLE fb_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_name VARCHAR(64) NOT NULL,
  event_id VARCHAR(128) NULL,
  event_time DATETIME NOT NULL,
  page_url TEXT NULL,
  referrer TEXT NULL,
  user_agent TEXT NULL,
  fbp VARCHAR(255) NULL,
  fbc VARCHAR(255) NULL,
  value DECIMAL(12,2) NULL,
  currency VARCHAR(8) NULL,
  content_ids JSON NULL,
  raw_payload JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_event_id (event_id),
  KEY idx_event_name (event_name),
  KEY idx_event_time (event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meta_capi_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fb_event_id BIGINT UNSIGNED NOT NULL,
  event_name VARCHAR(64) NOT NULL,
  event_id VARCHAR(128) NULL,
  request_url TEXT NOT NULL,
  request_payload JSON NOT NULL,
  response_http_code SMALLINT UNSIGNED NULL,
  response_body JSON NULL,
  response_text MEDIUMTEXT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fb_event_id (fb_event_id),
  KEY idx_event_name (event_name),
  KEY idx_success_created_at (success, created_at),
  CONSTRAINT fk_meta_capi_logs_fb_event
    FOREIGN KEY (fb_event_id) REFERENCES fb_events(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
