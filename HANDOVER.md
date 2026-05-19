# Handover Manual: B2B Outreach LAMP Stack

The software has been fully developed beyond a skeleton into a functional LAMP stack application. It is ready for deployment on shared hosting with cPanel.

## 📁 Final Component List

### 🛰️ API Layer (`/api`)

- `leads.php`: List and create leads.
- `import_leads.php`: Processes CSV uploads and populates the database.
- `campaigns.php`: Manages outreach campaigns and email templates.
- `settings.php`: Encrypts/stores system configuration (API Keys, SMTP).
- `trigger_task.php`: Allows manual execution of tasks (Enrichment/Outreach) for testing.

### 🧠 Core Engine (`/includes`)

- `db.php`: Central PDO connection handler.
- `runner.php`: The "Lead Worker" logic. This is where the Agentic behavior (calling LLMs, sending emails) lives.

### 🖥️ Mission Control

- `index.php`: The primary dashboard using a premium glassmorphism design.
- `assets/js/dashboard.js`: The frontend controller for the tabbed SPA-style experience.

## 🛠️ How to Test Without a Live Server

Since this is local, you can simulate the functionality using PHP's built-in server:

1. Open a terminal in `c:\sandbox\b2b_outreach_lamp`.
2. Run: `php -S localhost:8000`.
3. Open `http://localhost:8000` in your browser.

> [!NOTE]
> You must have a local MySQL server running and have imported `schema.sql` for the database-driven features to function.

## 🚀 Transitioning to cPanel

Once you are ready to go live:

1. Upload all files to `public_html`.
2. Create a MySQL database and user in cPanel.
3. Import `schema.sql` via phpMyAdmin.
4. Update `includes/db.php` with your database credentials.
5. Set up a Cron Job to run `php /home/username/public_html/cron/process_queue.php` every minute.
