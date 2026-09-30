
PHP Guidelines

# Architectural notes
1. The application is a PHP / MYSQL application.  (Eventually, we'll add an "app" for iOS and Android, but for now it's just a web application)
2. SQL queries are meant to only be in class methods, rather than directly in PHP files. for new code, please either add new SQL code within a method of an existing class or create a new class and put the SQL code there.
3. The database schema should be documented in a file schema.sql.
4. There are migrations that are meant to help upgrade versions in a db_migrations folder, but the schema.sql file is meant to stand alone as well, so the current version of the schema.sql file at any time should not need any migrations.
5. There should be an activity log table in the database which logs all write actions and logins.
IMPORTANT: When making database changes, always update schema.sql to reflect the current state. The schema.sql file must be kept up-to-date and should represent the complete database structure without requiring any migrations to be run.  Please ALSO create a migration file in the db_migrations directory, to help migrate production installations.
6. There should be an “email_log” that logs all email sent.
7. There is a SQL file that I’d like you to use to help which is the basic structure for another application that was built (different use-case, but some things similar).

## File structure
lib/
lib/UserManagement.php
lib/ObligationManagement.php
obligations/index.php
obligations/add_eval.php
obligations/add.php
obligations/edit.php
obligations/edit_eval.php
obligations/remove_eval.php
admin/settings.php
login.php
login_eval.php
logout.php
profile/index.php
profile/edit.php
profile/edit_eval.php
profile/edit_picture.php
contacts/index.php
contacts/add.php
contacts/add_eval.php
contacts/edit.php
contacts/edit_eval.php
contacts/remove_eval.php
assets/...
documents/...



## Database Writes

Database writes should only happen in methods of classes that are object management classes.  The public methods on these classes should take a "UserContext" object, which should specify the user id, whether they're an admin or not, and whether they're logged in via super.

There should be a UserContext class that exposes the method:
UserContext::getLoggedInUserContext()
... which should return a user context object that can be passed to these functions.

The UserContext is important because all writes to the database should also write to the ActivityLog, and the ActivityLog will need this information.

## Images
1. There should be an "images" table in the database which stores blobs of data by id.
2. Profile photos should reference an "image_id"
3. Profile photos should be displayed with a "reder_image.php" file passing in the id.
4. When rendering a link for an image like this, the system should try to cache the file in the file system in a cache/ directory and instead of displaying the render_image.php link, it should show the cache link.
To clarify - I want the image tag written out with the cached image or render_image.php.  I don't want render_image.php to try to hit the cache.   

## Security notes
1. Forms are protected with CSRF tokens.
2. Passwords have reasonable constraints to disallow weak passwords.
3. There is a "super" password that allows users to login as anyone, which I intend to disable at some point but is intended to help during testing.
4. There is a config.local.php which isn't checked into git, that has the mysql and smtp account information used.

## Data Model Notes
1. The data model is best understood by reading schema.sql, and new features of the data model should be written to this file and also a database_migration sql file, so the schema.sql file should always be up to date, but each new change should have a migration file so it can be added to the existing system.

## Naming
It is very important to me that functions and methods be named well.  The name of a method should express its intent.  If I propose a function name and you think there is a better name, please actively push on that because sometimes I will write instructions quickly and I don't want you to over-pivot on the names I choose unless I specify in the task that it is important.

## Modal Dialog Implementation Pattern
When implementing modal dialogs that require server-side data or processing, follow this separation of concerns pattern:
(1) Modal UI: Include modal HTML and JavaScript in the main page via UI manager classes (e.g., EventUIManager) so users get consistent modal experiences across all relevant pages
(2) AJAX Endpoint: Create dedicated PHP endpoints specifically for modal functionality (e.g., `admin_event_emails.php`, `event_attendees_export.php`) that return JSON responses. These endpoints should contain only the server-side logic needed by the modal, not full page rendering
(3) JavaScript Integration: Modal JavaScript should make AJAX calls to these dedicated endpoints rather than posting back to the current page. This keeps modal logic separate from page-specific logic, allows modals to work consistently across multiple pages, and makes the codebase more maintainable. The modal JavaScript handles success/error responses, updates modal content, and provides user feedback. Direct page links (like "Edit Event" or "Manage Volunteers") should still link directly to their respective pages without modals.

## Ajax endpoing html fragments
Ajax endpoints in general should return html fragments for the part of the page they affect so that they can replace that part of the page's html without having a full page reload.  Those parts of the page should be functionalized so that the ajax page can generate the HTML with the same logic as the original page itself.  The page should replace a section of html with the fragment returned on success and on error should put the error string in an appropriate place and handle the error in whatever way makes sense.

## Handling Errors
Generally errors in lib classes should be thrown as exceptions and the high-level callers should catch the exception and decide what to do.  Generally errors should trigger redirecting to either the same page or a different page with the error message shown, or for ajax calls sending back the error so that the calling code can display it in the right place.

Also - errors should not be swallowed!!! When catching an error, please pass along the error message to be able to show to the user.

Forms should be designed such that when errors happen and they return to the form, the form should be pre-populated so that the user does not have to re-enter the information!

## Single concerns per file
Some of the files in the current system have if branches at their top which handle form evaluations of the file.  This is not a pattern I want to continue.  If PHP file 1 is a form which evalutes, it should evaluate to PHP file 2.  Or if it calls an ajax query, that should be PHP file 3.  In other words, I want to prefer to not have one file have more than one purpose.

## GET vs POST
Web requests that don't modify data should generally be GET requests.  Web requests that modify persistent data (not logging to disk or traffic logging, but modifying important data in the database) should generally be POST requests.

## cachebusting
CSS and JS files should use a cachebusting technique so that updates get passed through.  Right now, the technique is to use the file modification time as a version strategy and append a querystring variable with the file modification time.

## On thoroughness
Please make sure when you call a function that it actually exists.  Make sure that if you change something you consider all the implications of the change.

## On checking your work
Anytime you change a file, you should check a few things:
- That when you call a function, it exists and the signature is what you expect
- When you call a function that fetches a set of data elements, you must absolutely check to see that the function exists and the signature is correct.  I've had an issue in the past with this particular type of call.
- That the file parses correctly (ie, it's unacceptable for there to be syntax errors for your changes)



## Import Flows

Import flows should be multistep.
Step 1: Select a CSV file. A user should be able to alternatively copy and paste CSV data directly into a textarea. The user should also be able to select the delimiter as either a comma or tab. There should be a button "Parse Import File" that should take the user to step 2.

Step 2: "Mapping." The user should see a list of column names in the import file, and each should show what field name the column will map to. It should default to a particular field for each column but the user should be ablle to fix the mapping by selecting the right data if it can be automatically mapped. In this page, the user should see sample data to feel comfortable about what is going on. There should be a button at the bottom of the step that says "Continue to Validation" and "Back". This phase should send the mapped data to step 3 (no need to keep teh original CSV)

Step 3: "Validation." The goal of this page is to show the user what data is invalid and also to sho the user what will happen when the data is imported. The user should see a table with one line per row, the relvant data should be printed, and there should be two extra columns: "Status" (ie. valid or not, matched or not, etc.), "Changes" (the changes that will happen when the data is imported like "create new user") as well as notices that should appear in an appropriate color that say things like "no match found".

Step 4: "Commit." This should commit the changes to the database.

# Test Philosophy

In www, there should be a directory unit-tests.

Please write unit tests for new api's that you write, using dependency injection.

No Endpoint tests, though.

No UI tests.

