ALTER TABLE weekly_liquidations
    ADD COLUMN IF NOT EXISTS submission_round INT NOT NULL DEFAULT 0 AFTER status;

INSERT INTO roles (code, name, description)
VALUES ('finance', 'Finance', 'Gives the final finance approval after provincial and fleet checks')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description);

CREATE TABLE IF NOT EXISTS reconciliation_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    weekly_liquidation_id INT NOT NULL,
    submission_round INT NOT NULL DEFAULT 1,
    review_role VARCHAR(50) NOT NULL,
    review_action ENUM('checked', 'returned', 'approved') NOT NULL,
    reviewed_by INT NULL,
    reviewer_name VARCHAR(255) NULL,
    reviewer_email VARCHAR(255) NULL,
    review_notes TEXT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reconciliation_reviews_package_round (weekly_liquidation_id, submission_round),
    KEY idx_reconciliation_reviews_role_action (review_role, review_action),
    KEY idx_reconciliation_reviews_reviewer (reviewed_by),
    CONSTRAINT fk_reconciliation_reviews_weekly_liquidation
        FOREIGN KEY (weekly_liquidation_id) REFERENCES weekly_liquidations(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_reconciliation_reviews_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
