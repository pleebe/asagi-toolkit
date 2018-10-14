# 10 billion thread dump migration tool

With this tool you can import most data from this dump https://archive.org/details/4chan_threads_archive_10_billion

XML files are used and sadly not all boards have them. Also it's text only.

This tool does not create database or tables for you. You should already have a functional asagi tables before using this tool.

## Usage

Showing import procedure for /trv/ data into database "archive".

```
cp config.json.example config.json
vi config.json # fill your database details
composer install -o
php import.php --source path/to/downloaded/xml/files/ --destination archive --board trv
```
