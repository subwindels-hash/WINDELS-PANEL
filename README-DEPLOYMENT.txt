MarvySocials — cPanel deployment
=================================

Six steps, no terminal, no Composer, no npm, no symlinks.

1. UPLOAD
   cPanel -> File Manager -> the folder your domain serves (usually
   public_html) -> Upload this zip -> Extract. index.php must end up directly
   in that folder. The framework ships INSIDE this package as real files —
   system/ and vendor/codeigniter/framework/system are both included, so the
   application finds it automatically. "Your system folder path does not
   appear to be set correctly" means the upload was cut short: re-upload and
   re-extract.

2. CREATE THE DATABASE
   cPanel -> MySQL Databases. Create a database, create a user, set a password,
   then "Add User To Database" with ALL PRIVILEGES.

3. IMPORT THE DATABASE
   cPanel -> phpMyAdmin -> select the new database -> Import ->
   choose database/marvysocials.sql -> Go.
   This creates every table and all the data the panel needs. Nothing else has
   to run afterwards.

4. CONFIGURE .env
   In File Manager, copy .env.example to .env and edit it:
       CI_ENV=production
       VP_BASE_URL=https://yourdomain.com
       VP_DB_HOST=localhost
       VP_DB_PORT=3306
       VP_DB_NAME=your_database
       VP_DB_USER=your_database_user
       VP_DB_PASS=your_database_password
       VP_ENCRYPTION_KEY=...   (32+ random characters; when MOVING an existing
       VP_AUTH_SECRET=...       panel, copy these two from the old server)

5. VERIFY THE DEPLOYMENT (browser, one page)
   Open https://yourdomain.com/deploy-verify.php
   It checks the PHP version, extensions, the CodeIgniter system path,
   writable folders, .env and a live database connection, and names the exact
   fix for anything that failed. When everything is green, DELETE
   deploy-verify.php in File Manager.

6. OPEN THE SITE
   https://yourdomain.com

FIRST LOGIN
   The credentials are printed at the top of database/marvysocials.sql.
   Change the password immediately, or set your own before the first login by
   putting VP_SETUP_TOKEN=<32 random characters> in .env and visiting
   https://yourdomain.com/setup?token=<that value>. Remove the line afterwards.

FOLDER PERMISSIONS (only if something is not writable)
   Directories 755, files 644. These four must be writable by the web server:
       storage/logs/
       storage/ticket_attachments/
       storage/cache/
       storage/cache/sessions/
       assets/uploads/
   cPanel -> File Manager -> select the folder -> Permissions -> 755 (or 775).

Full guide: docs/cpanel-deployment.md
