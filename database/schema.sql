-- database/schema.sql

-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS voidix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE voidix;

-- Users table to store basic user information linked to Telegram sessions
-- Added api_id and api_hash to store user-specific credentials
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT UNIQUE NOT NULL,
    api_id INT, 
    api_hash VARCHAR(255),
    first_name VARCHAR(255),
    last_name VARCHAR(255),
    username VARCHAR(255),
    phone_number VARCHAR(20) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Sessions table to store JWT refresh tokens and link to Telegram sessions
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_hash VARCHAR(255) UNIQUE NOT NULL, -- Unique hash for each user's login session
    jwt_refresh_token TEXT NOT NULL,
    telegram_session_file VARCHAR(255) UNIQUE NOT NULL, -- Path to MadelineProto session file
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Job tracking table for Clean Engine operations
CREATE TABLE IF NOT EXISTS clean_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    job_type VARCHAR(50) NOT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    progress TEXT,
    start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMP,
    error_message TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
