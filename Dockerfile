# Use official PHP 8.2 image with Apache
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    cron \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 666 /var/www/html/movies.csv \
    && chmod 666 /var/www/html/users.json \
    && chmod 666 /var/www/html/bot_stats.json \
    && mkdir -p /var/www/html/backups \
    && chmod 777 /var/www/html/backups

# Configure cron for daily digest and backup
RUN echo "0 8 * * * php /var/www/html/bot.php daily_digest" | crontab - \
    && echo "0 0 * * * php /var/www/html/bot.php auto_backup" | crontab -

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
