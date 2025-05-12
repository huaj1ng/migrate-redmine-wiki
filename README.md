# Migrate Redmine Wiki Pages to MediaWiki

This is a command line tool that extracts wiki pages from a Redmine installation, providing data output that can be imported to a MediaWiki installation.

(Work in progress)

## Prerequisites
1. Connection to MySQL database of the Easy Redmine installation you would like to migrate. Alternatively, you should have its complete database dump and load the dump into a MySQL database server/container that you can connect to.
<!--TODO: specify data tables needed-->
2. A complete copy of the directory holding all attachment files of your Easy Redmine installation. Alternatively, you should have SSH connection to the server holding the directory (as well as tools like SCP or SSHFS installed, so that you can copy or map the directory).
3. PHP >= 8.1 with the `xml` extension must be installed
4. `pandoc` >= 3.1.6. The `pandoc` tool must be installed and available in the `PATH` (https://pandoc.org/installing.html).

## Installation
1. Download `migrate-redmine-wiki.phar` from https://github.com/hallowelt/migrate-redmine-wiki/releases/tag/latest
    - alternatively, you can clone the code of this script, and run the script in `bin/migrate-redmine-wiki`
2. Make sure the file is executable. E.g. by running `chmod +x migrate-redmine-wiki.phar`
3. Move `migrate-redmine-wiki.phar` to `/usr/local/bin/migrate-redmine-wiki` (or somewhere else in the `PATH`)

## Workflow

### Prepare migration
1. Create a workspace directory for the migration (e.g `/[path-to]/workspace`)
2. Create a files directory in your workspace (e.g `/[path-to]/Attachments`), to which you copy or map the whole attachment directory of your Easy Redmine installation
3. Create a `connection.json` file (`/[path-to]/connection.json`), containing access data to the MySQL database like below. (Please replace all `[]`-wrapped names (including those brackets) with your real-world data.)
```json
{
    "hostname": "[your_server_address]",
    "username": "[your_db_user]",
    "password": "[your_db_password]",
    "database": "[your_redmine_db_name]",
    "port": 3306,
    "socket": null
}
```
4. Optionally if you would like to remove or change title of certain pages, please create file `workspace/customizations.php` like:
```php
<?php

return array (
  'is-enabled' => true,
  'redmine-domain' => 'your.redmine-instance.com',
  'unwanted-projects' => '1, 2, 3',
  'customized-replace' => 
  array (
    'oldstring' => 'newstring',
  ),
  'title-cheatsheet' => 
  array (
    'Image12345678_1.png' => 'File:Image12345678_1.png',
  ),
  'pages-to-modify' => 
  array (
    'Formatted_page_title_of_unwanted_page' => false,
    'Formatted_page_title_to_alter' => 'Namespace:Altered_root_page/Altered_title',
  ),
  'categories-to-add' => 
  array (
    'Formatted_page_title' => 
    array(
      0 => 'Your category',
      1 => 'Another category',
    ),
  ),
);
```
### Generate migration data
Run the migration commands:
1. Run `migrate-redmine-wiki analyze --src connection.json --dest workspace` to analyze and fetched from the database, creating intermediate code files.
2. Run `migrate-redmine-wiki extract --src Attachments --dest workspace` to extract (copy) needed attachments.
3. Run `migrate-redmine-wiki convert --src workspace --dest workspace` to convert page content into Wikitext that works in MediaWiki, creating an intermediate code file.
4. Run `migrate-redmine-wiki compose --src workspace --dest workspace` to compose a XML file that can be imported to MediaWiki.
### Import into MediaWiki
1. Copy the directory `workspace/result` into your target wiki server, if you are not on that server. Assume that it is copied to `/tmp/result`
2. Go to your MediaWiki installation directory.
3. Make sure that your MediaWiki installation support the file extensions you are about to import: setup [$wgFileExtensions](https://www.mediawiki.org/wiki/Manual:$wgFileExtensions) properly. ~~See `workspace/result/images` for reference.~~
4. Use `php maintenance/importDump.php /tmp/result/0-output.xml` to import the actual pages
5. To allow all existing attachments, you will need to set the following lines in your `LocalSettings.php` before the next step. ***Note that these lines disable important security features of MediaWiki, which is especially dangerous for websites on the Internet. You should remove the lines immediately after the import.***
```php
$wgVerifyMimeType = false;
$$wgDisableUploadScriptChecks = true;
$wgStrictFileExtensions = false;
$wgProhibitedFileExtensions = [];
```
6. Use `php maintenance/importImages.php /tmp/result/images/` to first import all attachment files and images
7. Remove the settings bypassing security features, if you have added any
8. Refresh MediaWiki indexes:
```sh
php maintenance/rebuildrecentchanges.php
php maintenance/initSiteStats.php

```
For users of BlueSpice, please also consider the search index when refreshing:
```sh
php maintenance/rebuildrecentchanges.php
php maintenance/initSiteStats.php
php extensions/BlueSpiceExtendedSearch/maintenance/purgeIndexes.php --quick
php extensions/BlueSpiceExtendedSearch/maintenance/initBackends.php --quick
php extensions/BlueSpiceExtendedSearch/maintenance/rebuildIndex.php --quick
while  [ "$(php maintenance/showJobs.php)" != "0" ]; do php maintenance/runJobs.php --maxjobs 100; done
```

You may need to update your MediaWiki search index afterwards.

## troubleshooting
1. To run this migration scirpt, you can either use a seperate MySQL service (e.g within a docker container) that has imported a database dump of your Redmine, or  the actual database you use for your Redmine installation.
2. The database connection is eventually handled with php mysqli. Hence to test connections, you can run `php -r '$c=mysqli_connect("[your_server_address]","[your_db_user]","[your_db_password]","[your_redmine_db_name]",3306,null);if(!$c)die("Fail");$r=mysqli_query($c,"SHOW TABLES;");while($row=mysqli_fetch_array($r))echo $row[0]."\n";mysqli_close($c);'`. If the connection is correct, you should be able to see all tables in your redmine database. 
3. Check the port and socket if your connection fails, whether they are correct correct and accessible. A connection to a remote/containered/virtualized database servers should use `null` as the socket, and probably server ip address as hostname. 
4. If you have ssh connection to the server holding the attachment files, you can consider installing SSHFS and mapping the remote directory to the files directory you created, instead of copying the whole bulky directory. Typically the wiki part of a Redmine installation only involve a small part of the attachments, as most of them appear only in the ticket system. After installing SSHFS, please use the following command to map, and unmount with `fusermount -u /tmp/workspace/files` afterwards (unmount command on MacOS might differ).
```sh
sshfs [username]@[server_address]:/[server-path-to]/files /tmp/workspace/files
```
