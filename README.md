# CFI

![Version](https://img.shields.io/badge/VERSION-2.0.0-0366d6.svg?style=for-the-badge)
![Joomla!](https://img.shields.io/badge/Joomla!-4.3+-1A3867.svg?style=for-the-badge)
![Php](https://img.shields.io/badge/php-8.0+-8892BF.svg?style=for-the-badge)

_description in Russian [here](README.ru.md)_

# Execution time and article volumes while exporting or importing Joomla articles
The PHP script is set to a limit of `0`. Then the time limits are affected by the web server parameters:  
Nginx (`fastcgi_read_timeout`, `proxy_read_timeout`, `client_body_timeout`),  
Apache (`Timeout`, `ProxyTimeout`),  
PHP-FPM (`request_terminate_timeout`),  
which may cause the script to be stopped by the server.

Export is faster than import. Import speed on a weak server can range **from 3–4 to 10 articles per second**. Export volume on a weak server can reach **tens of thousands of articles**, including custom fields. In tests, **14,000 articles** without custom fields were exported in slightly under **1.5 minutes**.

The number of imported article properties has a big impact, including whether there are non-editable properties (`hits`, `modified`, etc.), as well as the number of custom fields. The more custom fields, the slower the import.

It is recommended to experimentally select the optimal amount of imported data and to import large numbers of articles in parts, since long and heavy processes are usually performed via the CLI.

# How does Joomla articles export work?
During export, you can export up to several tens of thousands of articles.

## Article filtering

The export respects Joomla administrator panel article filters (search parameters). You can export articles from one or several selected categories, only featured, only published, only with specific tags, and so on.

The `items per page` parameter affects the number of articles in a batch retrieved from the database at a time.

## Article properties for export

In the export window, you can select which article properties (`title`, `catid`, etc.) should be exported. All article properties are prefixed with `article`:

`id` → `articleid`

Article properties are used as table headers. The `id` article property is always exported. Additionally, tag IDs can be exported.

## Article custom fields

You can select which custom fields should be exported or not exported at all. The system names of the custom fields are used as table headers.

Article custom fields may belong to different categories. Make sure that all selected categories have the same set of fields.

If some custom fields belong to all categories and some only to one category, the export will merge them into one table.

If the export contains articles from different categories whose fields belong only to those categories, the export will **try** to merge them into one table. However, the correctness of the export in this case is not guaranteed.

## Example

You can select only the `id` property and the custom fields you need. The import will then correctly process such a table.

# How does Joomla articles import work?
With import, you can create new articles or update existing ones. The `articleid` column in the import table is used as the article identifier.

If the `articleid` column is empty or `0`, the import will create a new article. If it is set, the article with the specified `articleid` will be updated.

## Creating new Joomla articles via import

If no category is specified for new articles (the `catid` column is missing or empty), the import will create the article in the default category (the first category in the list). For a new article, the `articletitle` field (article title) is mandatory.

## Updating Joomla articles via import

For updating articles, the `articleid` field is mandatory. In the other columns, you can specify any article properties that you can see in the article creation form, including non-editable ones (`hits` – view count, `modified` – last modification date, etc.).

You can also use import to update **only** article custom fields. To do this, the import table must contain the `articleid` column, and the other column headers must match the system names of the custom fields.

The data format must also match what these fields expect: in some cases simple strings, and in others arrays or `json`.

---

# File Format Description

Data is exported to and imported from a CSV file with a mandatory `;` delimiter.

The default file encoding, if the “Convert encoding…” option is not enabled, is UTF-8 without BOM. Automatic conversion from the encoding specified in the plugin’s single setting is supported.

The first row of the file always contains the field headers.

The `articleid` field is mandatory. If it is missing, data import from the file will not be performed.

Any other field names are treated as names of article custom fields. If the specified custom fields do not exist for a given article, they will be ignored.

If the number of values in a row does not match the number of field headers, that row will be skipped. During import, article custom fields that are not present in the file are not affected.

Import error data is stored in the *cfi.php* log file in Joomla!’s standard log directory.

If no import errors occur, the imported file is deleted. Otherwise, the file is preserved in Joomla!’s standard temporary files directory.

---

## Data Format

During export, data is written to the file as-is, in the same format in which it is stored in your site’s database: plain text, HTML-formatted text, JSON structures, and other complex string structures.

For standard Joomla! configurable list-type fields that return stored data structures as non-associative arrays, the resulting file will contain JSON. For non-standard fields, the structure `array::` is written before the JSON value. This is required so that during a possible subsequent import, the plugin can parse the JSON value from the file and assign a prepared array to the corresponding field.

If you did not understand the explanation above — that’s fine. Just do not modify the `array::` value in your file, or remove that column entirely to avoid corrupting the data of the corresponding article field.

---

## Data Protection During Import

During import, data is validated by Joomla and can also be processed by third-party plugins of the `cfi` group.

**The plugin developer is not responsible for incorrect content in imported files that may break your website.**

---
