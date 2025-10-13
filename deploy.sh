#!/bin/bash

LOG_FILE="/var/www/app.cavaleria.ru/storage/logs/deploy.log"
echo "=== Deployment started at $(date) ===" >> $LOG_FILE

cd /var/www/app.cavaleria.ru

# Временно меняем владельца для git
sudo chown -R $USER:$USER .

# Git pull
echo "Pulling latest changes..." >> $LOG_FILE
git pull origin main >> $LOG_FILE 2>&1

# Возвращаем владельца nginx
sudo chown -R nginx:nginx .
sudo chmod -R 775 storage bootstrap/cache

# Composer
echo "Installing dependencies..." >> $LOG_FILE
composer install --optimize-autoloader --no-dev >> $LOG_FILE 2>&1

# NPM
echo "Building frontend..." >> $LOG_FILE
npm ci --production=false >> $LOG_FILE 2>&1
npm run build >> $LOG_FILE 2>&1

# Laravel
echo "Running migrations..." >> $LOG_FILE
php artisan migrate --force >> $LOG_FILE 2>&1
php artisan config:cache >> $LOG_FILE 2>&1
php artisan route:cache >> $LOG_FILE 2>&1
php artisan view:cache >> $LOG_FILE 2>&1
php artisan queue:restart >> $LOG_FILE 2>&1

# Restart services
echo "Restarting services..." >> $LOG_FILE
sudo systemctl restart php-fpm >> $LOG_FILE 2>&1

echo "=== Deployment completed at $(date) ===" >> $LOG_FILE