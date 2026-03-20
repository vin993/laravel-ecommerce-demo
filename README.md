# Laravel Ecommerce Demo

This repository contains a sanitized Laravel project that was prepared for public sharing. All client-specific data and proprietary branding have been removed.

## Overview

- Laravel-bagisto based ecommerce codebase
- Configured to use environment variables for secrets
- Cleaned of client names, domains, and credentials
- Includes only essential application code and commit history

## Setup

1. Clone repository:
   ```sh
   git clone https://github.com/vin993/laravel-ecommerce-demo.git
   cd laravel-ecommerce-demo
   ```
2. Install dependencies:
   ```sh
   composer install
   npm install
   ```
3. Set up environment:
   ```sh
   cp .env.example .env
   php artisan key:generate
   ```
4. Run migrations (if needed):
   ```sh
   php artisan migrate
   ```
5. Start application:
   ```sh
   php artisan serve
   ```

## Notes

- No real credentials are included.
- `.env.example` includes placeholder values.
- `.gitignore` is set to prevent files like `vendor`, `node_modules`, `.env`, and storage logs from being committed.

## License

This demo is provided as-is for portfolio purposes.
