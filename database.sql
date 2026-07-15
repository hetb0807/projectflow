-- ProjectFlow Database Schema - Advanced Version (Teams, Chat, Submissions)
-- Use this file for a clean re-import or manual update.

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS project_reviews;
DROP TABLE IF EXISTS submissions;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS project_members;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE DATABASE IF NOT EXISTS projectflow;
USE projectflow;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NULL UNIQUE,
    enrollment_no VARCHAR(50) UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'mentor', 'admin') NOT NULL,
    department VARCHAR(100) NULL,
    designation VARCHAR(100) NULL,
    qualification VARCHAR(200) NULL,
    research_area TEXT NULL,
    max_teams INT DEFAULT 5,
    notif_mentor_msg TINYINT(1) DEFAULT 1,
    notif_project_approved TINYINT(1) DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    notif_new_project TINYINT(1) DEFAULT 1,
    notif_new_user TINYINT(1) DEFAULT 1,
    profile_image VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Projects Table
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL, -- The project owner/lead
    mentor_id INT NOT NULL,
    project_title VARCHAR(200) NOT NULL,
    department VARCHAR(100) NULL,
    seminar_name VARCHAR(100) NULL,
    project_type VARCHAR(50) NULL,
    technologies TEXT NULL,
    description TEXT NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (mentor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Project Members (Team)
CREATE TABLE IF NOT EXISTS project_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    student_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (project_id, student_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Chat Messages
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) DEFAULT 0,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Work Submissions
CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    student_id INT NOT NULL, -- Who uploaded it
    file_path VARCHAR(255) NOT NULL,
    submission_title VARCHAR(100) NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    mentor_comment TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Project Reviews (Formal Feedback)
CREATE TABLE IF NOT EXISTS project_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    mentor_id INT NOT NULL,
    review_type ENUM('Review 1', 'Review 2', 'Final Review') NOT NULL,
    status ENUM('Pending', 'Approved', 'Needs Improvement', 'Completed') DEFAULT 'Pending',
    feedback TEXT,
    reviewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (mentor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert Students
-- Insert Students (44 Records)
INSERT INTO users (name, email, enrollment_no, password, role) VALUES 
('ACHARYA HARIOM HITESHKUMAR', 'hariom03@123', '23BECE30003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('AHIR NEELKUMAR RAMESHBHAI', 'neelkumar04@123', '23BECE30004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('ARYA RUTUL NIKESHBHAI', 'rutul06@123', '23BECE30006', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BAGADIA VRAJ HARSHAD', 'vraj07@123', '23BECE30007', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BALCHANDANI DIVYA KAMLESH', 'divya08@123', '23BECE30008', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BAMANIYA DAKSHIT RAMESH', 'dakshit09@123', '23BECE30009', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BARAIYA TANISHK KALPESHKUMAR', 'tanishk10@123', '23BECE30010', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BARASARA MEET JAYESHBHAI', 'meet11@123', '23BECE30011', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('JILL JITESHBHAI BELADIYA', 'jill13@123', '23BECE30013', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHADJA HET JAYESHBHAI', 'het14@123', '23BECE30014', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('TRUSHA BHALALA', 'trusha15@123', '23BECE30015', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHALANI KRUTI PANKAJBHAI', 'kruti16@123', '23BECE30016', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHALSOD DHRUVKUMAR ASHVIN', 'dhruvkumar18@123', '23BECE30018', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHANDARI DEV PANKAJ', 'dev19@123', '23BECE30019', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHANDARI PALAK NARENDRABHAI', 'palak20@123', '23BECE30020', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHARVAD CHIRAGBHAI BABABHAI', 'chiragbhai21@123', '23BECE30021', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('KARTIK BHATNAGAR', 'kartik22@123', '23BECE30022', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHATT KRISHNA HARDIKKUMAR', 'krishna23@123', '23BECE30023', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHATT RUSHI GOPALBHAI', 'rushi24@123', '23BECE30024', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHAVSAR HET NILESHKUMAR', 'het25@123', '23BECE30025', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHAVSAR MOHAK JIGNESHKUMAR', 'mohak26@123', '23BECE30026', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHAVSAR NEEV AMITKUMAR', 'neev27@123', '23BECE30027', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BHENSDADIYA PRINCEKUMAR ASHVINBHAI', 'princekumar28@123', '23BECE30028', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('BUCH KANKSHA KEYUR', 'kanksha29@123', '23BECE30029', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHAUDHARY DRAUN HARESHBHAI', 'draun31@123', '23BECE30031', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHAUDHARY MANKUMAR SATISHBHAI', 'mankumar32@123', '23BECE30032', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHAUHAN HARIKRISHNA VIJAYKUMAR', 'harikrishna33@123', '23BECE30033', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('JINALBA CHAUHAN', 'jinalba34@123', '23BECE30034', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHAUHAN SHAKTISINH BAHADURSINH', 'shaktisinh35@123', '23BECE30035', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHAVDA YASHVIKUVARBA SURENDRASINH', 'yashvikuvarba36@123', '23BECE30036', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHAVDA KRISH DINESHKUMAR', 'krish37@123', '23BECE30037', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHAVDA MOHIT HARILAL', 'mohit38@123', '23BECE30038', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHAUDHARI OM SURESHKUMAR', 'om39@123', '23BECE30039', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHITRODA JANKI SANJAYBHAI', 'janki40@123', '23BECE30040', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('CHOVATIYA NENCY HARESHBHAI', 'nency41@123', '23BECE30041', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DEEPRAJSINH DHARMAVIRSINH CHUDASAMA', 'deeprajsinh42@123', '23BECE30042', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DALSANIYA VRUNDA SANJAYBHAI', 'vrunda43@123', '23BECE30043', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DALSANIYA ZEEL DEEPAKBHAI', 'zeel44@123', '23BECE30044', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DALVI KAUSHIK NARENDRA', 'kaushik45@123', '23BECE30045', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DANTANI PRINCEKUMAR MAHENDRABHAI', 'princekumar46@123', '23BECE30046', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DARJI DHAIRYA BHARATBHAI', 'dhairya47@123', '23BECE30047', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DARJI HARSH RIKENBHAI', 'harsh48@123', '23BECE30048', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DARJI NEEL DIPAKBHAI', 'neel49@123', '23BECE30049', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('DARJI PARTHIV BRIJESHKUMAR', 'parthiv50@123', '23BECE30050', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student');

-- Insert 20 LDRP Faculty Members
INSERT INTO users (name, email, role, password, department, designation, qualification, research_area) VALUES 
('Vaishali Patel', 'vaishali_ce@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Computer Engineering', 'Lecturer', 'M.E. (Computer Engineering)', 'Information Security'),
('Dr. Hiren B. Patel', 'hirenpatel@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Computer Engineering', 'Professor & HOD', 'Ph.D., M.E.', 'Cloud Computing'),
('Dr. Mehul P. Barot', 'mehulbarot@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Computer Engineering', 'Professor', 'Ph.D., M.E.', 'Data Science, AI'),
('Sandip Modha', 'sandipmodha@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Information Technology', 'Assistant Professor', 'M.Tech (CSE)', 'Web Technologies'),
('Dushyant Chavda', 'dushyantchavda@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Information Technology', 'Lecturer', 'M.Tech (CSE)', 'Cyber Security'),
('J. V. Dave', 'jvdave@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Electronics & Communication', 'Professor & HOD', 'M.E. (EC)', 'Communication Systems'),
('Dr. Shridhar Mendhe', 'mendhe@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Electronics & Communication', 'Professor', 'Ph.D. (EC)', 'Embedded Systems'),
('Ankita Parikh', 'ankita@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Civil Engineering', 'Assistant Professor', 'M.E.', 'Water Resource Engineering'),
('Megha Bhatt', 'meghabhatt@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Civil Engineering', 'Lecturer', 'M.Tech Structural Engineering', 'Structural Design'),
('Prakash K. Shah', 'prakashshah@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Electrical Engineering', 'Professor', 'M.E. Electrical', 'Power Systems'),
('Ketan Patel', 'ketanpatel@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Computer Engineering', 'Assistant Professor', 'M.Tech', 'Machine Learning'),
('Pooja Shah', 'poojashah@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Computer Engineering', 'Lecturer', 'M.E.', 'Data Mining'),
('Nirav Desai', 'niravdesai@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Information Technology', 'Assistant Professor', 'M.Tech', 'Cloud Computing'),
('Rupal Trivedi', 'rupaltrivedi@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Information Technology', 'Lecturer', 'M.E.', 'Web Development'),
('Dhaval Joshi', 'dhavaljoshi@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Computer Engineering', 'Assistant Professor', 'M.Tech', 'Artificial Intelligence'),
('Harshad Patel', 'harshadpatel@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Electrical Engineering', 'Lecturer', 'M.E. Electrical', 'Power Electronics'),
('Krunal Shah', 'krunalshah@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mechanical Engineering', 'Assistant Professor', 'M.Tech', 'Thermal Engineering'),
('Sneha Mehta', 'snehamehta@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Computer Engineering', 'Lecturer', 'M.E.', 'Software Engineering'),
('Amit Panchal', 'amitpanchal@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Civil Engineering', 'Assistant Professor', 'M.E.', 'Construction Management'),
('Rakesh Patel', 'rakeshpatel@ldrp.ac.in', 'mentor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Information Technology', 'Lecturer', 'M.Tech', 'Network Security');

-- Insert Admin
INSERT INTO users (name, email, role, password) VALUES 
('Admin', 'admin@projectflow.com', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    project_id INT NULL,
    type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
