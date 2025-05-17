CREATE TABLE IF NOT EXISTS contest_exits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    contest_id INT NOT NULL,
    exit_time DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (contest_id) REFERENCES contests(id),
    UNIQUE KEY unique_user_contest (user_id, contest_id)
); 