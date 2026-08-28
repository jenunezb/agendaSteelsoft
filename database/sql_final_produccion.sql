START TRANSACTION;

CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(170) NOT NULL UNIQUE,
  account_type ENUM('business', 'independent') NOT NULL DEFAULT 'business',
  status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
  working_hour_start TINYINT UNSIGNED NOT NULL DEFAULT 8,
  working_hour_end TINYINT UNSIGNED NOT NULL DEFAULT 18,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  professional_limit SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS company_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NULL,
  plan_code VARCHAR(50) NOT NULL,
  plan_name VARCHAR(100) NOT NULL,
  status ENUM('active', 'trial', 'suspended', 'cancelled') NOT NULL DEFAULT 'active',
  monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  professional_limit SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  started_at DATE NOT NULL,
  renewal_day TINYINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO plans (code, name, monthly_price, professional_limit, active)
VALUES
  ('free', 'Plan gratuito', 0, 4, 1),
  ('basic', 'Basico empresarial', 150000, 4, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  monthly_price = VALUES(monthly_price),
  professional_limit = VALUES(professional_limit),
  active = VALUES(active);

DELIMITER $$

DROP PROCEDURE IF EXISTS ensure_column$$
CREATE PROCEDURE ensure_column(
  IN p_table VARCHAR(128),
  IN p_column VARCHAR(128),
  IN p_ddl TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column
  ) THEN
    SET @sql = p_ddl;
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DROP PROCEDURE IF EXISTS drop_column_if_exists$$
CREATE PROCEDURE drop_column_if_exists(
  IN p_table VARCHAR(128),
  IN p_column VARCHAR(128)
)
BEGIN
  IF EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` DROP COLUMN `', p_column, '`');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DROP PROCEDURE IF EXISTS ensure_index_name$$
CREATE PROCEDURE ensure_index_name(
  IN p_table VARCHAR(128),
  IN p_index VARCHAR(128),
  IN p_ddl TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND INDEX_NAME = p_index
  ) THEN
    SET @sql = p_ddl;
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DELIMITER ;

CALL ensure_column('companies', 'account_type', 'ALTER TABLE companies ADD COLUMN account_type ENUM(''business'', ''independent'') NOT NULL DEFAULT ''business'' AFTER slug');
CALL ensure_column('companies', 'status', 'ALTER TABLE companies ADD COLUMN status ENUM(''active'', ''inactive'', ''suspended'') NOT NULL DEFAULT ''active'' AFTER account_type');
CALL ensure_column('companies', 'working_hour_start', 'ALTER TABLE companies ADD COLUMN working_hour_start TINYINT UNSIGNED NOT NULL DEFAULT 8 AFTER status');
CALL ensure_column('companies', 'working_hour_end', 'ALTER TABLE companies ADD COLUMN working_hour_end TINYINT UNSIGNED NOT NULL DEFAULT 18 AFTER working_hour_start');

CALL ensure_column('users', 'email', 'ALTER TABLE users ADD COLUMN email VARCHAR(150) NOT NULL DEFAULT '''' AFTER username');
CALL ensure_column('users', 'company_id', 'ALTER TABLE users ADD COLUMN company_id INT UNSIGNED NULL AFTER password_hash');
CALL ensure_column('users', 'company_role', 'ALTER TABLE users ADD COLUMN company_role VARCHAR(40) NOT NULL DEFAULT ''owner'' AFTER company_id');
CALL ensure_column('users', 'professional_id', 'ALTER TABLE users ADD COLUMN professional_id INT UNSIGNED NULL AFTER company_role');
CALL ensure_column('users', 'is_system_admin', 'ALTER TABLE users ADD COLUMN is_system_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER professional_id');
CALL ensure_column('users', 'email_verified_at', 'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER is_system_admin');
CALL ensure_column('users', 'verification_token_hash', 'ALTER TABLE users ADD COLUMN verification_token_hash VARCHAR(255) NULL AFTER email_verified_at');
CALL ensure_column('users', 'verification_token_expires_at', 'ALTER TABLE users ADD COLUMN verification_token_expires_at DATETIME NULL AFTER verification_token_hash');
CALL ensure_column('users', 'verification_sent_at', 'ALTER TABLE users ADD COLUMN verification_sent_at DATETIME NULL AFTER verification_token_expires_at');
CALL ensure_column('users', 'profile_public', 'ALTER TABLE users ADD COLUMN profile_public TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash');
CALL ensure_column('users', 'whatsapp_number', 'ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(20) NOT NULL DEFAULT '''' AFTER profile_public');
CALL ensure_column('users', 'whatsapp_notifications_enabled', 'ALTER TABLE users ADD COLUMN whatsapp_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_number');
CALL drop_column_if_exists('users', 'telegram_notifications_enabled');
CALL drop_column_if_exists('users', 'telegram_chat_id');

CALL ensure_column('professionals', 'linked_user_id', 'ALTER TABLE professionals ADD COLUMN linked_user_id INT UNSIGNED NULL AFTER phone');
CREATE TABLE IF NOT EXISTS service_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  description TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CALL ensure_column('services', 'price', 'ALTER TABLE services ADD COLUMN price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER duration_minutes');
CREATE TABLE IF NOT EXISTS professional_roles (
  professional_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (professional_id, role_id)
);

CALL ensure_column('activities', 'user_id', 'ALTER TABLE activities ADD COLUMN user_id INT UNSIGNED NULL AFTER id');
CALL ensure_column('activities', 'company_id', 'ALTER TABLE activities ADD COLUMN company_id INT UNSIGNED NULL AFTER user_id');
CALL ensure_column('activities', 'professional_id', 'ALTER TABLE activities ADD COLUMN professional_id INT UNSIGNED NULL AFTER company_id');
CALL ensure_column('activities', 'is_public', 'ALTER TABLE activities ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER assignee');
CALL ensure_column('activities', 'reminder_minutes', 'ALTER TABLE activities ADD COLUMN reminder_minutes SMALLINT UNSIGNED NULL AFTER description');
CALL ensure_column('activities', 'reminder_sent_at', 'ALTER TABLE activities ADD COLUMN reminder_sent_at DATETIME NULL AFTER reminder_minutes');
CALL ensure_column('activities', 'recurrence_type', 'ALTER TABLE activities ADD COLUMN recurrence_type VARCHAR(20) NOT NULL DEFAULT "none" AFTER reminder_sent_at');
CALL ensure_column('activities', 'reminder_sent_for_date', 'ALTER TABLE activities ADD COLUMN reminder_sent_for_date DATE NULL AFTER recurrence_type');
CALL ensure_column('activities', 'booking_status', 'ALTER TABLE activities ADD COLUMN booking_status VARCHAR(20) NULL AFTER reminder_sent_at');
CALL ensure_column('activities', 'booking_customer_name', 'ALTER TABLE activities ADD COLUMN booking_customer_name VARCHAR(120) NOT NULL DEFAULT '''' AFTER booking_status');
CALL ensure_column('activities', 'booking_customer_phone', 'ALTER TABLE activities ADD COLUMN booking_customer_phone VARCHAR(30) NOT NULL DEFAULT '''' AFTER booking_customer_name');
CALL ensure_column('activities', 'booking_confirmation_token', 'ALTER TABLE activities ADD COLUMN booking_confirmation_token CHAR(64) NULL AFTER booking_customer_phone');
CALL ensure_column('activities', 'booking_confirmation_sent_at', 'ALTER TABLE activities ADD COLUMN booking_confirmation_sent_at DATETIME NULL AFTER booking_confirmation_token');
CALL ensure_column('activities', 'booking_responded_at', 'ALTER TABLE activities ADD COLUMN booking_responded_at DATETIME NULL AFTER booking_confirmation_sent_at');

CREATE TABLE IF NOT EXISTS whatsapp_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_id INT UNSIGNED NOT NULL,
  notification_type VARCHAR(40) NOT NULL,
  recipient VARCHAR(30) NOT NULL,
  provider VARCHAR(30) NOT NULL,
  message_sid VARCHAR(64) NOT NULL DEFAULT '',
  delivery_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_whatsapp_activity_notification (activity_id, notification_type, recipient)
);

CREATE TABLE IF NOT EXISTS personal_reminders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  due_date DATE NULL,
  priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  completed TINYINT(1) NOT NULL DEFAULT 0,
  whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  remind_at DATETIME NULL,
  reminder_sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CALL ensure_column('general_pendings', 'user_id', 'ALTER TABLE general_pendings ADD COLUMN user_id INT UNSIGNED NULL AFTER id');
CALL ensure_column('general_pendings', 'company_id', 'ALTER TABLE general_pendings ADD COLUMN company_id INT UNSIGNED NULL AFTER user_id');
CALL ensure_column('general_pendings', 'professional_id', 'ALTER TABLE general_pendings ADD COLUMN professional_id INT UNSIGNED NULL AFTER company_id');

CALL ensure_column('financial_entries', 'user_id', 'ALTER TABLE financial_entries ADD COLUMN user_id INT UNSIGNED NULL AFTER id');
CALL ensure_column('financial_entries', 'company_id', 'ALTER TABLE financial_entries ADD COLUMN company_id INT UNSIGNED NULL AFTER user_id');
CALL ensure_column('financial_entries', 'professional_id', 'ALTER TABLE financial_entries ADD COLUMN professional_id INT UNSIGNED NULL AFTER company_id');
CALL ensure_column('financial_entries', 'participation_percentage', 'ALTER TABLE financial_entries ADD COLUMN participation_percentage DECIMAL(5,2) NULL AFTER amount');

UPDATE users
SET profile_public = 1
WHERE profile_public IS NULL OR profile_public = 0;

UPDATE users
SET email = CONCAT(COALESCE(NULLIF(username, ''), 'usuario'), '+', id, '@migracion.local')
WHERE email IS NULL OR TRIM(email) = '';

CALL ensure_index_name('users', 'idx_users_email_unique', 'CREATE UNIQUE INDEX idx_users_email_unique ON users (email)');
CALL ensure_index_name('users', 'idx_users_company', 'CREATE INDEX idx_users_company ON users (company_id)');
CALL ensure_index_name('users', 'idx_users_professional', 'CREATE INDEX idx_users_professional ON users (professional_id)');
CALL ensure_index_name('professionals', 'idx_professionals_company_active', 'CREATE INDEX idx_professionals_company_active ON professionals (company_id, active, name)');
CALL ensure_index_name('professionals', 'idx_professionals_linked_user', 'CREATE INDEX idx_professionals_linked_user ON professionals (linked_user_id)');
CALL ensure_index_name('service_roles', 'idx_service_roles_company_active', 'CREATE INDEX idx_service_roles_company_active ON service_roles (company_id, active, name)');
CALL ensure_index_name('services', 'idx_services_company_active', 'CREATE INDEX idx_services_company_active ON services (company_id, active, name)');
CALL ensure_index_name('services', 'idx_services_role', 'CREATE INDEX idx_services_role ON services (role_id)');
CALL ensure_index_name('professional_roles', 'idx_professional_roles_role', 'CREATE INDEX idx_professional_roles_role ON professional_roles (role_id)');
CALL ensure_index_name('company_subscriptions', 'idx_company_subscriptions_company', 'CREATE INDEX idx_company_subscriptions_company ON company_subscriptions (company_id, status)');
CALL ensure_index_name('activities', 'idx_activities_user_date', 'CREATE INDEX idx_activities_user_date ON activities (user_id, activity_date, start_time)');
CALL ensure_index_name('activities', 'idx_activities_company_date', 'CREATE INDEX idx_activities_company_date ON activities (company_id, activity_date, start_time)');
CALL ensure_index_name('activities', 'idx_activities_booking_token', 'CREATE UNIQUE INDEX idx_activities_booking_token ON activities (booking_confirmation_token)');
CALL ensure_index_name('whatsapp_notifications', 'idx_whatsapp_message_sid', 'CREATE INDEX idx_whatsapp_message_sid ON whatsapp_notifications (message_sid)');
CALL ensure_index_name('general_pendings', 'idx_general_pendings_user_date', 'CREATE INDEX idx_general_pendings_user_date ON general_pendings (user_id, pending_date)');
CALL ensure_index_name('general_pendings', 'idx_general_pendings_company_date', 'CREATE INDEX idx_general_pendings_company_date ON general_pendings (company_id, pending_date)');
CALL ensure_index_name('personal_reminders', 'idx_personal_reminders_due', 'CREATE INDEX idx_personal_reminders_due ON personal_reminders (user_id, completed, due_date)');
CALL ensure_index_name('personal_reminders', 'idx_personal_reminders_send', 'CREATE INDEX idx_personal_reminders_send ON personal_reminders (whatsapp_enabled, completed, remind_at, reminder_sent_at)');
CALL ensure_index_name('financial_entries', 'idx_financial_entries_user_date', 'CREATE INDEX idx_financial_entries_user_date ON financial_entries (user_id, entry_date)');
CALL ensure_index_name('financial_entries', 'idx_financial_entries_company_date', 'CREATE INDEX idx_financial_entries_company_date ON financial_entries (company_id, entry_date)');

DROP PROCEDURE IF EXISTS ensure_column;
DROP PROCEDURE IF EXISTS drop_column_if_exists;
DROP PROCEDURE IF EXISTS ensure_index_name;

COMMIT;
