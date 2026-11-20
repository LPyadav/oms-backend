# OMS Backend

Laravel 13 API service for the Order Management System. The current Foundation module provides MySQL migrations, a JSON health endpoint, and a feature test for that endpoint. The companion `oms-frontend` repository contains the Vue application.

## Prerequisites

- PHP 8.3 or later with `pdo_mysql` and the standard Laravel extensions
- Composer 2 with the PHP ZIP extension or an archive tool such as 7-Zip
- MySQL running locally, for example through Laragon on port `3306`

## Local setup

1. Create a development schema with `utf8mb4` and use a MySQL account scoped to that schema. For example, run the following as an administrator after replacing the password placeholder.

   ```sql
   CREATE DATABASE oms_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'oms'@'127.0.0.1' IDENTIFIED BY '<local-password>';
   GRANT ALL PRIVILEGES ON oms_dev.* TO 'oms'@'127.0.0.1';
   ```

2. From the repository root, install the locked dependencies and create your local `.env`. In Windows Command Prompt, use `copy .env.example .env` in place of `cp`. In PowerShell, use `Copy-Item .env.example .env`. Keep your existing `.env` if you already have one.

   ```sh
   composer install
   cp .env.example .env
   ```

3. Set the local database connection in `.env`. Use the credentials for your development account. Keep real credentials and `APP_KEY` in your local `.env` and commit the example file only.

   ```dotenv
   APP_URL=http://127.0.0.1:8000
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=oms
   DB_USERNAME=oms
   DB_PASSWORD="<local-password>"
   ```

4. Generate the application key for a new `.env`, apply migrations, and start Laravel.

   ```sh
   php artisan key:generate
   php artisan migrate
   php artisan serve --host=127.0.0.1 --port=8000
   ```

## Verify the API

`GET /api/health` returns HTTP `200` with this JSON response.

```json
{"status":"ok"}
```

```sh
curl http://127.0.0.1:8000/api/health
php artisan migrate:status
```

The health endpoint checks the HTTP service. The migration status checks the database connection and shows the applied migrations. With the Vue dev server running, `http://127.0.0.1:5173/api/health` should return the same JSON through its `/api` proxy.

## Development checks

```sh
php vendor/bin/pint --test
php artisan test
```

The backend health feature test verifies the status code and the exact response body. API routes are registered in `bootstrap/app.php` and defined in `routes/api.php`. Schema changes live in `database/migrations`.
