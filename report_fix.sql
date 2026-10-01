-- Run ONCE in phpMyAdmin: select school_system > SQL tab > paste > Go
-- Adds the columns your report.php uses (marks timestamp, grade points, remarks).
USE school_system;

ALTER TABLE student_marks    ADD COLUMN entered_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE grade_boundaries ADD COLUMN points TINYINT NOT NULL DEFAULT 0;
ALTER TABLE grade_boundaries ADD COLUMN remark VARCHAR(40) NULL;

-- Uganda New Curriculum grading scale used in official student and class reports.
DELETE FROM grade_boundaries WHERE grade IN ('A','B','C','D','E','F','D1','D2','C3','C4','C5','C6','P7','P8','F9');

INSERT IGNORE INTO grade_boundaries (grade, min_score, points, remark) VALUES
('D1',75,1,'Distinction'),('D2',70,2,'Distinction'),
('C3',65,3,'Credit'),('C4',60,4,'Credit'),('C5',55,5,'Credit'),('C6',50,6,'Credit'),
('P7',45,7,'Pass'),('P8',40,8,'Pass'),('F9',0,9,'Fail');
