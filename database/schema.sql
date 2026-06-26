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

INSERT INTO plans (code, name, monthly_price, professional_limit, active)
VALUES
  ('free', 'Plan gratuito', 0, 4, 1),
  ('basic', 'Basico empresarial', 150000, 4, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  monthly_price = VALUES(monthly_price),
  professional_limit = VALUES(professional_limit),
  active = VALUES(active);

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(100) NOT NULL UNIQUE,
  email VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  company_role VARCHAR(40) NOT NULL DEFAULT 'owner',
  professional_id INT UNSIGNED NULL,
  is_system_admin TINYINT(1) NOT NULL DEFAULT 0,
  email_verified_at DATETIME NULL,
  verification_token_hash VARCHAR(255) NULL,
  verification_token_expires_at DATETIME NULL,
  verification_sent_at DATETIME NULL,
  profile_public TINYINT(1) NOT NULL DEFAULT 1,
  whatsapp_number VARCHAR(20) NOT NULL DEFAULT '',
  whatsapp_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0,
  telegram_chat_id VARCHAR(30) NOT NULL DEFAULT '',
  telegram_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0,
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

CREATE TABLE IF NOT EXISTS professionals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  linked_user_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

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
  description TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS professional_roles (
  professional_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (professional_id, role_id)
);

CREATE TABLE IF NOT EXISTS activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  company_id INT UNSIGNED NULL,
  professional_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  assignee VARCHAR(100) NOT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0,
  location VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  reminder_minutes SMALLINT UNSIGNED NULL,
  reminder_sent_at DATETIME NULL,
  activity_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS general_pendings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  company_id INT UNSIGNED NULL,
  professional_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  assignee VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  pending_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS financial_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  company_id INT UNSIGNED NULL,
  professional_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  entry_type ENUM('income', 'expense') NOT NULL DEFAULT 'income',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  participation_percentage DECIMAL(5,2) NULL,
  assignee VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  entry_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_users_email_unique ON users (email);
CREATE INDEX idx_users_company ON users (company_id);
CREATE INDEX idx_users_professional ON users (professional_id);
CREATE INDEX idx_professionals_company_active ON professionals (company_id, active, name);
CREATE INDEX idx_professionals_linked_user ON professionals (linked_user_id);
CREATE INDEX idx_service_roles_company_active ON service_roles (company_id, active, name);
CREATE INDEX idx_services_company_active ON services (company_id, active, name);
CREATE INDEX idx_services_role ON services (role_id);
CREATE INDEX idx_professional_roles_role ON professional_roles (role_id);
CREATE INDEX idx_company_subscriptions_company ON company_subscriptions (company_id, status);
CREATE INDEX idx_activities_user_date ON activities (user_id, activity_date, start_time);
CREATE INDEX idx_activities_company_date ON activities (company_id, activity_date, start_time);
CREATE INDEX idx_general_pendings_user_date ON general_pendings (user_id, pending_date);
CREATE INDEX idx_general_pendings_company_date ON general_pendings (company_id, pending_date);
CREATE INDEX idx_financial_entries_user_date ON financial_entries (user_id, entry_date);
CREATE INDEX idx_financial_entries_company_date ON financial_entries (company_id, entry_date);
