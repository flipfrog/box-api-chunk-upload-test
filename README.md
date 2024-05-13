## Test program to chunk upload a file to Box.
### Settings and run program.
- run `composer install`
- copy `./config/box.php.sample` to `./config/box.php`
- make sure your app has a scope to `Write all files and folders stored in Box`.
    - If not, make the scope checked and save the app configuration.
- copy access token to clip board from the app's configuration screen.
- edit `./config/box.php` with copied access token.
- run `make` to generate a test file ./data/test.dat size of 21MB.
- run `php test.php` to copy `./data/test.dat` to Box root folder.

### I wrote a blog article about this repository.

https://unknownspace.hatenablog.com/entry/box-api-chunked-upload

