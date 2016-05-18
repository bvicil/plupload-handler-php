<?php
require_once("PluploadHandler.php");
require_once("PluploadFTP.php");

$settings = array(
    'target_dir' => 'path/to/upload', // FTP directory path from FTP root
    'rel_dir' => 'path/to/upload', // relative directory path for php
    'allow_extensions' => 'txt,sql', // allowed extensions
    'ftphost' => 'ftp.domain.tld', // ftp host address
    'ftpuser' => 'ftpuser', // ftp user
    'ftppass' => 'ftppass' // ftp pass
);

PluploadFTP::no_cache_headers();
PluploadFTP::cors_headers();
if (!PluploadFTP::handleFTP($settings)) {
    die(json_encode(array(
        'OK' => 0,
        'error' => array(
            'code' => PluploadHandler::get_error_code(),
            'message' => PluploadHandler::get_error_message()
        )
    )));
} else {
    die(json_encode(array('OK' => 1)));
}
