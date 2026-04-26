# Spec 028: Contact Us Form

## Overview
Implement a "Contact Us" form that allows users to submit inquiries, suggestions, or complaints. When an authenticated user submits the form, the application must save the submission to the database and send an email notification to an administrator email address configured via an environment variable. The submission endpoint should also be rate-limited to prevent abuse.

## Requirements

### 1. Database & Model
Create a model named `ContactMessage` (or similar) with the corresponding migration.
- `user_id` (foreign key to users table)
- `name` (string, required)
- `email` (string, required)
- `phone` (string, nullable)
- `type` (enum or string restricted to: `inquiry`, `suggestion`, `complaint`)
- `subject` (string, required)
- `message` (text, required)
- `created_at` / `updated_at` (timestamps)

### 2. Environment Configuration
- Introduce a new environment variable, e.g., `CONTACT_MAIL_TO`.
- Add this configuration to an appropriate config file (e.g., `config/mail.php` or `config/services.php`).

### 3. Validation
Create a Form Request (e.g., `StoreContactMessageRequest`) to validate incoming payloads:
- `name`: required, string, max 255
- `email`: required, valid email, max 255
- `phone`: nullable, string (potentially with regex for phone numbers)
- `type`: required, in: `inquiry`, `suggestion`, `complaint`
- `subject`: required, string, max 255
- `message`: required, string

### 4. Mailable
Create a Mailable class (e.g., `ContactMessageSubmitted`) that implements `ShouldQueue` for asynchronous sending.
- The mailable must be pushed to a specific queue named `contact` (not the default queue).
- Pass the saved `ContactMessage` model to the Mailable.
- Create an email view (Blade template) that formats and displays all submitted fields along with the authenticated user details.

### 5. Controller & Routing
- Route must be protected by the `auth` (or `auth:sanctum`) middleware.
- Apply a rate limiting middleware (e.g., `throttle:5,1` allowing 5 requests per minute).
- Validate the request using the Form Request.
- Create a new record in the database, automatically associating it with the authenticated `auth()->id()`.
- Dispatch the Mailable to the configured environment email address.
- Return an appropriate success response (JSON for API, or a redirect with a success flash message for web).

### 6. Testing (Pest)
Write Pest feature tests covering:
- Access restriction (assert unauthenticated users get 401/403).
- Rate limiting enforcement (assert hitting the endpoint too many times returns 429 Too Many Requests).
- Successful form submission by an authenticated user (assert database has the record and `user_id` matches).
- Validation errors (missing required fields, invalid email format, invalid type).
- Email dispatching (using `Mail::fake()` to assert the Mailable is sent to the correct address and pushed to the `contact` queue).
