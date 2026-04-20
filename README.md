# Laravel E-commerce Demo

A high-performance, scalable e-commerce platform built on Laravel and Bagisto, designed to handle enterprise-level operations with 600,000+ products and 20,000+ active users. This project demonstrates advanced integration capabilities, complex caching strategies, and robust API management for modern e-commerce solutions.

## Overview

This Laravel-based e-commerce application leverages the Bagisto framework to deliver a comprehensive online retail experience. The system is engineered for high scalability, featuring multi-supplier dropshipping, advanced product filtering, and seamless payment processing. It includes extensive API integrations with external services and implements sophisticated caching mechanisms to ensure optimal performance at scale.

## Key Features

### Product Management
- Dynamic catalog supporting 600,000+ SKUs across multiple suppliers
- Vehicle fitment mapping for motorcycle and ATV parts
- Automated XML/CSV data imports via FTP synchronization
- Product variants, grouping, and OEM parts integration
- Real-time inventory tracking and price updates

### Shopping Experience
- Advanced filtering by vehicle type, category, brand, and attributes
- Elasticsearch-powered full-text search and recommendations
- Cart management with supplier consolidation
- Wishlist and product review systems
- Responsive design with optimized frontend assets

### Order Processing
- Multi-gateway payment support (Stripe, PayPal)
- Automated invoice generation and email notifications
- ShipStation integration for shipping and tracking
- Order status management and customer communications
- Abandoned cart recovery system

### Business Operations
- Customer group-based pricing and discount rules
- Coupon management and promotional campaigns
- Tax calculation and free shipping configurations
- GDPR compliance tools and visitor analytics
- Content management system for pages and blogs

### API Integrations
- **Payment Processing:** Stripe (PCI-compliant), PayPal
- **Search & Analytics:** Elasticsearch with Kibana visualization
- **AI Features:** OpenAI for product descriptions and recommendations
- **Dropshipping:** Turn14, WPS, Helmet House, Parts Unlimited APIs
- **Shipping:** ShipStation webhooks and rate calculations
- **Data Feeds:** FTP/SFTP automated supplier synchronization

## Technology Stack

### Backend
- **PHP 8.2+** with Laravel 11.0 framework
- **Bagisto** e-commerce platform
- **Laravel Octane** for high-performance request handling
- **Laravel Sanctum** for API authentication

### Frontend
- **Vite** for modern asset bundling
- **Axios** for HTTP client requests
- **Laravel Blade** templating system

### Database & Caching
- **MySQL 8.0** primary database
- **Redis** distributed caching with Predis client
- **Elasticsearch 7.17** for search indexing
- **Laravel Response Cache** for full-page caching

### Infrastructure
- **Docker** containerization with Laravel Sail
- **Queue System** (Database/Redis/Beanstalkd)
- **Webhook Handlers** for payment and shipping updates

## Architecture Highlights

### Scalability Features
- Redis-backed caching reducing database load by 70-90%
- Vehicle fitment count caching for rapid product filtering
- Automated cache invalidation via model observers
- Batch processing for large data imports
- Cache warming scripts for deployment optimization

### Performance Optimizations
- Query caching for expensive operations
- Elasticsearch indexing for fast product searches
- Image proxy system for supplier assets
- Session management with Redis support
- Rate limiting and spam protection

### Data Pipeline
- FTP-based supplier data synchronization
- Micro-batch processing for incremental updates
- Automated sync windows (2-120 minutes)
- Dry-run validation for safe deployments
- Archive extraction and diff detection

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/vin993/laravel-ecommerce-demo.git
   cd laravel-ecommerce-demo
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Install Node.js dependencies:
   ```bash
   npm install
   ```

4. Set up environment configuration:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. Configure database and run migrations:
   ```bash
   php artisan migrate
   ```

6. Build frontend assets:
   ```bash
   npm run build
   ```

7. Start the application:
   ```bash
   php artisan serve
   ```

## Usage

### Development
- Use `php artisan serve` for local development
- Run `npm run dev` for frontend asset watching
- Execute `php artisan queue:work` for background job processing

### Production Deployment
- Utilize Docker Compose for containerized deployment
- Configure Redis and Elasticsearch clusters
- Set up automated cache warming scripts
- Monitor performance with Kibana dashboards

### Key Artisan Commands
- `php artisan product:import` - Import product data
- `php artisan cache:warm` - Warm application caches
- `php artisan elasticsearch:index` - Rebuild search indexes
- `php artisan supplier:sync` - Synchronize supplier data

## Configuration

The application requires extensive environment configuration for API integrations:

- Database connection settings
- Redis and Elasticsearch hosts
- Payment gateway API keys (Stripe, PayPal)
- Supplier API credentials (Turn14, WPS, etc.)
- OpenAI API configuration
- Email and notification settings

Refer to `.env.example` for all required variables.

## Author

Vinayak Chandran

## License

This project is provided as-is for portfolio and demonstration purposes.
