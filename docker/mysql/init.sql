-- Runs once, on first boot of an empty MySQL data volume.
-- docker-compose.yml mounts this at /docker-entrypoint-initdb.d/init.sql and
-- the container refused to start while the file was missing.
--
-- The MYSQL_DATABASE/MYSQL_USER env vars already create the main database and
-- grant on it. This adds the test database CI and local `phpunit` expect, and
-- grants the same user access to it. Schema itself comes from migrations
-- (`php index.php migrate`), never from here.

CREATE DATABASE IF NOT EXISTS `windels_panel`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS `windels_panel_test`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `windels_panel`.*      TO 'windels'@'%';
GRANT ALL PRIVILEGES ON `windels_panel_test`.* TO 'windels'@'%';
FLUSH PRIVILEGES;
