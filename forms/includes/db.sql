-- ===========================
-- Final Medical Database Schema (with read_at)
-- ===========================

CREATE DATABASE IF NOT EXISTS vaxcare_pro;
USE vaxcare_pro;

-- ---------------------------
-- Admins
-- ---------------------------
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ---------------------------
-- Hospitals
-- ---------------------------
CREATE TABLE IF NOT EXISTS hospitals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('parent','hospital') NOT NULL,
    sender_id INT NOT NULL,
    receiver_type ENUM('parent','hospital') NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NULL,
    file_path VARCHAR(255) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS chat_status (
    user_type ENUM('parent','hospital'),
    user_id INT,
    is_typing TINYINT(1) DEFAULT 0,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_type, user_id)
);


-- ---------------------------
-- Parents
-- ---------------------------
CREATE TABLE IF NOT EXISTS parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ---------------------------
-- Children
-- ---------------------------
CREATE TABLE IF NOT EXISTS children (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    date_of_birth DATE NOT NULL,
    gender ENUM('male', 'female', 'other') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
);

-- ---------------------------
-- Vaccines
-- ---------------------------
CREATE TABLE IF NOT EXISTS vaccines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(50) NOT NULL,
    manufacturer VARCHAR(100),
    batch_number VARCHAR(50) UNIQUE NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    price DECIMAL(10,2) DEFAULT 0,
    expiry_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE
);

-- ---------------------------
-- Bookings
-- ---------------------------
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    hospital_id INT NOT NULL,
    child_id INT NOT NULL,
    vaccine_type VARCHAR(50) NOT NULL,
    booking_date DATE NOT NULL,
    booking_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
);

-- ---------------------------
-- Vaccination Records
-- ---------------------------
CREATE TABLE IF NOT EXISTS vaccination_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    vaccine_id INT NOT NULL,
    administered_by INT NOT NULL,
    administered_date DATE NOT NULL,
    next_due_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE CASCADE,
    FOREIGN KEY (administered_by) REFERENCES hospitals(id) ON DELETE CASCADE
);

-- ---------------------------
-- Messages (Unified) - with read_at
-- ---------------------------
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('parent','hospital') NOT NULL,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    child_id INT DEFAULT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL
);

-- If messages table exists but read_at is missing, add it:
ALTER TABLE messages
ADD COLUMN IF NOT EXISTS read_at TIMESTAMP NULL DEFAULT NULL AFTER created_at;

-- ---------------------------
-- Reminders
-- ---------------------------
CREATE TABLE IF NOT EXISTS reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    child_id INT NOT NULL,
    vaccine_name VARCHAR(100) NOT NULL,
    due_date DATE NOT NULL,
    is_sent BOOLEAN DEFAULT FALSE
);

-- ---------------------------
-- Notifications
-- ---------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('parent','hospital') NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------
-- Daily Stats
-- ---------------------------
CREATE TABLE IF NOT EXISTS daily_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stat_date DATE NOT NULL,
    vaccinations INT DEFAULT 0,
    registrations INT DEFAULT 0
);

-- ===========================
-- Payment System Tables
-- ===========================

-- Add price to vaccines table if not already present
ALTER TABLE vaccines 
ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) DEFAULT 0 AFTER quantity;

-- Add payment support to bookings table
ALTER TABLE bookings 
ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid', 'pending', 'paid') DEFAULT 'unpaid' AFTER status,
ADD COLUMN IF NOT EXISTS total_amount DECIMAL(10,2) DEFAULT 0 AFTER payment_status,
ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP NULL DEFAULT NULL AFTER total_amount;

-- Booking Vaccinations (Many-to-Many relationship)
CREATE TABLE IF NOT EXISTS booking_vaccinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    vaccine_id INT NOT NULL,
    vaccine_name VARCHAR(100) NOT NULL,
    vaccine_type VARCHAR(50) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    quantity INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_booking_vaccine (booking_id, vaccine_id)
);

-- Payment Accounts (Parents can have multiple payment accounts)
CREATE TABLE IF NOT EXISTS payment_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    account_balance DECIMAL(10,2) DEFAULT 0,
    is_verified TINYINT(1) DEFAULT 0,
    verification_token VARCHAR(255),
    verification_token_expires TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
    UNIQUE KEY unique_email_phone (email, phone)
);

-- Payments (Record of all payment transactions)
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    payment_account_id INT NOT NULL,
    parent_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('card', 'bank_transfer', 'wallet', 'other') DEFAULT 'card',
    transaction_id VARCHAR(255) UNIQUE,
    external_payment_id VARCHAR(255),
    payment_gateway ENUM('test', 'stripe', 'jazzcash', 'razorpay', 'other') DEFAULT 'test',
    status ENUM('pending', 'processing', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    refund_amount DECIMAL(10,2) DEFAULT 0,
    refund_reason VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_account_id) REFERENCES payment_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_payment_gateway (payment_gateway),
    INDEX idx_booking_parent (booking_id, parent_id)
);

