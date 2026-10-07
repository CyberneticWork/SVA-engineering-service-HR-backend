-- =============================================================================
-- Fresh database — clear ALL data, KEEP users and companies
-- =============================================================================
-- KEEPS
--   • companies, company_locations
--   • users that are HR / admin logins (users.employee_id IS NULL and role <> 'employee')
--   • user_acl_permissions and hr_roles for those users
--   • employment_types, emergency_contact_relationship_types (employee form lookups)
--   • migrations (so `php artisan migrate` keeps working)
--
-- DELETES (everything else), including
--   employees + profiles, departments, sub_departments, designations, shifts,
--   rosters, time cards, OT, leave, loans, advances, salary process, allowances,
--   deductions, bonuses, holidays, LMS/PMS, hikvision devices/logs, notices,
--   accounting/inventory, sessions, tokens (everyone logs in again),
--   and employee portal logins (they point at deleted employees).
--
-- BACK UP THE DATABASE FIRST.
--
-- phpMyAdmin: Import this file. If DELIMITER fails, set the delimiter box
-- at the bottom of the SQL tab to // and run the procedure block on its own.
-- CLI:
--   mysql -u USER -p YOUR_DATABASE < fresh_keep_users_companies.sql
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET @db := DATABASE();

SELECT id, name, email, role AS will_keep
FROM users
WHERE employee_id IS NULL AND LOWER(IFNULL(role, '')) <> 'employee';

SELECT id, name AS will_keep_company FROM companies;

DROP PROCEDURE IF EXISTS fresh_keep_users_companies;
DELIMITER //
CREATE PROCEDURE fresh_keep_users_companies()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE t VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT TABLE_NAME
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db
      AND TABLE_TYPE = 'BASE TABLE'
      AND TABLE_NAME NOT IN (
        'migrations',
        'users',
        'user_acl_permissions',
        'hr_roles',
        'companies',
        'company_locations',
        'employment_types',
        'emergency_contact_relationship_types'
      )
    ORDER BY TABLE_NAME;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  SET FOREIGN_KEY_CHECKS = 0;

  -- Employee portal logins belong to employees that are being removed.
  DELETE FROM users
  WHERE employee_id IS NOT NULL OR LOWER(IFNULL(role, '')) = 'employee';

  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_acl_permissions'
  ) THEN
    DELETE FROM user_acl_permissions WHERE user_id NOT IN (SELECT id FROM users);
  END IF;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO t;
    IF done = 1 THEN
      LEAVE read_loop;
    END IF;
    SET @sql := CONCAT('DELETE FROM `', t, '`');
    PREPARE s FROM @sql;
    EXECUTE s;
    DEALLOCATE PREPARE s;
    BEGIN
      DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
      SET @sql := CONCAT('ALTER TABLE `', t, '` AUTO_INCREMENT = 1');
      PREPARE s FROM @sql;
      EXECUTE s;
      DEALLOCATE PREPARE s;
    END;
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;

CALL fresh_keep_users_companies();
DROP PROCEDURE IF EXISTS fresh_keep_users_companies;

SET FOREIGN_KEY_CHECKS = 1;

SELECT id, name, email, role AS remaining_logins FROM users;
SELECT COUNT(*) AS companies_left FROM companies;
SELECT COUNT(*) AS employees_left FROM employees;
SELECT COUNT(*) AS departments_left FROM departments;
