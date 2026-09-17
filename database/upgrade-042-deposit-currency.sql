-- MarvySocials existing-install upgrade: migration 042
-- ==================================================
--
-- Use this file ONLY on an existing MarvySocials database that predates
-- migration 042. For a new installation, import marvysocials.sql instead.
--
-- cPanel / phpMyAdmin:
--   1. Export a backup of the current database.
--   2. Select that database, open Import, and import this file.
--   3. Re-open /deploy-verify.php. The four payment_transactions columns
--      should pass, then delete deploy-verify.php.
--
-- Safe to run more than once. Each column is added only when absent, existing
-- deposits are backfilled without changing their charge amount/currency, and
-- the migration row is advanced only when it is behind version 42.

SET @marvy_database := DATABASE();

SET @marvy_sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @marvy_database
        AND TABLE_NAME = 'payment_transactions'
        AND COLUMN_NAME = 'base_currency') = 0,
    'ALTER TABLE `payment_transactions` ADD COLUMN `base_currency` CHAR(3) NULL COMMENT ''accounting currency the wallet is credited in''',
    'SELECT 1'
);
PREPARE marvy_stmt FROM @marvy_sql;
EXECUTE marvy_stmt;
DEALLOCATE PREPARE marvy_stmt;

SET @marvy_sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @marvy_database
        AND TABLE_NAME = 'payment_transactions'
        AND COLUMN_NAME = 'base_amount') = 0,
    'ALTER TABLE `payment_transactions` ADD COLUMN `base_amount` DECIMAL(20,8) NULL COMMENT ''amount in base currency at fx_rate''',
    'SELECT 1'
);
PREPARE marvy_stmt FROM @marvy_sql;
EXECUTE marvy_stmt;
DEALLOCATE PREPARE marvy_stmt;

SET @marvy_sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @marvy_database
        AND TABLE_NAME = 'payment_transactions'
        AND COLUMN_NAME = 'credited_base_amount') = 0,
    'ALTER TABLE `payment_transactions` ADD COLUMN `credited_base_amount` DECIMAL(20,8) NULL COMMENT ''what the wallet is credited, in base currency''',
    'SELECT 1'
);
PREPARE marvy_stmt FROM @marvy_sql;
EXECUTE marvy_stmt;
DEALLOCATE PREPARE marvy_stmt;

SET @marvy_sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @marvy_database
        AND TABLE_NAME = 'payment_transactions'
        AND COLUMN_NAME = 'fx_rate') = 0,
    'ALTER TABLE `payment_transactions` ADD COLUMN `fx_rate` DECIMAL(20,8) NULL COMMENT ''units of the charge currency per 1 unit of base, pinned at initiation''',
    'SELECT 1'
);
PREPARE marvy_stmt FROM @marvy_sql;
EXECUTE marvy_stmt;
DEALLOCATE PREPARE marvy_stmt;

-- Deposits created before migration 042 were charged in the accounting/base
-- currency, so their charge and settlement legs are exactly the same. One
-- deployment window is different: PHP files may have been uploaded before this
-- SQL, and those new deposits carry their correct base leg in
-- metadata.settlement. Prefer that JSON when present so importing the migration
-- later does not relabel an NGN/default-currency payment as a USD/base one.
UPDATE `payment_transactions`
   SET `base_currency` = COALESCE(
           NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.settlement.base_currency')), 'null'), ''),
           `base_currency`,
           `currency`
       ),
       `base_amount` = COALESCE(
           CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.settlement.base_amount')), 'null'), '') AS DECIMAL(20,8)),
           `base_amount`,
           `amount`
       ),
       `credited_base_amount` = COALESCE(
           CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.settlement.credited_base_amount')), 'null'), '') AS DECIMAL(20,8)),
           `credited_base_amount`,
           `credited_amount`,
           `amount`
       ),
       `fx_rate` = COALESCE(
           CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.settlement.fx_rate')), 'null'), '') AS DECIMAL(20,8)),
           `fx_rate`,
           1.00000000
       );

-- CI3 stores one current migration version row. Preserve a newer version if a
-- later release has already been applied, and recover safely if the table is
-- present but its row is unexpectedly absent.
UPDATE `migrations` SET `version` = 42 WHERE `version` < 42;
INSERT INTO `migrations` (`version`)
SELECT 42 WHERE NOT EXISTS (SELECT 1 FROM `migrations`);

SET @marvy_sql := NULL;
SET @marvy_database := NULL;
