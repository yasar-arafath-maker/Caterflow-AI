CREATE DATABASE IF NOT EXISTS eventai;
USE eventai;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100),
    password VARCHAR(255),
    role VARCHAR(50),
    location VARCHAR(255),
    phone VARCHAR(20),
    experience VARCHAR(255),
    batch_id INT,
    FOREIGN KEY (batch_id) REFERENCES batches(id)
);

CREATE TABLE functions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT,
    assigned_to_id INT,
    assigned_to_role VARCHAR(50),
    type VARCHAR(100),
    location VARCHAR(255),
    date DATE,
    time TIME,
    contact VARCHAR(255),
    num_workers INT,
    worker_rate DECIMAL(10,2),
    status VARCHAR(50),
    agent_status VARCHAR(50),
    total_payment DECIMAL(10,2),
    advance_payment DECIMAL(10,2),
    balance_due DECIMAL(10,2),
    payment_status VARCHAR(50),
    is_agent_as_worker TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (assigned_to_id) REFERENCES users(id)
);

CREATE TABLE assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    function_id INT,
    worker_id INT,
    role VARCHAR(50),
    status VARCHAR(50),
    batch_id INT,
    FOREIGN KEY (function_id) REFERENCES functions(id),
    FOREIGN KEY (worker_id) REFERENCES users(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id)
);


CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    function_id INT,
    customer_id INT,
    amount DECIMAL(10,2),
    payment_date DATETIME,
    payment_method VARCHAR(50),
    status VARCHAR(50),
    FOREIGN KEY (function_id) REFERENCES functions(id),
    FOREIGN KEY (customer_id) REFERENCES users(id)
);
 -- Fetch function details
SELECT f.*, u.name as agent_name 
FROM functions f 
JOIN users u ON f.assigned_to_id = u.id 
WHERE f.id = ? AND f.customer_id = ?; -- For customer
-- OR
SELECT f.*, u.name as agent_name 
FROM functions f 
JOIN users u ON f.assigned_to_id = u.id 
WHERE f.id = ? AND f.assigned_to_id = ?; -- For agent
-- OR
SELECT f.*, u.name as agent_name 
FROM functions f 
JOIN users u ON f.assigned_to_id = u.id 
WHERE f.id = ? AND EXISTS (SELECT 1 FROM assignments a WHERE a.function_id = f.id AND a.worker_id = ?); -- For worker

-- Check if agent is assigned as worker
SELECT COUNT(*) as agent_assignments FROM assignments WHERE function_id = ? AND worker_id = ?;

-- Fetch assigned workers
SELECT u.name, a.role 
FROM assignments a 
JOIN users u ON a.worker_id = u.id 
WHERE a.function_id = ?;