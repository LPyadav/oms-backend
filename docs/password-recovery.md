# Password recovery setup

Password recovery sends an email link from Laravel and opens the reset form in
the Vue app. Complete the [backend setup](../README.md), including the application
key and database migrations, before testing the flow.

## Backend environment

Set these values in the backend `.env` file. Use the actual Vue origin
including its port when applicable. Run the Vue checkout that includes the
Forgot password and Reset password screens. Use the HTTPS Vue origin in
production.

```dotenv
AUTH_PASSWORD_RESET_FRONTEND_URL=http://127.0.0.1:5173
QUEUE_CONNECTION=database
```

The API and queue worker use the same backend configuration and `APP_KEY`.
The recovery job is encrypted and is processed from the `default` queue.

## Local testing with the log mailer

The default in [`.env.example`](../.env.example) writes the email content to
the application log.

```dotenv
MAIL_MAILER=log
```

With the default logging settings, find the reset link in
`storage/logs/laravel.log`.

## SMTP inbox delivery

Copy the following settings into the backend `.env` and replace the
placeholders with your SMTP provider's values. This example uses port 587.
Use a sender address that the provider authorizes.

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD="your-smtp-password"
MAIL_FROM_ADDRESS="verified-sender@example.com"
MAIL_FROM_NAME="OMS"
```

Use the host, port and scheme supplied by your provider. Keep the SMTP
credentials in the backend environment or deployment secret store.

## Run the queue worker

From the backend directory, start a worker that serves `default`. The
following command also serves the OMS order notifications queue. If your
`DB_QUEUE` setting changes the database queue name, include that queue in
the worker command.

```bash
php artisan queue:work --queue=notifications,default
```

After changing `.env`, clear a cached configuration with
`php artisan config:clear` and restart any running worker. If deployment
uses `php artisan config:cache`, rebuild that cache before restarting
the worker. For a worker running in a local terminal, stop it and run the
command above again.

## Verify the flow

1. Open the Vue sign-in page, select **Forgot password?** and submit the
   email of an active test account. The API returns `202 Accepted` when
   it accepts the request for queue processing.
2. Open the reset link in the local log or in the inbox, depending on the
   configured mailer. The link opens the Vue reset form and expires after
   60 minutes.
3. Enter and confirm the new password, then sign in again. A successful
   reset consumes the link and revokes the account's API sessions.
4. If the email does not appear, check that the worker serves `default`
   and inspect the backend log or `php artisan queue:failed`. If the link
   opens a different app or port, check
   `AUTH_PASSWORD_RESET_FRONTEND_URL`.
