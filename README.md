# Migrate Redmine Wiki Pages to MediaWiki

This is a command line tool that extracts wiki pages from a Redmine installation, providing data output that can be imported to a MediaWiki installation.

(Work in progress)

## Prerequisites
1. Database connection to the database of the Redmine installation you would like to migrate. Alternatively, you should prepare its complete database dump. 
<!--TODO: specify data tables needed-->
2. A complete copy of the directory holding all attachment files of your Redmine installation. Alternatively, you should have SSH connection to the server holding the directory (as well as tools like SCP or SSHFS installed, so that you can copy of map the directory).
3. PHP >= 8.2 with the `xml` extension must be installed
<!--TODO: make list of all extensions needed-->
4. `pandoc` >= 3.1.6. The `pandoc` tool must be installed and available in the `PATH` (https://pandoc.org/installing.html).
5. For a migration fetching data directly from a running Redmine installation, it is recommended to set the installation as read only to prevent potential problems.

## Installation
tba
## Workflow
### Prepare
1. Create a workspace directory for the migration (e.g `/tmp/workspace`)
2. Create a files directory in your workspace (e.g `/tmp/workspace/files`), to which you copy or map the whole attachment directory of your Redmine installation
3. Create a `connection.json` file in your workspace (`/tmp/workspace/connection.json), containing access data to the MySQL database like:
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
#### troubleshooting
1. To run this migration scirpt, you can either use a seperate MySQL service (e.g within a docker container) that has imported a database dump of your Redmine, or  the actual database you use for your Redmine installation.
2. The database connection is eventually handled with php mysqli. Hence to test connections, you can run `php -r '$c=mysqli_connect("[your_server_address]","[your_db_user]","[your_db_password]","[your_redmine_db_name]",3306,null);if(!$c)die("Fail");$r=mysqli_query($c,"SHOW TABLES;");while($row=mysqli_fetch_array($r))echo $row[0]."\n";mysqli_close($c);'`. If the connection is correct, you should be able to see all tables in your redmine database. 
3. Check the port and socket if your connection fails, whether they are correct correct and accessible. A connection to a remote/containered/virtualized database servers should use `null` as the socket, and probably server ip address as hostname. 
4. If you have ssh connection to the server holding the attachment files, you can consider installing SSHFS and mapping the remote directory to the files directory you created, instead of copying the whole bulky directory. Typically the wiki part of a Redmine installation only involve a small part of the attachments, as most of them appear only in the ticket system. After installing SSHFS, please use the following command to map, and unmount with `fusermount -u /tmp/workspace/files` afterwards (unmount command on MacOS might differ).
```sh
sshfs [username]@[server_address]:/<server-path-to>/files /tmp/workspace/files
```

### Migrate
tba
### Import
tba