-- QR Codes (Linked to bookings, generated after payment)
CREATE TABLE IF NOT EXISTS qr_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    parent_id INT NOT NULL,
    child_id INT NOT NULL,
    hospital_id INT NOT NULL,
    qr_data TEXT NOT NULL,
    qr_image_path VARCHAR(255) NOT NULL,
    qr_image_base64 LONGTEXT,
    status ENUM('active', 'scanned', 'completed', 'expired') DEFAULT 'active',
    scanned_by INT,
    scanned_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    FOREIGN KEY (scanned_by) REFERENCES hospitals(id) ON DELETE SET NULL,
    INDEX idx_booking_status (booking_id, status),
    INDEX idx_parent_id (parent_id)
);

-- Performance Indices
ALTER TABLE bookings ADD INDEX IF NOT EXISTS idx_payment_status (payment_status);
ALTER TABLE bookings ADD INDEX IF NOT EXISTS idx_parent_child (parent_id, child_id);
ALTER TABLE payments ADD INDEX IF NOT EXISTS idx_parent_id (parent_id);
ALTER TABLE payment_accounts ADD INDEX IF NOT EXISTS idx_parent_id (parent_id);
ALTER TABLE booking_vaccinations ADD INDEX IF NOT EXISTS idx_vaccine_id (vaccine_id);

-- Update vaccine prices
UPDATE vaccines SET price = 500 WHERE type = 'BCG' AND price = 0;
UPDATE vaccines SET price = 800 WHERE type = 'HepB' AND price = 0;
UPDATE vaccines SET price = 1200 WHERE type = 'Polio' AND price = 0;
UPDATE vaccines SET price = 1500 WHERE type = 'DPT' AND price = 0;
UPDATE vaccines SET price = 2000 WHERE type = 'Measles' AND price = 0;

-- Migration tracking
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO schema_migrations (migration_name) VALUES 
('2026-01-21_payment_system_schema_v1.0');

-- ===========================
-- Hospital Ratings System
-- ===========================

-- Hospital Ratings Table (for parent reviews)
CREATE TABLE IF NOT EXISTS hospital_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    hospital_id INT NOT NULL,
    booking_id INT,
    stars TINYINT NOT NULL CHECK (stars >= 1 AND stars <= 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    UNIQUE KEY unique_parent_hospital_booking (parent_id, hospital_id, booking_id)
);

-- Indices for hospital ratings
CREATE INDEX IF NOT EXISTS idx_hospital_id ON hospital_ratings(hospital_id);
CREATE INDEX IF NOT EXISTS idx_parent_id_ratings ON hospital_ratings(parent_id);
CREATE INDEX IF NOT EXISTS idx_created_at ON hospital_ratings(created_at);

INSERT IGNORE INTO schema_migrations (migration_name) VALUES 
('2026-01-21_hospital_ratings_schema_v1.0');

-- ---------------------------
-- Sample Data
-- ---------------------------
INSERT IGNORE INTO admins (name, email, password, phone) VALUES 
('System Administrator', 'admin@vaxcarepro.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+1234567890');

INSERT IGNORE INTO hospitals (name, email, password, phone, address) VALUES 
('City General Hospital', 'cityhospital@vaxcarepro.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+1234567891', '123 Main Street, City Center'),
('Community Health Center', 'community@vaxcarepro.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+1234567892', '456 Oak Avenue, Downtown');

INSERT IGNORE INTO parents (name, email, password, phone) VALUES 
('John Smith', 'john.smith@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+1234567893'),
('Sarah Johnson', 'sarah.j@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '+1234567894');

INSERT IGNORE INTO children (parent_id, name, date_of_birth, gender) VALUES 
(1, 'Emma Smith', '2020-05-15', 'female'),
(1, 'Noah Smith', '2022-01-20', 'male'),
(2, 'Liam Johnson', '2021-08-10', 'male');

INSERT IGNORE INTO vaccines (hospital_id, name, type, manufacturer, batch_number, quantity, expiry_date) VALUES 
(1, 'BCG Vaccine', 'BCG', 'VaccineCorp', 'BCG202401', 150, '2024-12-31'),
(1, 'Hepatitis B', 'HepB', 'MediVax', 'HEPB202402', 200, '2024-11-30'),
(1, 'Polio Vaccine', 'Polio', 'HealthPharm', 'POL202403', 180, '2024-10-31'),
(2, 'DPT Vaccine', 'DPT', 'VaccineCorp', 'DPT202404', 120, '2024-09-30'),
(2, 'Measles Vaccine', 'Measles', 'MediVax', 'MSL202405', 100, '2024-08-31');

INSERT IGNORE INTO bookings (parent_id, hospital_id, child_id, vaccine_type, booking_date, booking_time, status) VALUES 
(1, 1, 1, 'BCG', '2024-01-15', '10:00:00', 'completed'),
(1, 1, 2, 'Hepatitis B', '2024-01-20', '14:30:00', 'approved'),
(2, 2, 3, 'DPT', '2024-01-25', '11:15:00', 'pending');
