php artisan migrate --force
php artisan storage:link
php artisan db:seed --class=ArticleSeeder --force
php artisan job-offers:generate-images

php artisan companies:generate-logos [--force]

