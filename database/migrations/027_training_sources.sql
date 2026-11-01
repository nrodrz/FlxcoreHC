-- Authoritative source links per training article, so agents can verify the
-- guidance against CMS, certification bodies, carriers, and the state.
CREATE TABLE IF NOT EXISTS training_sources (
  id           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  article_code VARCHAR(60)  NOT NULL,
  label        VARCHAR(160) NOT NULL,
  url          VARCHAR(400) NOT NULL,
  authority    VARCHAR(20)  NULL,      -- cms | federal | state | carrier | industry
  sort         INT          NOT NULL DEFAULT 100,
  UNIQUE KEY uq_training_source (article_code, url),
  INDEX idx_ts_article (article_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
