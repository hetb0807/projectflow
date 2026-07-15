# ProjectFlow - Academic Project Management System

ProjectFlow is a web-based platform designed to manage academic projects. It connects students, faculty mentors, and administrators to streamline project proposals, team formations, document submissions, and progress reviews.

## Tech Stack
* **Backend:** PHP (using PDO for secure database queries)
* **Database:** MySQL
* **Frontend:** HTML, CSS, JavaScript (with FontAwesome 6.0.0 and Google Fonts)

---

## Features

### Students
* **Register Projects:** Submit project proposals containing titles, departments, seminar names, project types (Web, Mobile, AI/ML), technologies used, and descriptions.
* **Team Management:** Add up to 3 classmates to form a team (max 4 members total). Team edits are allowed while the project status is pending and lock automatically once approved.
* **Submissions:** Upload PDF reports (requirements, mockups, code zip, etc.) for reviews.
* **Collaboration:** Dedicated chat channel with teammates and the assigned mentor.

### Mentors (Faculty)
* **Manage Requests:** Approve or reject team project proposals. Each mentor has a limit of 5 active teams.
* **Reviews & Feedback:** Grade and review milestones (Review 1, Review 2, and Final Review) with numerical scores (1-10) and written comments.
* **Chat:** Communicate directly with student teams in project-specific chatrooms.
* **Faculty Directory:** Search lists of other faculty members and view their departments/availability.

### Admin
* **User Management:** Search students and mentors, reset passwords, disable/enable accounts, and add new faculty.
* **Project Moderation:** Manage active projects and override statuses if needed.
* **Activity Logs:** View logs of system events and document uploads.

---

## Default Accounts
All seeded accounts use `password` as the password.

### Administrator
* **Email:** `admin@projectflow.com`
* **Password:** `password`

### Faculty (Mentors)
* **Vaishali Patel:** `vaishali_ce@ldrp.ac.in`
* **Dr. Hiren B. Patel:** `hirenpatel@ldrp.ac.in`
* **Dr. Mehul P. Barot:** `mehulbarot@ldrp.ac.in`
*(See `database.sql` for the full list of 20 faculty accounts)*

### Students
* **ACHARYA HARIOM HITESHKUMAR:** `23BECE30003` (Email: `hariom03@123`)
* **AHIR NEELKUMAR RAMESHBHAI:** `23BECE30004` (Email: `neelkumar04@123`)
* **ARYA RUTUL NIKESHBHAI:** `23BECE30006` (Email: `rutul06@123`)
* **JILL JITESHBHAI BELADIYA:** `23BECE30013` (Email: `jill13@123`)
*(See `database.sql` for the full list of 44 student accounts)*

---

## Setup Instructions

### 1. Import Database Schema
1. Open phpMyAdmin, MySQL Workbench, or your preferred MySQL client.
2. Create a database named `projectflow`:
   ```sql
   CREATE DATABASE projectflow;
   ```
3. Import the `projectflow/database.sql` file.

### 2. Configure Database Connection
Update your database configuration in `projectflow/includes/db.php`:
```php
$host = 'localhost';
$db = 'projectflow';
$user = 'root'; // Your MySQL username
$pass = '';     // Your MySQL password
```

### 3. Run the Project
* **Using XAMPP / WAMP:** Copy the `PmsProject1` folder into your server's web directory (e.g., `htdocs` or `www`). Access it at `http://localhost/PmsProject1/projectflow/`.
* **Using PHP's built-in server:** Navigate to the `projectflow` directory in your terminal and run:
  ```bash
  php -S localhost:8000
  ```
  Open `http://localhost:8000` in your browser.
