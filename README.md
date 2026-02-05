# Тестовое задание PHP (Laravel): IMAP-прайсинг + обновление наличия/цен + отчёт

Laravel Framework 11.48.0

PHP 8.5.1 (cli)

MYSQL-8.4

для запуска приложения локально необходимо:

скачать репозиторий в локальную папку

загрузить библиотеки - composer install

запустить локальный сервер (например OPEN SERVER)

включить MYSQL (8.4), через PHP_MY_ADMIN создать базу данных ( test )

создать и настроить файл .env по шаблону .env.example

настройки базы данных, почты, IMAP и почтовых ящиков

произвести миграции таблиц командой php artisan migrate

запуск функционала осуществляется командой:

# php artisan prices:sync-polcar

Логика приложения в файле app\Console\Commands\SyncPolcarPrices.php




















