FROM php:8.1-apache

# Update Apache to listen on Render's dynamic $PORT
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite

# Install common PHP extensions for file handling/databases
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . /var/www/html/

# Ensure 'uploads' folder exists and is writable by the web server
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html/uploads && \
    chmod -R 755 /var/www/html/uploads

ENV PORT 10000
EXPOSE 10000

CMD ["apache2-foreground"]