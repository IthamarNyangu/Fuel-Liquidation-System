ALTER TABLE users
    MODIFY COLUMN role ENUM('super_admin', 'facility_admin', 'admin', 'approver', 'staff', 'finance')
    NULL DEFAULT 'staff';

UPDATE users
SET role = 'finance',
    is_super_admin = 0,
    is_facility_admin = 0
WHERE role = '';
